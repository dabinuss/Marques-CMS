<?php
declare(strict_types=1);

namespace Marques\Core;

if (!function_exists('opcache_invalidate')) {
    function opcache_invalidate($file, $force = false) {
        // Dummy-Funktion: Nichts tun, da OPcache nicht verfügbar ist.
        return true;
    }
}

class AppCache {
    protected string $cacheDir;
    protected bool $useOpcache;
    protected bool $enabled;
    protected array $memoryCache = [];
    protected static ?AppCache $instance = null;
    
    // Index-Unterstützung
    protected bool $useIndex;
    protected array $index = [];
    protected string $indexFile;
    // Batch-Modus für Index-Operationen
    protected bool $batchMode = false;
    protected array $batchIndexUpdates = [];
    
    // MD5-Cache für Schlüssel
    protected array $md5Cache = [];
    
    // Cache-Statistiken
    protected int $totalRequests = 0;
    protected int $cacheHits = 0;
    protected float $totalAccessTime = 0.0;
    
    // Konfigurierbare TTL-Standardwerte
    protected array $defaultTtlMapping = [
        'template_' => 3600,
        'asset_'    => 86400,
        'default'   => 3600,
    ];
    
    /**
     * Liefert die Singleton-Instanz des AppCaches.
     * Hinweis: Parameter werden nur beim ersten Aufruf berücksichtigt.
     *
     * @return AppCache
     */
    public static function getInstance(): AppCache {
        if (self::$instance === null) {
            $settingsManager = new AppSettings();
            $system_settings = $settingsManager->getAllSettings();
            $enabled = $system_settings['cache_enabled'] ?? true;
            self::$instance = new self(null, $enabled, true);
        }
        return self::$instance;
    }
    
    /**
     * Konstruktor.
     *
     * @param string|null $cacheDir Pfad zum Cache-Verzeichnis
     * @param bool $enabled Ob Caching aktiviert ist
     * @param bool $useIndex Ob der Index verwendet werden soll
     */
    public function __construct(?string $cacheDir = null, bool $enabled = true, bool $useIndex = true) {
        if ($cacheDir === null) {
            $cacheDir = defined('MARQUES_CACHE_DIR') ? MARQUES_CACHE_DIR : __DIR__ . '/cache';
        }
        $this->cacheDir = rtrim($cacheDir, DIRECTORY_SEPARATOR);
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException("Cache-Verzeichnis konnte nicht erstellt werden.", 500);
        }
        $this->useOpcache = function_exists('opcache_get_status');
        $this->enabled = $enabled;
        $this->useIndex = $useIndex;
        $this->indexFile = $this->cacheDir . '/cache_index.json';
        if ($this->useIndex) {
            $this->loadIndex();
        }
    }
    
    /* Batch-Operationen für den Index */
    
    /**
     * Beginnt einen Batch-Modus, sodass mehrere Index-Änderungen gesammelt werden.
     */
    public function beginIndexBatch(): void {
        $this->batchMode = true;
        $this->batchIndexUpdates = [];
    }
    
    /**
     * Führt alle im Batch gesammelten Index-Änderungen durch und speichert den Index.
     */
    public function commitIndexBatch(): void {
        foreach ($this->batchIndexUpdates as $group => $keys) {
            if (!isset($this->index[$group])) {
                $this->index[$group] = [];
            }
            $this->index[$group] = array_unique(array_merge($this->index[$group], $keys));
        }
        $this->batchIndexUpdates = [];
        $this->batchMode = false;
        $this->saveIndex();
    }
    
    /* Index-Operationen */
    
    /**
     * Lädt den Index aus der Indexdatei.
     */
    protected function loadIndex(): void {
        if (file_exists($this->indexFile) && is_readable($this->indexFile)) {
            $content = file_get_contents($this->indexFile);
            $data = json_decode($content, true);
            $this->index = is_array($data) ? $data : [];
        } else {
            $this->index = [];
        }
    }
    
    /**
     * Speichert den Index in der Indexdatei.
     * JSON_PRETTY_PRINT wird nur im Debug-Modus verwendet.
     */
    protected function saveIndex(): void {
        if ($this->useIndex) {
            $flags = 0;
            if (defined('DEBUG') && DEBUG === true) {
                $flags = JSON_PRETTY_PRINT;
            }
            file_put_contents($this->indexFile, json_encode($this->index, $flags));
        }
    }
    
    /**
     * Aktualisiert den Index für einen bestimmten Schlüssel und die zugehörigen Gruppen.
     *
     * @param string $key
     * @param array $groups
     */
    protected function updateIndexForKey(string $key, array $groups): void {
        if ($this->batchMode) {
            foreach ($groups as $group) {
                if (!isset($this->batchIndexUpdates[$group])) {
                    $this->batchIndexUpdates[$group] = [];
                }
                if (!in_array($key, $this->batchIndexUpdates[$group], true)) {
                    $this->batchIndexUpdates[$group][] = $key;
                }
            }
        } else {
            foreach ($groups as $group) {
                if (!isset($this->index[$group])) {
                    $this->index[$group] = [];
                }
                if (!in_array($key, $this->index[$group], true)) {
                    $this->index[$group][] = $key;
                }
            }
            $this->saveIndex();
        }
    }
    
    /**
     * Entfernt einen Schlüssel aus dem Index.
     *
     * @param string $key
     */
    protected function removeKeyFromIndex(string $key): void {
        foreach ($this->index as $group => $keys) {
            if (($pos = array_search($key, $keys, true)) !== false) {
                unset($this->index[$group][$pos]);
                $this->index[$group] = array_values($this->index[$group]);
            }
        }
        $this->saveIndex();
    }
    
    /**
     * Hilfsmethode zur Ermittlung eindeutiger Schlüssel aus dem Index.
     *
     * @return array
     */
    protected function getUniqueIndexKeys(): array {
        $keys = [];
        foreach ($this->index as $groupKeys) {
            $keys = array_merge($keys, $groupKeys);
        }
        return array_unique($keys);
    }
    
    /* MD5-Cache */
    
    /**
     * Berechnet den Dateinamen für einen Cache-Schlüssel, unter Nutzung eines internen MD5-Caches.
     *
     * @param string $key
     * @return string
     */
    protected function getCacheFilePath(string $key): string {
        if (isset($this->md5Cache[$key])) {
            return $this->cacheDir . '/' . $this->md5Cache[$key] . '.cache';
        }
        $hash = md5($key);
        $this->md5Cache[$key] = $hash;
        return $this->cacheDir . '/' . $hash . '.cache';
    }
    
    /**
     * Validiert den Cache-Schlüssel.
     *
     * @param string $key
     * @return string
     * @throws \InvalidArgumentException
     */
    protected function validateKey(string $key): string {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $key)) {
            throw new \InvalidArgumentException("Ungültiger Cache-Schlüssel: $key");
        }
        return $key;
    }
    
    /* Cache-Statistiken */
    
    /**
     * Liefert aktuelle Cache-Statistiken (Trefferquote, durchschnittliche Zugriffszeit, etc.).
     *
     * @return array
     */
    public function getStatistics(): array {
        $hitRate = $this->totalRequests > 0 ? $this->cacheHits / $this->totalRequests : 0;
        $avgAccessTime = $this->totalRequests > 0 ? $this->totalAccessTime / $this->totalRequests : 0;
        return [
            'total_requests' => $this->totalRequests,
            'cache_hits'     => $this->cacheHits,
            'hit_rate'       => $hitRate,
            'avg_access_time'=> $avgAccessTime,
        ];
    }
    
    /* Konfigurierbare TTL-Standardwerte */
    
    /**
     * Überschreibt die Standard-TTL-Werte.
     *
     * @param array $mapping
     */
    public function setDefaultTtlMapping(array $mapping): void {
        $this->defaultTtlMapping = $mapping;
    }
    
    /* Öffentliche API */
    
    /**
     * Liefert den gecachten Inhalt oder null, falls er nicht existiert oder abgelaufen ist.
     *
     * @param string $key
     * @return string|null
     */
    public function get(string $key): ?string {
        $this->validateKey($key);
        $startTime = microtime(true);
        $result = null;
        
        if (!$this->enabled) {
            $result = null;
        } elseif (isset($this->memoryCache[$key])) {
            $result = $this->memoryCache[$key];
            $this->cacheHits++;
        } else {
            $file = $this->getCacheFilePath($key);
            if (file_exists($file) && is_readable($file)) {
                $content = file_get_contents($file);
                if ($content !== false) {
                    try {
                        $data = unserialize($content);
                    } catch (\Exception $e) {
                        $this->delete($key);
                        $data = null;
                    }
                    if (is_array($data) && isset($data['expire'], $data['content'])) {
                        if (time() <= $data['expire']) {
                            $result = $data['content'];
                            $this->memoryCache[$key] = $result;
                            $this->cacheHits++;
                        } else {
                            $this->delete($key);
                        }
                    }
                }
            }
        }
        $this->totalRequests++;
        $this->totalAccessTime += microtime(true) - $startTime;
        return $result;
    }
    
    /**
     * Speichert den Inhalt im Cache.
     *
     * @param string $key
     * @param string $content
     * @param int|null $ttl Time-to-live in Sekunden (falls null, werden Standardwerte genutzt)
     * @param array $groups Gruppen, denen der Cacheeintrag zugeordnet wird
     */
    public function set(string $key, string $content, ?int $ttl = null, array $groups = []): void {
        $this->validateKey($key);
        if (!$this->enabled) {
            return;
        }
        // Konfigurierbare TTL-Standardwerte nutzen
        if ($ttl === null) {
            foreach ($this->defaultTtlMapping as $prefix => $defaultTtl) {
                if ($prefix !== 'default' && strpos($key, $prefix) === 0) {
                    $ttl = $defaultTtl;
                    if ($prefix === 'template_') {
                        $groups[] = 'templates';
                    } elseif ($prefix === 'asset_') {
                        $groups[] = 'assets';
                    }
                    break;
                }
            }
            if ($ttl === null) {
                $ttl = $this->defaultTtlMapping['default'] ?? 3600;
            }
        }
        
        $file = $this->getCacheFilePath($key);
        $data = [
            'expire'  => time() + $ttl,
            'content' => $content,
            'groups'  => $groups,
        ];
        $serialized = serialize($data);
        $fp = fopen($file, 'c');
        if ($fp === false) {
            throw new \RuntimeException("Cache-Datei konnte nicht geöffnet werden.", 500);
        }
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, $serialized);
            fflush($fp);
            flock($fp, LOCK_UN);
        } else {
            fclose($fp);
            throw new \RuntimeException("Cache-Datei konnte nicht gesperrt werden.", 500);
        }
        fclose($fp);
        $this->memoryCache[$key] = $content;
        if ($this->useOpcache && function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }        
        if ($this->useIndex && !empty($groups)) {
            $this->updateIndexForKey($key, $groups);
        }
    }
    
    /**
     * Löscht einen Cache-Eintrag.
     *
     * @param string $key
     */
    public function delete(string $key): void {
        $this->validateKey($key);
        $file = $this->getCacheFilePath($key);
        if (file_exists($file)) {
            unlink($file);
        }
        unset($this->memoryCache[$key]);
        if ($this->useIndex) {
            $this->removeKeyFromIndex($key);
        }
    }
    
    /**
     * Löscht alle Cache-Einträge.
     */
    public function clear(): void {
        $files = glob($this->cacheDir . '/*.cache');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        $this->memoryCache = [];
        if ($this->useIndex) {
            $this->index = [];
            if (file_exists($this->indexFile)) {
                unlink($this->indexFile);
            }
        }
    }
    
    /**
     * Löscht alle Cache-Einträge, die zu einer bestimmten Gruppe gehören.
     *
     * @param string $group
     */
    public function clearGroup(string $group): void {
        if ($this->useIndex) {
            if (isset($this->index[$group]) && is_array($this->index[$group])) {
                foreach ($this->index[$group] as $key) {
                    $this->delete($key);
                }
                unset($this->index[$group]);
                $this->saveIndex();
            }
        } else {
            // Ineffizienter Fallback: Durchsuche alle Cache-Dateien
            $files = glob($this->cacheDir . '/*.cache');
            if ($files !== false) {
                foreach ($files as $file) {
                    $content = file_get_contents($file);
                    if ($content === false) continue;
                    $data = @unserialize($content);
                    if (is_array($data) && isset($data['groups']) && in_array($group, $data['groups'], true)) {
                        unlink($file);
                    }
                }
            }
        }
    }
    
    /**
     * Generiert eine cache-busted URL für statische Assets.
     *
     * @param string $url
     * @return string
     */
    public function bustUrl(string $url): string {
        $parts = parse_url($url);
        $path = $parts['path'] ?? $url;
        if (!file_exists($path) && isset($_SERVER['DOCUMENT_ROOT'])) {
            $path = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        }
        if (file_exists($path)) {
            $mtime = filemtime($path);
            $sep = strpos($url, '?') === false ? '?' : '&';
            return $url . $sep . 'v=' . $mtime;
        }
        return $url;
    }
    
    /**
     * Gibt die Anzahl der Cache-Dateien zurück.
     *
     * @return int
     */
    public function getCacheFileCount(): int {
        if ($this->useIndex) {
            $uniqueKeys = $this->getUniqueIndexKeys();
            return count($uniqueKeys);
        } else {
            $files = glob($this->cacheDir . '/*.cache');
            return $files === false ? 0 : count($files);
        }
    }
    
    /**
     * Gibt die Gesamtgröße aller Cache-Dateien zurück.
     *
     * @return int
     */
    public function getCacheSize(): int {
        $size = 0;
        if ($this->useIndex) {
            $uniqueKeys = $this->getUniqueIndexKeys();
            foreach ($uniqueKeys as $key) {
                $file = $this->getCacheFilePath($key);
                if (file_exists($file)) {
                    $size += filesize($file);
                }
            }
        } else {
            $files = glob($this->cacheDir . '/*.cache');
            if ($files !== false) {
                foreach ($files as $file) {
                    $size += filesize($file);
                }
            }
        }
        return $size;
    }
}

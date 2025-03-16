<?php
declare(strict_types=1);

namespace Marques\Core;

class CacheManager {
    protected string $cacheDir;
    protected bool $useOpcache;
    protected bool $enabled;
    protected array $memoryCache = [];
    protected static ?CacheManager $instance = null;

    // Neue Properties für Index-Unterstützung
    protected bool $useIndex;
    protected array $index = [];
    protected string $indexFile;

    /**
     * Liefert die Singleton-Instanz des CacheManagers.
     *
     * @param string|null $cacheDir
     * @param bool|null $enabled
     * @param bool $useIndex Ob der Index genutzt werden soll (Standard: true)
     * @return CacheManager
     */
    public static function getInstance(?string $cacheDir = null, ?bool $enabled = null, bool $useIndex = true): CacheManager {
        if (self::$instance === null) {
            if ($enabled === null) {
                $settingsManager = new SettingsManager();
                $system_settings = $settingsManager->getAllSettings();
                $enabled = $system_settings['cache_enabled'] ?? true;
            }
            self::$instance = new self($cacheDir, $enabled, $useIndex);
        }
        return self::$instance;
    }

    /**
     * Konstruktor.
     *
     * @param string|null $cacheDir Pfad zum Cache-Verzeichnis
     * @param bool $enabled Ob Caching aktiviert ist
     * @param bool $useIndex Ob der Index verwendet werden soll
     * @throws ConfigurationException Falls das Cache-Verzeichnis nicht erstellt werden kann.
     */
    public function __construct(?string $cacheDir = null, bool $enabled = true, bool $useIndex = true) {
        if ($cacheDir === null) {
            $cacheDir = defined('MARQUES_CACHE_DIR') ? MARQUES_CACHE_DIR : __DIR__ . '/cache';
        }
        $this->cacheDir = rtrim($cacheDir, DIRECTORY_SEPARATOR);
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            throw new ConfigurationException("Cache-Verzeichnis konnte nicht erstellt werden.", 500);
        }
        $this->useOpcache = function_exists('opcache_get_status');
        $this->enabled = $enabled;
        $this->useIndex = $useIndex;
        $this->indexFile = $this->cacheDir . '/cache_index.json';
        if ($this->useIndex) {
            $this->loadIndex();
        }
    }

    /**
     * Lädt den Index aus der Indexdatei.
     */
    protected function loadIndex(): void {
        if (file_exists($this->indexFile) && is_readable($this->indexFile)) {
            $content = file_get_contents($this->indexFile);
            $data = json_decode($content, true);
            if (is_array($data)) {
                $this->index = $data;
            } else {
                $this->index = [];
            }
        } else {
            $this->index = [];
        }
    }

    /**
     * Speichert den Index in der Indexdatei.
     */
    protected function saveIndex(): void {
        if ($this->useIndex) {
            file_put_contents($this->indexFile, json_encode($this->index, JSON_PRETTY_PRINT));
        }
    }

    /**
     * Aktualisiert den Index für einen bestimmten Schlüssel und die zugehörigen Gruppen.
     *
     * @param string $key
     * @param array $groups
     */
    protected function updateIndexForKey(string $key, array $groups): void {
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
     * Erzeugt einen Dateinamen für einen Cache-Schlüssel.
     *
     * @param string $key
     * @return string
     */
    protected function getCacheFilePath(string $key): string {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }

    /**
     * Liefert den gecachten Inhalt oder null, falls er nicht existiert oder abgelaufen ist.
     *
     * @param string $key
     * @return string|null
     */
    public function get(string $key): ?string {
        if (!$this->enabled) {
            return null;
        }
        if (isset($this->memoryCache[$key])) {
            return $this->memoryCache[$key];
        }
        $file = $this->getCacheFilePath($key);
        if (!file_exists($file) || !is_readable($file)) {
            return null;
        }
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }
        $data = unserialize($content);
        if (!is_array($data) || !isset($data['expire'], $data['content'])) {
            return null;
        }
        if (time() > $data['expire']) {
            $this->delete($key);
            return null;
        }
        $this->memoryCache[$key] = $data['content'];
        return $data['content'];
    }

    /**
     * Speichert den Inhalt im Cache.
     *
     * Automatische Anpassung der TTL und Gruppen basierend auf dem Schlüssel.
     *
     * @param string $key Schlüssel für den Cacheeintrag.
     * @param string $content Inhalt, der gecached werden soll.
     * @param int|null $ttl Time-to-live in Sekunden.
     * @param array $groups Gruppen, denen der Cacheeintrag zugeordnet wird.
     * @throws ConfigurationException
     */
    public function set(string $key, string $content, ?int $ttl = null, array $groups = []): void {
        if (!$this->enabled) {
            return;
        }
        if ($ttl === null) {
            if (strpos($key, 'template_') === 0) {
                $ttl = 3600;
                $groups[] = 'templates';
            } elseif (strpos($key, 'asset_') === 0) {
                $ttl = 86400;
                $groups[] = 'assets';
            } else {
                $ttl = 3600;
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
            throw new ConfigurationException("Cache-Datei konnte nicht geöffnet werden.", 500, null, ['file' => $file]);
        }
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, $serialized);
            fflush($fp);
            flock($fp, LOCK_UN);
        } else {
            fclose($fp);
            throw new ConfigurationException("Cache-Datei konnte nicht gesperrt werden.", 500, null, ['file' => $file]);
        }
        fclose($fp);
        $this->memoryCache[$key] = $content;
        if ($this->useOpcache) {
            opcache_invalidate($file, true);
        }
        // Index aktualisieren
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
            $files = glob($this->cacheDir . '/*.cache');
            if ($files !== false) {
                foreach ($files as $file) {
                    $data = unserialize(file_get_contents($file));
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
            $keys = [];
            foreach ($this->index as $groupKeys) {
                $keys = array_merge($keys, $groupKeys);
            }
            $uniqueKeys = array_unique($keys);
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
            $keys = [];
            foreach ($this->index as $groupKeys) {
                $keys = array_merge($keys, $groupKeys);
            }
            $uniqueKeys = array_unique($keys);
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

<?php
declare(strict_types=1);

namespace Marques\Core;

class CacheManager {
    protected string $cacheDir;
    protected bool $useOpcache;
    protected bool $enabled;
    protected array $memoryCache = [];
    protected static ?CacheManager $instance = null;

    /**
     * Liefert die Singleton-Instanz des CacheManagers.
     * Falls $enabled nicht explizit übergeben wird, wird der Wert aus den Systemeinstellungen geladen.
     *
     * @param string|null $cacheDir
     * @param bool|null $enabled
     * @return CacheManager
     */
    public static function getInstance(?string $cacheDir = null, ?bool $enabled = null): CacheManager {
        if (self::$instance === null) {
            if ($enabled === null) {
                // Lade die Systemeinstellungen über den SettingsManager
                $settingsManager = new SettingsManager();
                $system_settings = $settingsManager->getAllSettings();
                $enabled = $system_settings['cache_enabled'] ?? true;
            }
            self::$instance = new self($cacheDir, $enabled);
        }
        return self::$instance;
    }

    /**
     * Konstruktor.
     *
     * @param string|null $cacheDir Pfad zum Cache-Verzeichnis
     * @param bool $enabled Ob Caching aktiviert ist
     * @throws ConfigurationException Falls das Cache-Verzeichnis nicht erstellt werden kann.
     */
    public function __construct(?string $cacheDir = null, bool $enabled = true) {
        if ($cacheDir === null) {
            $cacheDir = defined('MARQUES_CACHE_DIR') ? MARQUES_CACHE_DIR : __DIR__ . '/cache';
        }
        $this->cacheDir = rtrim($cacheDir, DIRECTORY_SEPARATOR);
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            throw new ConfigurationException("Cache-Verzeichnis konnte nicht erstellt werden.", 500);
        }
        $this->useOpcache = function_exists('opcache_get_status');
        $this->enabled = $enabled;
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
     * Automatische Anpassung: Falls keine TTL übergeben wurde, wird basierend auf dem Schlüssel ein Standardwert verwendet:
     * - Schlüssel beginnend mit "template_": TTL 3600 Sekunden, Gruppe "templates"
     * - Schlüssel beginnend mit "asset_": TTL 86400 Sekunden, Gruppe "assets"
     * - Ansonsten: TTL 3600 Sekunden
     *
     * @param string $key Schlüssel für den Cacheeintrag.
     * @param string $content Inhalt, der gecached werden soll.
     * @param int|null $ttl Time-to-live in Sekunden.
     * @param array $groups Gruppen, denen der Cacheeintrag zugeordnet wird.
     * @throws ConfigurationException Wenn die Cache-Datei nicht geöffnet oder gesperrt werden kann.
     */
    public function set(string $key, string $content, ?int $ttl = null, array $groups = []): void {
        if (!$this->enabled) {
            return;
        }
        // Automatische Anpassung der TTL und Gruppen basierend auf dem Schlüssel
        if ($ttl === null) {
            if (strpos($key, 'template_') === 0) {
                $ttl = 3600; // 1 Stunde für Templates
                $groups[] = 'templates';
            } elseif (strpos($key, 'asset_') === 0) {
                $ttl = 86400; // 24 Stunden für Assets
                $groups[] = 'assets';
            } else {
                $ttl = 3600; // Standard-TTL
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
    }

    /**
     * Löscht alle Cache-Einträge, die zu einer bestimmten Gruppe gehören.
     *
     * @param string $group
     */
    public function clearGroup(string $group): void {
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

    /**
     * Generiert eine Cache-busted URL für statische Assets.
     * Hängt einen Query-Parameter "v" mit der Dateimtime an, um sicherzustellen, dass immer die aktuellste Version geladen wird.
     *
     * @param string $url Pfad oder URL zur Datei (relativ oder absolut)
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
}

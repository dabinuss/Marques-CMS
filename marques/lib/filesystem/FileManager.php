<?php
declare(strict_types=1);

namespace Marques\Filesystem;

use InvalidArgumentException;
use RuntimeException;
use Marques\Core\Cache;
use Marques\Filesystem\PathResolver;
use Marques\Filesystem\PathRegistry;

/**
 * File‑ & Directory‑Utility für Marques CMS.
 *
 * @phpstan-type DirectoryMap array<string,string>
 */
class FileManager
{
    /**
     * Standard-Verzeichnisschlüssel als Konstanten für Typsicherheit
     */
    public const DIR_CONTENT = 'content';
    public const DIR_THEMES = 'themes';  
    public const DIR_ADMIN = 'admin';
    public const DIR_BACKEND_TEMPLATES = 'backend_templates';
    
    /**
     * Maximale Anzahl der Einträge im Path-Cache
     */
    private const MAX_PATH_CACHE_SIZE = 500;

    protected string $baseDir;
    protected readonly Cache $cache;

    /** @var DirectoryMap */
    protected array $knownDirectories = [];
    
    /** @var array<string,string> Internes Cache für Pfadauflösungen */
    private array $pathCache = [];
    
    /** @var int Zählt Operationen für regelmäßige Cache-Bereinigung */
    private int $operationCounter = 0;

    /**
     * @param Cache $cache
     * @param PathRegistry $paths
     * @param string|array<string,string>|null $baseDir
     */
    public function __construct(
        Cache $cache,
        PathRegistry|string|array|null $pathsOrBaseDir = null,
        string|array|null              $maybeBaseDir  = null
    ) {
        $this->cache = $cache;
    
        // 1) Pfad‑Registry ermitteln
        if ($pathsOrBaseDir instanceof PathRegistry) {
            $paths   = $pathsOrBaseDir;
            $baseDir = $maybeBaseDir;
        } else {
            // Legacy‑Modus: kein Registry‑Objekt übergeben
            $paths   = new PathRegistry();
            $baseDir = $pathsOrBaseDir;
        }
    
        // 2) Standard‑Mappings
        $this->knownDirectories = [
            self::DIR_CONTENT           => $paths->getPath('content'),
            self::DIR_THEMES            => $paths->getPath('themes'),
            self::DIR_ADMIN             => $paths->getPath('admin'),
            self::DIR_BACKEND_TEMPLATES => $paths->getPath('admin_template'),
        ];
    
        // 3) Basis‑Verzeichnis setzen
        if (is_array($baseDir)) {
            $this->knownDirectories = [...$this->knownDirectories, ...$baseDir];
            $this->baseDir          = rtrim(
                $this->knownDirectories[self::DIR_CONTENT],
                DIRECTORY_SEPARATOR
            );
        } elseif (is_string($baseDir)) {
            $this->baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);
        } else {
            $this->baseDir = $paths->getPath('content');
        }
    }

    /** @return self */
    public function setBaseDir(string $newBaseDir): self
    {
        $this->baseDir = \rtrim($newBaseDir, DIRECTORY_SEPARATOR);
        $this->resetPathCache(); // Path-Cache komplett leeren
        return $this;
    }

    /**
     * @throws InvalidArgumentException
     * @return self
     */
    public function useDirectory(string $directoryKey, ?string $pathIfNotExists = null): self
    {
        if (!isset($this->knownDirectories[$directoryKey])) {
            if ($pathIfNotExists === null) {
                throw new InvalidArgumentException("Unbekanntes Verzeichnis: {$directoryKey}");
            }

            $this->addDirectory($directoryKey, $pathIfNotExists);
        }

        $this->baseDir = \rtrim($this->knownDirectories[$directoryKey], DIRECTORY_SEPARATOR);
        $this->resetPathCache(); // Path-Cache komplett leeren
        
        return $this;
    }

    /** @return self */
    public function addDirectory(string $key, string $path): self
    {
        $this->knownDirectories[$key] = \rtrim($path, DIRECTORY_SEPARATOR);
        $this->resetPathCache(); // Path-Cache leeren, da sich das Mapping geändert hat
        return $this;
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    /** @return DirectoryMap */
    public function getKnownDirectories(): array
    {
        return $this->knownDirectories;
    }

    public function existsInDirectory(string $directoryKey, string $relativePath): bool
    {
        return $this->inDirectory(
            $directoryKey,
            fn (): bool => $this->exists($relativePath)
        );
    }

    public function readFromDirectory(string $directoryKey, string $relativePath): ?string
    {
        return $this->inDirectory(
            $directoryKey,
            fn (): ?string => $this->readFile($relativePath)
        );
    }

    /** 
     * @throws RuntimeException 
     */
    public function writeFile(string $relativePath, string $content): bool
    {
        $filePath = $this->getFullPath($relativePath);
        $dir = \dirname($filePath);

        // Überprüfen und erstellen des Verzeichnisses
        if (!\is_dir($dir)) {
            if (!\mkdir($dir, 0o755, true) && !\is_dir($dir)) {
                throw new RuntimeException("Konnte Verzeichnis nicht erstellen: {$dir}");
            }
        }

        $written = \file_put_contents($filePath, $content, LOCK_EX) !== false;

        if ($written) {
            $this->purgeCache($relativePath);
        }

        return $written;
    }

    public function readFile(string $relativePath): ?string
    {
        $filePath = $this->getFullPath($relativePath);
        
        if (!\is_readable($filePath)) {
            return null;
        }
        
        // Prüfe zuerst die Dateigröße, bevor wir den Inhalt laden
        $fileSize = @\filesize($filePath);
        if ($fileSize === false) {
            return null;
        }
        
        $cacheKey = 'file_content_' . md5($this->baseDir . '_' . $relativePath);
        
        // Nur cachen, wenn die Datei nicht zu groß ist (z.B. < 1MB)
        if ($fileSize < 1048576) {
            $cachedContent = $this->cache->get($cacheKey);
            if ($cachedContent !== null) {
                return $cachedContent;
            }
        }
        
        $content = \file_get_contents($filePath);
        
        if ($content !== false && $fileSize < 1048576) {
            $this->cache->set($cacheKey, (string)$content);
        }
        
        return $content !== false ? $content : null;
    }

    public function deleteFile(string $relativePath): bool
    {
        $filePath = $this->getFullPath($relativePath);

        if (!\file_exists($filePath)) {
            return false;
        }

        $this->purgeCache($relativePath);
        
        return \unlink($filePath);
    }

    /** 
     * @return string[] 
     */
    public function listFiles(string $relativeDir, string $extension = 'md'): array
    {
        $fullPath = $this->getFullPath($relativeDir);
        $cacheKey = 'file_list_' . md5($fullPath . '_' . $extension);
        
        // Versuche aus dem Cache zu lesen
        $cachedFiles = $this->cache->get($cacheKey);
        
        // Effizientere Prüfung, ob wir ein gültiges Array haben
        if ($cachedFiles !== null) {
            $decodedFiles = json_decode($cachedFiles, true);
            if (is_array($decodedFiles)) {
                return $decodedFiles;
            }
        }

        if (!\is_dir($fullPath)) {
            return [];
        }

        $extension = \ltrim($extension, '.');
        $pattern = $fullPath . DIRECTORY_SEPARATOR . '*.' . $extension;
        $files = \glob($pattern);

        $result = $files ? \array_map('basename', $files) : [];
        
        // Ergebnis als JSON-String im Cache speichern
        $this->cache->set($cacheKey, json_encode($result));
        
        return $result;
    }

    /** 
     * @return string[] 
     */
    public function listFilesInDirectory(
        string $directoryKey,
        string $relativeDir,
        string $extension = 'md'
    ): array {
        return $this->inDirectory(
            $directoryKey,
            fn (): array => $this->listFiles($relativeDir, $extension)
        );
    }

    public function getFullPath(string $path): string
    {
        // Selbstkontrolle: Nach X Operationen Cache-Größe überprüfen
        $this->operationCounter++;
        if ($this->operationCounter > 1000) {
            $this->trimPathCache();
            $this->operationCounter = 0;
        }
        
        // Schneller Pfad für absolute Pfade, die schon mit dem baseDir beginnen
        if (\str_starts_with($path, $this->baseDir) && \realpath($path) !== false) {
            return PathResolver::resolve($this->baseDir, \substr($path, \strlen($this->baseDir)));
        }
        
        // Cache-Schlüssel für den aktuellen Pfad
        $cacheKey = $this->baseDir . '|' . $path;
        
        // Prüfen, ob der Pfad bereits im Cache ist
        if (isset($this->pathCache[$cacheKey])) {
            return $this->pathCache[$cacheKey];
        }
        
        // Pfad auflösen und im Cache speichern
        $resolvedPath = PathResolver::resolve($this->baseDir, $path);
        
        // Cache-Größe kontrollieren, bevor wir einen neuen Eintrag hinzufügen
        if (count($this->pathCache) >= self::MAX_PATH_CACHE_SIZE) {
            $this->trimPathCache();
        }
        
        $this->pathCache[$cacheKey] = $resolvedPath;
        
        return $resolvedPath;
    }

    /** 
     * @return string[] 
     */
    public function glob(string $pattern): array
    {
        $fullPattern = $this->getFullPath($pattern);
        $cacheKey = 'glob_' . md5($fullPattern);
        
        // Versuche aus dem Cache zu lesen
        $cachedResult = $this->cache->get($cacheKey);
        
        // Effizientere Prüfung, ob wir ein gültiges Array haben
        if ($cachedResult !== null) {
            $decodedResult = json_decode($cachedResult, true);
            if (is_array($decodedResult)) {
                return $decodedResult;
            }
        }
        
        $files = \glob($fullPattern);
        $result = $files ?: [];
        
        // Ergebnis als JSON-String im Cache speichern, sofern es nicht zu groß ist
        if (count($result) < 1000) {
            $this->cache->set($cacheKey, json_encode($result), 300);
        }
        
        return $result;
    }

    public function exists(string $relativePath): bool
    {
        $fullPath = $this->getFullPath($relativePath);
        $cacheKey = 'exists_' . md5($fullPath);
    
        // Aus dem Cache holen und als Boolean zurückgeben
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return (bool) json_decode($cached, true);
        }
    
        // Datei‑Check durchführen
        $exists = \file_exists($fullPath);
    
        // Als JSON‑String cachen (erwarteter Typ: string)
        $this->cache->set($cacheKey, json_encode($exists), 60);
    
        return $exists;
    }

    public function createDirectory(string $dir): bool
    {
        $fullPath = $this->getFullPath($dir);

        if (\is_dir($fullPath)) {
            return true;
        }
        
        $success = \mkdir($fullPath, 0o755, true);
        
        if ($success) {
            // Nicht nur den Path-Cache leeren, sondern auch die relevanten Verzeichniscaches
            $this->purgeCacheForDirectory(dirname($fullPath));
            $this->trimPathCache();
        }
        
        return $success;
    }

    /* --------------------------------------------------------------------- */
    /* ----------------------- interne Hilfsfunktionen ---------------------- */
    /* --------------------------------------------------------------------- */

    /**
     * Führt einen Callback im Kontext eines Verzeichnisses aus
     * und stellt anschließend das vorige `$baseDir` wieder her.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function inDirectory(string $directoryKey, callable $callback)
    {
        $originalDir = $this->baseDir;
        $originalPathCache = $this->pathCache;

        try {
            $this->useDirectory($directoryKey);
            return $callback();
        } finally {
            $this->baseDir = $originalDir;
            $this->pathCache = $originalPathCache;
        }
    }

    /**
     * Leert Cache-Einträge, die mit einem bestimmten Pfad zusammenhängen
     */
    private function purgeCache(string $relativePath): void
    {
        $fullPath = $this->getFullPath($relativePath);
        
        // Spezifische Cache-Schlüssel löschen
        $this->cache->delete($relativePath);
        $this->cache->delete(\md5($fullPath));
        $this->cache->delete('file_content_' . md5($this->baseDir . '_' . $relativePath));
        $this->cache->delete('exists_' . md5($fullPath));
        
        // Cache für das übergeordnete Verzeichnis bereinigen
        $this->purgeCacheForDirectory(dirname($fullPath));
    }
    
    /**
     * Leert Cache-Einträge für ein Verzeichnis
     */
    private function purgeCacheForDirectory(string $dirPath): void
    {
        // Glob und listFiles-Cache zurücksetzen für das Verzeichnis
        $this->cache->delete('glob_' . md5($dirPath . DIRECTORY_SEPARATOR . '*'));
        
        // Bekannte Dateierweiterungen und zusätzlich weitere typische CMS-Erweiterungen
        $extensions = ['md', 'php', 'html', 'json', 'txt', 'yaml', 'yml', 'twig', 'css', 'js', 'xml', 'csv'];
        
        $fileListCachePrefix = 'file_list_' . md5($dirPath . '_');
        foreach ($extensions as $ext) {
            $this->cache->delete($fileListCachePrefix . $ext);
        }
    }
    
    /**
     * Setzt den internen Path-Cache zurück
     */
    private function resetPathCache(): void
    {
        $this->pathCache = [];
        $this->operationCounter = 0;
    }
    
    /**
     * Trimmt den Path-Cache auf eine vernünftige Größe
     * Einfache LRU-Simulation: Wir behalten nur die Hälfte der jüngsten Einträge
     */
    private function trimPathCache(): void
    {
        if (count($this->pathCache) > self::MAX_PATH_CACHE_SIZE / 2) {
            // Behalte nur die letzten Hälfte der Einträge (einfache LRU-Strategie)
            $this->pathCache = array_slice($this->pathCache, 
                -(int)(self::MAX_PATH_CACHE_SIZE / 2), 
                null, 
                true);
        }
    }
}
<?php
declare(strict_types=1);

namespace Marques\Filesystem;

class PathRegistry
{
    protected array $paths = [];

    public function __construct()
    {
        $this->initializePaths();
    }

    private function initializePaths(): void
    {
        $root = dirname(__DIR__, 2);
        if (!$root || !is_dir($root)) {
            throw new \RuntimeException("Root‑Pfad konnte nicht ermittelt werden.");
        }
        $this->paths = [
            'root'            => $root,
            'core'            => $root . '/lib/core',
            'logs'            => $root . '/logs',
            'content'         => $root . '/content',
            'templates'       => $root . '/templates',
            'cache'           => $root . '/lib/cache',
            'admin'           => $root . '/admin',
            'admin_template'  => $root . '/admin/lib/templates',
            'themes'          => $root . '/themes',
        ];
    }

    public function getPath(string $key): string
    {
        if (!isset($this->paths[$key])) {
            throw new \RuntimeException("Pfad‑Schlüssel '{$key}' ist nicht definiert.");
        }
        return $this->paths[$key];
    }

    public function combine(string $baseKey, string $subPath): string
    {
        $base = $this->getPath($baseKey);
        return PathResolver::resolve($base, $subPath);
    }

    // ------- weitere unveränderte Wrapper (exists, setPermissions, ...) -------

    public function exists(string $key, string $subPath = ''): bool
    {
        $path = $subPath === '' ? $this->getPath($key) : $this->combine($key, $subPath);
        return file_exists($path);
    }

    public function setPermissions(string $key, string $subPath, int $permissions): bool
    {
        $path = $this->combine($key, $subPath);
        return chmod($path, $permissions);
    }

    public function isValidPath(string $path): bool
    {
        return strpos($path, "\0") === false && !preg_match('#[<>:"|?*]#u', $path);
    }

    public function getAllPaths(): array
    {
        return $this->paths;
    }

    public function preparePath(string $key, string $type = 'directory', int $permissions = 0755): string
    {
        if (!in_array($type, ['directory', 'file'], true)) {
            throw new \InvalidArgumentException("Ungültiger Typ '{$type}'.");
        }
        $fullPath  = $this->getPath($key);
        $directory = ($type === 'directory') ? $fullPath : dirname($fullPath);
        if (!is_dir($directory) && !mkdir($directory, $permissions, true)) {
            throw new \RuntimeException("Verzeichnis '{$directory}' konnte nicht erstellt werden.");
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException("Verzeichnis '{$directory}' ist nicht beschreibbar.");
        }
        if ($type === 'file') {
            if (!file_exists($fullPath) && !touch($fullPath)) {
                throw new \RuntimeException("Datei '{$fullPath}' konnte nicht erstellt werden.");
            }
            chmod($fullPath, $permissions);
            if (!is_writable($fullPath)) {
                throw new \RuntimeException("Datei '{$fullPath}' ist nicht beschreibbar.");
            }
        }
        return $fullPath;
    }
}

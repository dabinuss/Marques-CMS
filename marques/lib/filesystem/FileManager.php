<?php
declare(strict_types=1);

namespace Marques\Filesystem;

use InvalidArgumentException;
use RuntimeException;
use Marques\Core\Cache;
use Marques\Filesystem\PathResolver;
use Marques\Filesystem\PathRegistry;

/**
 * File‑ & Directory‑Utility für Marques CMS.
 *
 * @phpstan-type DirectoryMap array<string,string>
 */
class FileManager
{
    protected string $baseDir;

    protected readonly Cache $cache;

    /** @var DirectoryMap */
    protected array $knownDirectories = [
        'content'           => MARQUES_CONTENT_DIR,
        'themes'            => MARQUES_THEMES_DIR,
        'admin'             => MARQUES_ADMIN_DIR,
        'backend_templates' => MARQUES_ADMIN_DIR . '/templates',
    ];

    /**
     * @param Cache                         $cache
     * @param string|array<string,string>   $baseDir
     */
    /**
     * @param Cache                         $cache
     * @param PathRegistry                  $paths
     * @param string|array<string,string>|null $baseDir
     */
    public function __construct(
        Cache $cache,
        PathRegistry $paths,
        string|array|null $baseDir = null
    ) {
        $this->knownDirectories = [
            'content'           => $paths->getPath('content'),
            'themes'            => $paths->getPath('themes'),
            'admin'             => $paths->getPath('admin'),
            'backend_templates' => $paths->getPath('admin_template'),
        ];

        if (\is_array($baseDir)) {
            $this->knownDirectories = [...$this->knownDirectories, ...$baseDir];
            $this->baseDir          = \rtrim(
                $this->knownDirectories['content'],
                DIRECTORY_SEPARATOR
            );
        } elseif (\is_string($baseDir)) {
            $this->baseDir = \rtrim($baseDir, DIRECTORY_SEPARATOR);
        } else {
            $this->baseDir = $paths->getPath('content');
        }

        $this->cache = $cache;
    }

    /** @return self */
    public function setBaseDir(string $newBaseDir): self
    {
        $this->baseDir = \rtrim($newBaseDir, DIRECTORY_SEPARATOR);

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

        return $this;
    }

    /** @return self */
    public function addDirectory(string $key, string $path): self
    {
        $this->knownDirectories[$key] = \rtrim($path, DIRECTORY_SEPARATOR);

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

    /** @throws RuntimeException */
    public function writeFile(string $relativePath, string $content): bool
    {
        $filePath = $this->getFullPath($relativePath);
        $dir      = \dirname($filePath);

        if (!\is_dir($dir) && !\mkdir($dir, 0o755, true) && !\is_dir($dir)) {
            throw new RuntimeException("Konnte Verzeichnis nicht erstellen: {$dir}");
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

        return (\is_readable($filePath)) ? \file_get_contents($filePath) : null;
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

    /** @return string[] */
    public function listFiles(string $relativeDir, string $extension = 'md'): array
    {
        $fullPath = $this->getFullPath($relativeDir);

        if (!\is_dir($fullPath)) {
            return [];
        }

        $files = \glob($fullPath . DIRECTORY_SEPARATOR . '*.' . \ltrim($extension, '.'));

        return $files ? \array_map('basename', $files) : [];
    }

    /** @return string[] */
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
        if (\str_starts_with($path, $this->baseDir) && \realpath($path) !== false) {
            return PathResolver::resolve($this->baseDir, \substr($path, \strlen($this->baseDir)));
        }

        return PathResolver::resolve($this->baseDir, $path);
    }

    /** @return string[] */
    public function glob(string $pattern): array
    {
        $files = \glob($this->getFullPath($pattern));

        return $files ?: [];
    }

    public function exists(string $relativePath): bool
    {
        return \file_exists($this->getFullPath($relativePath));
    }

    public function createDirectory(string $dir): bool
    {
        $fullPath = $this->getFullPath($dir);

        return \is_dir($fullPath) || \mkdir($fullPath, 0o755, true);
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

        try {
            $this->useDirectory($directoryKey);

            return $callback();
        } finally {
            $this->baseDir = $originalDir;
        }
    }

    private function purgeCache(string $relativePath): void
    {
        $this->cache->delete($relativePath);
        $this->cache->delete(\md5($this->getFullPath($relativePath)));
    }
}

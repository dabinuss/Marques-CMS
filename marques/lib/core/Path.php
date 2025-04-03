<?php
declare(strict_types=1);

namespace Marques\Core;

class Path
{
    protected array $paths = [];

    public function __construct() {
        $this->initializePaths();
    }

    private function initializePaths(): void
    {
        $root = dirname(__DIR__, 2);
        if (!$root || !is_dir($root)) {
            throw new \Exception("Der Root-Pfad konnte nicht ermittelt werden.");
        }
        
        $this->paths = [
            'root'      => $root,
            'core'      => $root . '/lib/core',
            'logs'      => $root . '/logs',
            'content'   => $root . '/content',
            'templates' => $root . '/templates',
            'cache'     => $root . '/lib/cache',
            'admin'     => $root . '/admin',
            'admin_template' => $root . '/admin/lib/templates',
            'themes'    => $root . '/themes',
        ];
    }

    /**
     * Gibt den Pfad zu einem definierten Schlüssel zurück.
     *
     * @param string $key Der Name des Pfads (z.B. "content", "system").
     * @return string
     * @throws \Exception Falls der Pfad nicht definiert ist.
     */
    public function getPath(string $key): string
    {
        if (!isset($this->paths[$key])) {
            throw new \Exception("Pfad für Schlüssel '{$key}' ist nicht definiert.");
        }
        return $this->paths[$key];
    }

    /**
     * Kombiniert einen Basis-Pfad mit einem relativen Unterpfad.
     *
     * Dabei werden potenzielle Sicherheitslücken (z.B. Directory-Traversal) minimiert.
     *
     * @param string $baseKey Schlüssel des Basis-Pfads.
     * @param string $subPath Relativer Unterpfad.
     * @return string Kombinierter Pfad.
     * @throws \Exception Falls der kombinierte Pfad außerhalb des Root-Verzeichnisses liegt
     *                    oder der Unterpfad ungültige Zeichen enthält.
     */
    public function combine(string $base, string $subPath): string {
        if (!$this->isValidPath($subPath)) {
            throw new \Exception("Ungültiger Unterpfad: '{$subPath}'.");
        }
        // Nun direkt sichere Auflösung:
        return $this->safeResolve($base, $subPath);
    }

    /**
     * Prüft, ob ein Dateipfad bzw. Ordner existiert.
     *
     * @param string $key Basis-Pfad-Schlüssel.
     * @param string $subPath Optionaler relativer Unterpfad.
     * @return bool
     */
    public function exists(string $key, string $subPath = ''): bool
    {
        $path = $subPath ? $this->combine($key, $subPath) : $this->getPath($key);
        return file_exists($path);
    }

    /**
     * Setzt die Berechtigungen (chmod) für einen bestimmten Pfad.
     *
     * @param string $key Basis-Pfad-Schlüssel.
     * @param string $subPath Relativer Unterpfad.
     * @param int $permissions Die zu setzenden Berechtigungen (z.B. 0755).
     * @return bool
     */
    public function setPermissions(string $key, string $subPath, int $permissions): bool
    {
        $path = $this->combine($key, $subPath);
        return chmod($path, $permissions);
    }

    /**
     * Validiert einen Pfad-String anhand eines Regex.
     *
     * @param string $path Zu validierender Pfad.
     * @return bool
     */
    public function isValidPath(string $path): bool
    {
        // Erlaubt alphanumerische Zeichen, Bindestriche, Unterstriche, Schrägstriche und Punkte.
        return preg_match('/^[a-zA-Z0-9\-\_\/\.]*$/', $path) === 1;
    }

    /**
     * Gibt alle registrierten Pfade zurück.
     *
     * @return array
     */
    public function getAllPaths(): array
    {
        return $this->paths;
    }

    /**
     * Überprüft und bereitet einen Pfad (Verzeichnis oder Datei) vor.
     *
     * @param string $path Schlüssel für den Pfad
     * @param string $type 'directory' oder 'file'
     * @param int $permissions
     * @return string
     */
    public function preparePath(string $path, string $type = 'directory', int $permissions = 0755): string
    {
        if (!in_array($type, ['directory', 'file'], true)) {
            throw new \InvalidArgumentException("Ungültiger Typ '{$type}'. Erlaubt sind 'directory' oder 'file'.");
        }
    
        $fullPath = $this->getPath($path);
        $directory = ($type === 'directory') ? $fullPath : dirname($fullPath);
        
        if (!is_dir($directory) && !mkdir($directory, $permissions, true)) {
            throw new \RuntimeException("Das Verzeichnis '{$directory}' konnte nicht erstellt werden.");
        }
        
        if (!is_writable($directory)) {
            throw new \RuntimeException("Das Verzeichnis '{$directory}' ist nicht beschreibbar.");
        }
        
        if ($type === 'file') {
            if (!file_exists($fullPath)) {
                if (false === touch($fullPath)) {
                    throw new \RuntimeException("Die Datei '{$fullPath}' konnte nicht erstellt werden.");
                }
            }
            if (!chmod($fullPath, $permissions)) {
                throw new \RuntimeException("Die Berechtigungen für die Datei '{$fullPath}' konnten nicht gesetzt werden.");
            }
            if (!is_writable($fullPath)) {
                throw new \RuntimeException("Die Datei '{$fullPath}' ist nicht beschreibbar.");
            }
        }
        
        return $fullPath;
    }

    /**
     * Löst einen relativen Pfad sicher auf, ohne realpath() zu verwenden.
     *
     * Dabei wird der Pfad in seine Komponenten zerlegt, '.' und '..'
     * werden korrekt interpretiert und es wird überprüft, dass der
     * resultierende Pfad im Basisverzeichnis verbleibt.
     *
     * @param string $base Der absolute Basis-Pfad (ohne nachgestellten Schrägstrich)
     * @param string $relative Der relative Pfad, der aufgelöst werden soll
     * @return string Der aufgelöste absolute Pfad
     * @throws \Exception Falls der Pfad versucht, das Basisverzeichnis zu verlassen.
     */
    protected function safeResolve(string $base, string $relative): string {
        if (trim($relative) === '') {
            return rtrim($base, DIRECTORY_SEPARATOR);
        }
        $relative = str_replace("\0", '', $relative);
        $relative = ltrim($relative, '/\\');
        $parts = preg_split('#[\\\\/]+#', $relative);
        $safeParts = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (count($safeParts) > 0) {
                    array_pop($safeParts);
                } else {
                    throw new \Exception("Ungültiger Pfad: Versuch, außerhalb des Basisverzeichnisses zu gelangen.");
                }
            } else {
                $safeParts[] = $part;
            }
        }
        $normalizedRelative = safe_implode(DIRECTORY_SEPARATOR, $safeParts);
        $resolved = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalizedRelative;
        $baseNormalized = rtrim($base, DIRECTORY_SEPARATOR);
        if (strncmp($resolved, $baseNormalized, strlen($baseNormalized)) !== 0) {
            throw new \Exception("Sicherheitsverstoß: Der aufgelöste Pfad verlässt das Basisverzeichnis.");
        }
        return $resolved;
    }
}

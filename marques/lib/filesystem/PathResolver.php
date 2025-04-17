<?php
declare(strict_types=1);

namespace Marques\Filesystem;

final class PathResolver
{
    public static function resolve(string $base, string $relative): string
    {
        $base = rtrim(realpath($base) ?: $base, DIRECTORY_SEPARATOR);
        if ($base === '' || !is_dir($base)) {
            throw new \RuntimeException("Ungültiger Basis‑Pfad '{$base}'.");
        }

        if ($relative === '' || $relative === '.' || $relative === DIRECTORY_SEPARATOR) {
            return $base;
        }

        $relative  = str_replace("\0", '', $relative);
        $relative  = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative);
        $candidate = $base . DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR);
        $resolved  = realpath($candidate) ?: self::normalise($candidate);

        if (strpos($resolved, $base) !== 0) {
            throw new \RuntimeException("Pfad‑Traversal erkannt: '{$relative}'.");
        }
        return $resolved;
    }

    private static function normalise(string $path): string
    {
        $parts    = [];
        $segments = preg_split('#[\\\\/]+#', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
            } else {
                $parts[] = $segment;
            }
        }
        $prefix = '';
        if (preg_match('#^[A-Za-z]:#', $path, $m)) {
            $prefix = $m[0];
        }
        return $prefix . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }
}
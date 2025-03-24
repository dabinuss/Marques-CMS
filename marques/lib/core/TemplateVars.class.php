<?php
declare(strict_types=1);

namespace Marques\Core;

class TemplateVars {
    protected array $data = [];
    protected AppCache $cache;

    /**
     * Konstruktor.
     * @param array $data Template-Daten
     * @param AppCache $cache Die injizierte Cache-Instanz
     */
    public function __construct(AppCache $cache, array $data = []) {
        $this->cache = $cache;
        $this->data = $data;
    }

    public function __get(string $key) {
        return $this->data[$key] ?? null;
    }

    public function __set(string $key, $value): void {
        $this->data[$key] = $value;
    }

    /**
     * Gibt die Theme-Assets URL zurück, inklusive Cachebusting.
     */
    public function themeUrl(string $path = ''): string {
        if (isset($this->data['themeManager']) && method_exists($this->data['themeManager'], 'getThemeAssetsUrl')) {
            $url = $this->data['themeManager']->getThemeAssetsUrl($path);
            return $this->cache->bustUrl($url);
        }
        return $path;
    }
}

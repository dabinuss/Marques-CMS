<?php
declare(strict_types=1);

namespace Marques\Core;

class TemplateVars {
    protected array $data = [];

    public function __construct(array $data = []) {
        $this->data = $data;
    }

    public function __get(string $key) {
        return $this->data[$key] ?? null;
    }

    public function __set(string $key, $value): void {
        $this->data[$key] = $value;
    }

    /**
     * Gibt die Theme-Assets URL zurück und führt automatisch Cachebusting durch.
     *
     * @param string $path Pfad zur Asset-Datei
     * @return string
     */
    public function themeUrl(string $path = ''): string {
        if (isset($this->data['themeManager']) && method_exists($this->data['themeManager'], 'getThemeAssetsUrl')) {
            $url = $this->data['themeManager']->getThemeAssetsUrl($path);
            // Automatisch Cachebusting durchführen:
            $cacheManager = CacheManager::getInstance();
            return $cacheManager->bustUrl($url);
        }
        return $path;
    }
}

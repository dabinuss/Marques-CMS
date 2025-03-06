<?php
declare(strict_types=1);

namespace Marques\Core;

class TemplateVars {
    protected array $data = [];

    public function __construct(array $data = []) {
        $this->data = $data;
    }

    // Ermöglicht den Zugriff per $tpl->irgendwas
    public function __get(string $key) {
        return $this->data[$key] ?? null;
    }

    // Setzen von Werten per $tpl->irgendwas = $value
    public function __set(string $key, $value): void {
        $this->data[$key] = $value;
    }

    // Beispielmethode: Liefert die Theme-Assets URL
    public function themeUrl(string $path = ''): string {
        if (isset($this->data['themeManager']) && method_exists($this->data['themeManager'], 'getThemeAssetsUrl')) {
            return $this->data['themeManager']->getThemeAssetsUrl($path);
        }
        return $path;
    }
}

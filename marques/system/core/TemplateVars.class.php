<?php
declare(strict_types=1);

namespace Marques\Core;

class TemplateVars {
    protected array $data = [];

    public function __construct(array $data = []) {
        $this->data = $data;
    }

    public function __get(string $name) {
        if (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }
        return null;
    }

    public function __isset(string $name): bool {
        return isset($this->data[$name]);
    }

    public function __set(string $name, $value): void{
      $this->data[$name] = $value;
    }


    public function themeUrl(string $path = ''): string {
        if (isset($this->data['themeManager']) && $this->data['themeManager'] instanceof ThemeManager) {
            $url =  $this->data['themeManager']->getThemeAssetsUrl($path);

            if (isset($this->data['cacheManager']) && $this->data['cacheManager'] instanceof CacheManager){
                $cacheManager =  $this->data['cacheManager'];
                return $cacheManager->bustUrl($url);
            }
           return $url;
        }

        return $path;
    }
}
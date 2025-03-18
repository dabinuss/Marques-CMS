<?php
declare(strict_types=1);

namespace Marques\Admin;

use Marques\Core\AppSettings;

class AdminSettings extends AppSettings
{
    /**
     * Setzt einen einzelnen Einstellungswert.
     * Unterstützt Dot-Notation für verschachtelte Werte.
     */
    public function setSetting(string $key, $value): void
    {
        if (strpos($key, '.') !== false) {
            $keys = explode('.', $key);
            $lastKey = array_pop($keys);
            $current = &$this->_system_settings;
            foreach ($keys as $k) {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
            $current[$lastKey] = $value;
        } else {
            $this->_system_settings[$key] = $value;
        }
    }

    /**
     * Setzt mehrere Einstellungen gleichzeitig.
     */
    public function setMultipleSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->setSetting($key, $value);
        }
    }

    /**
     * Speichert die aktuellen Einstellungen über AppConfig.
     */
    public function saveSettings(): bool
    {
        if (isset($this->_system_settings['base_url'])) {
            $baseUrl = rtrim($this->_system_settings['base_url'], '/');
            // Entferne /admin vom Pfad für eine konsistente Base-URL
            if (strpos($baseUrl, '/admin') !== false) {
                $this->_system_settings['base_url'] = preg_replace('|/admin$|', '', $baseUrl);
            }
        }
        return $this->appConfig->save('system', $this->_system_settings);
    }
}

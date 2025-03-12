<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * Einfacher Dependency Injection AppContainer
 */
class AppContainer {
    private array $services = [];
    private array $instances = [];    
    
    /**
     * Registriert einen Service
     */
    public function register(string $id, $concrete = null): self {
        $this->services[$id] = $concrete ?: $id;
        return $this;
    }
    
    /**
     * Holt einen Service
     */
    public function get(string $id) {
        if (!isset($this->services[$id])) {
            throw new \Exception("Service '$id' nicht gefunden");
        }
        
        if (!isset($this->instances[$id])) {
            $concrete = $this->services[$id];
            
            if ($concrete instanceof \Closure) {
                $this->instances[$id] = $concrete($this);
            } elseif (is_string($concrete) && class_exists($concrete)) {
                $this->instances[$id] = new $concrete();
            } else {
                $this->instances[$id] = $concrete;
            }
        }
        
        return $this->instances[$id];
    }
    
    /**
     * Prüft, ob ein Service existiert
     */
    public function has(string $id): bool {
        return isset($this->services[$id]);
    }
}
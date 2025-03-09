<?php
declare(strict_types=1);

namespace Marques\Core;

class Docker {
    private array $services = [];
    private array $instances = [];

    public function register(string $id, $concrete = null): self {
        $this->services[$id] = $concrete;
        return $this;
    }

    public function resolve(string $id) {
        if (!isset($this->services[$id])) {
            throw new \Exception("Service '$id' nicht gefunden");
        }
        if (!isset($this->instances[$id])) {
            $concrete = $this->services[$id];
            if ($concrete instanceof \Closure) {
                $this->instances[$id] = $concrete($this);
            } elseif (is_string($concrete) && class_exists($concrete)) {
                 $this->instances[$id] = new $concrete($this); //docker parameter
            } else {
                $this->instances[$id] = $concrete;
            }
        }
        return $this->instances[$id];
    }

    public function has(string $id): bool {
        return isset($this->services[$id]);
    }
}
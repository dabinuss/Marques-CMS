<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * Einfacher Dependency Injection AppContainer ohne Exceptions und Interface
 */
class AppContainer {
    /** @var array<string, callable|string|object|null> */
    private array $services = [];
    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * Registriert einen Service
     * @param string $id Identifier des Services
     * @param callable|string|object|null $concrete Konkrete Implementierung, Closure, Klassenname oder Objekt
     */
    public function register(string $id, callable|object|array|string|null $concrete = null): self {
        $this->services[$id] = $concrete ?: $id;
        return $this;
    }

    /**
     * Holt einen Service und instanziiert diesen bei Bedarf (inklusive Autowiring)
     */
    public function get(string $id): mixed {
        if (!isset($this->services[$id])) {
            return null;
        }

        if (!isset($this->instances[$id])) {
            $concrete = $this->services[$id];

            if ($concrete instanceof \Closure) {
                $this->instances[$id] = $concrete($this);
            } elseif (is_string($concrete) && class_exists($concrete)) {
                $this->instances[$id] = $this->build($concrete);
            } elseif (is_object($concrete)) {
                $this->instances[$id] = $concrete;
            } else {
                $this->instances[$id] = $concrete;
            }
        }

        return $this->instances[$id];
    }

    /**
     * Prüft, ob ein Service registriert ist
     */
    public function has(string $id): bool {
        return isset($this->services[$id]);
    }

    /**
     * Baut eine Klasse mittels Reflection und Autowiring
     */
    private function build(string $class): object {
        $reflector = new \ReflectionClass($class);
        $constructor = $reflector->getConstructor();
        if (!$constructor) {
            return new $class;
        }
        $parameters = $constructor->getParameters();
        $dependencies = [];
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            if ($type === null) {
                $dependencies[] = $parameter->isDefaultValueAvailable()
                    ? $parameter->getDefaultValue()
                    : null;
            } else {
                // Bei Union Types: Wähle den ersten nicht eingebauten Typ
                if ($type instanceof \ReflectionUnionType) {
                    $resolved = false;
                    foreach ($type->getTypes() as $singleType) {
                        if (!$singleType->isBuiltin()) {
                            $depClass = $singleType->getName();
                            $dependencies[] = $this->has($depClass) ? $this->get($depClass) : null;
                            $resolved = true;
                            break;
                        }
                    }
                    if (!$resolved) {
                        $dependencies[] = null;
                    }
                } else {
                    $depClass = $type->getName();
                    if (!$type->isBuiltin()) {
                        $dependencies[] = $this->get($depClass);
                    } else {
                        $dependencies[] = $parameter->isDefaultValueAvailable()
                            ? $parameter->getDefaultValue()
                            : null;
                    }
                }
            }
        }
        return $reflector->newInstanceArgs($dependencies);
    }
}

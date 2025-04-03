<?php
declare(strict_types=1);

namespace Marques\Core;

class Node
{

    private array $services = [];
    private array $instances = [];
    protected bool $strictMode = false;
    protected static array $reflectionCache = [];
    protected ?self $parent = null;

    /**
     * Konstruktor, der einen optionalen Parent-Container akzeptiert.
     */
    public function __construct(?self $parent = null)
    {
        $this->parent = $parent;
    }

    /**
     * Setzt den Parent-Container.
     */
    public function set(string $id, $concrete = null): self 
    {
        return $this->register($id, $concrete);
    }

    /**
     * Registriert einen Service.
     *
     * @param string $id Identifier des Services
     * @param callable|string|object|null $concrete Konkrete Implementierung, Closure, Klassenname oder Objekt
     */
    public function register(string $id, $concrete = null): self
    {
        if ($concrete === null) {
            if (!class_exists($id)) {
                throw new \InvalidArgumentException("Kein konkreter Service für '$id' angegeben und Klasse existiert nicht.");
            }
            $concrete = $id;
        }
        $this->services[$id] = $concrete;
        return $this;
    }

    /**
     * Holt einen Service und instanziiert diesen bei Bedarf (inklusive Autowiring).
     *
     * Gibt null zurück, wenn $strictMode = false und der Service nicht gefunden wird.
     */
    public function get(string $id): mixed
    {
        if (!isset($this->services[$id])) {
            // Falls in dieser Node nicht vorhanden, im Parent nachsehen
            if ($this->parent !== null) {
                return $this->parent->get($id);
            }
            if ($this->strictMode) {
                throw new \RuntimeException("Service '$id' not found (strict mode).");
            }
            return null;
        }

        if (!isset($this->instances[$id])) {
            $concrete = $this->services[$id];

            if ($concrete instanceof \Closure) {
                $this->instances[$id] = $concrete($this);
            } elseif (is_string($concrete)) {
                if (!class_exists($concrete)) {
                    if ($this->strictMode) {
                        throw new \RuntimeException("Class '$concrete' does not exist (strict mode).");
                    }
                    $this->instances[$id] = null;
                } else {
                    $this->instances[$id] = $this->build($concrete);
                }
            } elseif (is_object($concrete)) {
                $this->instances[$id] = $concrete;
            } else {
                $this->instances[$id] = $concrete;
            }
        }

        return $this->instances[$id];
    }

    /**
     * Prüft, ob ein Service registriert ist.
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]) || ($this->parent !== null && $this->parent->has($id));
    }

    /**
     * Baut eine Klasse mittels Reflection und Autowiring.
     */
    protected function build(string $class): object
    {
        if (!isset(self::$reflectionCache[$class])) {
            $reflectionClass = new \ReflectionClass($class);
            $constructor = $reflectionClass->getConstructor();
            $params = $constructor ? $constructor->getParameters() : [];
            self::$reflectionCache[$class] = [
                'class'  => $reflectionClass,
                'params' => $params
            ];
        }

        $reflector  = self::$reflectionCache[$class]['class'];
        $parameters = self::$reflectionCache[$class]['params'] ?? [];

        if (empty($parameters)) {
            return new $class;
        }

        $dependencies = [];
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            if ($type === null) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } elseif ($this->strictMode) {
                    throw new \RuntimeException(
                        "Cannot resolve untyped parameter \${$parameter->getName()} in '$class' (strict mode)."
                    );
                } else {
                    $dependencies[] = null;
                }
            } elseif ($type instanceof \ReflectionUnionType) {
                $resolved = false;
                foreach ($type->getTypes() as $singleType) {
                    if (!$singleType->isBuiltin()) {
                        $depClass = $singleType->getName();
                        if ($this->has($depClass)) {
                            $dependencies[] = $this->get($depClass);
                        } elseif ($this->strictMode) {
                            throw new \RuntimeException(
                                "Unresolvable dependency '$depClass' for \${$parameter->getName()} in '$class' (strict mode)."
                            );
                        } else {
                            $dependencies[] = null;
                        }
                        $resolved = true;
                        break;
                    }
                }
                if (!$resolved) {
                    if ($parameter->isDefaultValueAvailable()) {
                        $dependencies[] = $parameter->getDefaultValue();
                    } elseif ($this->strictMode) {
                        throw new \RuntimeException(
                            "Cannot resolve union type for \${$parameter->getName()} in '$class' (strict mode)."
                        );
                    } else {
                        $dependencies[] = null;
                    }
                }
            } else {
                $depClass = $type->getName();
                if (!$type->isBuiltin()) {
                    if ($this->has($depClass)) {
                        $dependencies[] = $this->get($depClass);
                    } elseif ($this->strictMode) {
                        throw new \RuntimeException(
                            "Unresolvable dependency '$depClass' for \${$parameter->getName()} in '$class' (strict mode)."
                        );
                    } else {
                        $dependencies[] = null;
                    }
                } else {
                    if ($parameter->isDefaultValueAvailable()) {
                        $dependencies[] = $parameter->getDefaultValue();
                    } elseif ($this->strictMode) {
                        throw new \RuntimeException(
                            "Missing default for builtin type parameter \${$parameter->getName()} in '$class' (strict mode)."
                        );
                    } else {
                        $dependencies[] = null;
                    }
                }
            }
        }

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Schaltet den Strict Mode ein oder aus.
     */
    public function setStrictMode(bool $strict): self
    {
        $this->strictMode = $strict;
        return $this;
    }

    /**
     * Gibt zurück, ob der Strict Mode aktiv ist.
     */
    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }
}

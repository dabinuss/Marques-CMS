<?php
declare(strict_types=1);

namespace Marques\Core;

/**
 * Einfacher Dependency Injection AppNode
 *
 */
class AppNode
{
    /** @var array<string, callable|string|object|null> */
    private array $services = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * Schaltet bei Bedarf strengere Fehlerbehandlung ein.
     * Falls true: Nicht auffindbare Services oder Dependencies werfen Exceptions.
     * Standard: false (für Abwärtskompatibilität).
     */
    protected bool $strictMode = false;

    /**
     * Reflection-Cache für Klassen, um Performance zu verbessern.
     * Zusätzlich speichern wir nun auch die Konstruktor-Parameter,
     * damit getParameters() nicht bei jedem Aufruf erneut erfolgen muss.
     *
     * Format:
     * self::$reflectionCache[$class] = [
     *   'class'  => \ReflectionClass<object>,
     *   'params' => ?\ReflectionParameter[]
     * ];
     *
     * @var array<string, array{class: \ReflectionClass<object>, params: ?array<\ReflectionParameter>}>
     */
    protected static array $reflectionCache = [];

    /**
     * Registriert einen Service.
     * 
     * @param string $id Identifier des Services
     * @param callable|string|object|null $concrete Konkrete Implementierung, Closure, Klassenname oder Objekt
     */
    public function register(string $id, callable|object|array|string|null $concrete = null): self
    {
        $this->services[$id] = $concrete ?: $id;
        return $this;
    }

    /**
     * Holt einen Service und instanziiert diesen bei Bedarf (inklusive Autowiring)
     * 
     * @param string $id
     * @return mixed
     *
     * Gibt null zurück, wenn $strictMode = false und der Service nicht gefunden wird.
     * Wenn $strictMode = true, wird eine Exception geworfen.
     */
    public function get(string $id): mixed
    {
        if (!isset($this->services[$id])) {
            if ($this->strictMode) {
                throw new \RuntimeException("Service '$id' not found (strict mode).");
            }
            return null; // Abwärtskompatibel: Rückgabewert bleibt null.
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
                    // Andernfalls instanziieren wir nichts.
                    $this->instances[$id] = null;
                } else {
                    $this->instances[$id] = $this->build($concrete);
                }
            } elseif (is_object($concrete)) {
                $this->instances[$id] = $concrete;
            } else {
                // Falls ein anderer Typ (z.B. array) übergeben wurde:
                $this->instances[$id] = $concrete;
            }
        }

        return $this->instances[$id];
    }

    /**
     * Prüft, ob ein Service registriert ist
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    /**
     * Baut eine Klasse mittels Reflection und Autowiring.
     *
     */
    protected function build(string $class): object
    {
        // Prüfen, ob wir bereits Reflection-Daten gecacht haben
        if (!isset(self::$reflectionCache[$class])) {
            $reflectionClass = new \ReflectionClass($class);
            $constructor = $reflectionClass->getConstructor();

            // Falls kein Konstruktor vorhanden ist, setzen wir params auf null
            // und erzeugen die Instanz direkt ohne Parameter
            $params = $constructor ? $constructor->getParameters() : [];

            self::$reflectionCache[$class] = [
                'class'  => $reflectionClass,
                'params' => $params
            ];
        }

        $reflector  = self::$reflectionCache[$class]['class'];
        $parameters = self::$reflectionCache[$class]['params'] ?? [];

        // Falls kein Konstruktor existiert, direkt instanzieren
        if (empty($parameters)) {
            return new $class;
        }

        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            // Wenn kein Typ vorhanden ist
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
            }
            // Wenn ein Union Type vorhanden ist
            elseif ($type instanceof \ReflectionUnionType) {
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
                    // Keine nicht eingebaute Type gefunden
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
            }
            else {
                // Normaler (nicht-Union) Typ
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
                    // Eingebauter Typ (int, string etc.)
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
     *
     * @param bool $strict
     * @return self
     */
    public function setStrictMode(bool $strict): self
    {
        $this->strictMode = $strict;
        return $this;
    }

    /**
     * Gibt zurück, ob der Strict Mode aktiv ist.
     *
     * @return bool
     */
    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }
}

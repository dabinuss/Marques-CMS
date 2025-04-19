<?php
declare(strict_types=1);

namespace Marques\Core;

use Closure;
use ReflectionClass;
use ReflectionUnionType;
use ReflectionParameter;
use ReflectionNamedType;
use RuntimeException;
use InvalidArgumentException;

class Node
{
    private array $services = [];
    private array $instances = [];

    protected bool $strictMode = false;
    protected static array $reflectionCache = [];
    protected ?self $parent = null;

    public function __construct(?self $parent = null)
    {
        $this->parent = $parent;
    }

    public function set(string $id, mixed $concrete = null): self
    {
        return $this->register($id, $concrete);
    }

    public function register(string $id, mixed $concrete = null): self
    {
        $this->services[$id] = $concrete ?? $id;

        if ($concrete === null && !class_exists($id)) {
            throw new InvalidArgumentException("Class '$id' not found and no concrete provided.");
        }

        unset($this->instances[$id]);

        return $this;
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (array_key_exists($id, $this->services)) {
            $concrete = $this->services[$id];

            return $this->instances[$id] = match (true) {
                $concrete instanceof Closure => $concrete($this),
                is_object($concrete)         => $concrete,
                is_string($concrete)         => $this->instantiate($concrete),
                default                      => $concrete,
            };
        }

        if ($this->parent) {
            return $this->parent->get($id);
        }

        if ($this->strictMode) {
            throw new RuntimeException("Service '$id' not found in strict mode.");
        }

        return null;
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]) || ($this->parent?->has($id) ?? false);
    }

    public function setStrictMode(bool $strict): self
    {
        $this->strictMode = $strict;
        return $this;
    }

    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }

    public function remove(string $id): void
    {
        unset($this->services[$id], $this->instances[$id]);
    }

    public function dump(): array
    {
        return [
            'services'  => array_keys($this->services),
            'instances' => array_keys($this->instances),
            'strict'    => $this->strictMode,
            'parent'    => $this->parent ? get_class($this->parent) : null,
        ];
    }

    protected function instantiate(string $class): object
    {
        if (!class_exists($class)) {
            return $this->strictMode
                ? throw new RuntimeException("Class '$class' does not exist.")
                : new class {};
        }

        if (!isset(self::$reflectionCache[$class])) {
            $reflectionClass = new ReflectionClass($class);
            $constructor = $reflectionClass->getConstructor();

            self::$reflectionCache[$class] = [
                'class'  => $reflectionClass,
                'params' => $constructor?->getParameters() ?? [],
            ];
        }

        $cached = self::$reflectionCache[$class];

        if (empty($cached['params'])) {
            return $cached['class']->newInstance();
        }

        $dependencies = [];
        foreach ($cached['params'] as $param) {
            $dependencies[] = $this->resolveParameter($param, $class);
        }

        return $cached['class']->newInstanceArgs($dependencies);
    }

    protected function resolveParameter(ReflectionParameter $param, string $contextClass): mixed
    {
        $type = $param->getType();
        $paramName = $param->getName();

        $fallback = function() use ($param, $paramName, $contextClass) {
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }
            return $this->fail("Cannot resolve parameter \${$paramName} in $contextClass.");
        };

        if ($type === null) {
            return $fallback();
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $subType) {
                if (!$subType->isBuiltin() && $this->has($subType->getName())) {
                    return $this->get($subType->getName());
                }
            }
            return $fallback();
        }

        if ($type->isBuiltin()) {
            return $fallback();
        }

        $typeName = $type->getName();
        if ($this->has($typeName)) {
            return $this->get($typeName);
        }

        return $this->fail("Missing dependency {$typeName} for $contextClass.");
    }

    private function fail(string $message): mixed
    {
        if ($this->strictMode) {
            throw new RuntimeException($message);
        }
        return null;
    }
}

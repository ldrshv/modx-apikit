<?php

declare(strict_types=1);

namespace ApiKit;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container
{
    /** @var array<string, mixed> */
    private $bindings = [];

    /** @var array<string, object> */
    private $instances = [];

    public function bind(string $id, $concrete): void
    {
        $this->bindings[$id] = $concrete;
    }

    public function instance(string $id, $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function make(string $id)
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        $concrete = $this->bindings[$id] ?? $id;

        if ($concrete instanceof Closure) {
            return $concrete($this);
        }

        if (!is_string($concrete) || !class_exists($concrete)) {
            throw new RuntimeException('Зависимость не найдена: ' . $id);
        }

        $reflection = new ReflectionClass($concrete);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException('Класс нельзя создать: ' . $concrete);
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->make($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new RuntimeException(
                'Не удалось создать ' . $concrete . ': параметр $' . $parameter->getName()
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }
}

<?php

declare(strict_types=1);

final class Container
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function value(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
        unset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new InvalidArgumentException('Unknown service: ' . $id);
        }
        return $this->instances[$id] = ($this->factories[$id])($this);
    }
}

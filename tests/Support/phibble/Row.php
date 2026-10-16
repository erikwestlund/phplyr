<?php

declare(strict_types=1);

namespace Phibble;

use Phibble\Exception\ColumnNotFound;

final readonly class Row implements \ArrayAccess
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function __get(string $name): mixed
    {
        if (! array_key_exists($name, $this->values)) {
            throw new ColumnNotFound("Unknown column: {$name}");
        }

        return $this->values[$name];
    }

    public function __isset(string $name): bool
    {
        return isset($this->values[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->values);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('Rows are read-only.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('Rows are read-only.');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }
}

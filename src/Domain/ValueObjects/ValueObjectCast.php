<?php

namespace Incoder\DDD\Domain\ValueObjects;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class ValueObjectCast implements CastsAttributes
{
    protected string $valueObjectClass;

    /**
     * Constructor that accepts the ValueObject class name.
     */
    public function __construct(string $valueObjectClass)
    {
        $this->valueObjectClass = $valueObjectClass;
    }

    /**
     * Cast the stored value into a ValueObject instance.
     *
     * @param  Model  $model
     * @return ValueObject|null
     */
    public function get($model, string $key, mixed $value, array $attributes): mixed
    {
        $class = $this->valueObjectClass;

        return $value ? new $class($value) : null;
    }

    /**
     * Prepare the ValueObject for storage.
     *
     * @param  Model  $model
     * @return array<string, mixed>
     */
    public function set($model, string $key, mixed $value, array $attributes): array
    {
        return [$key => $value instanceof ValueObject ? (string) $value : $value];
    }
}

<?php

namespace Incoder\DDD\Domain\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Class ValueObject
 *
 * Base class for all Value Objects in the domain.
 * Value Objects must be immutable and compared by their values.
 */
abstract class ValueObject implements Arrayable, JsonSerializable
{
    /**
     * ValueObject constructor.
     * Ensures immutability by setting properties only once.
     */
    public function __construct(array $values = [])
    {
        foreach ($values as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }

        // Prevent property mutation after construction
        $this->freeze();
    }

    /**
     * Prevent any further changes to the value object (immutability).
     */
    protected function freeze(): void
    {
        foreach (get_object_vars($this) as $key => $value) {
            unset($this->$key);
            $this->$key = $value;
        }
    }

    /**
     * Check value equality.
     */
    public function equals(ValueObject $other): bool
    {
        return get_class($this) === get_class($other)
            && $this->toArray() === $other->toArray();
    }

    /**
     * Convert the value object to an array.
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * JSON serialization.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * String representation of the value object.
     */
    public function __toString(): string
    {
        return json_encode($this->toArray());
    }
}

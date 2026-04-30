<?php

namespace Incoder\DDD\Domain\ValueObjects;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid as RamseyUuid;

/**
 * Class Uuid
 *
 * Represents a strongly typed UUID value object.
 * Ensures that the value is a valid UUID and provides immutability and equality comparison.
 */
class Uuid extends ValueObject
{
    /**
     * @var string The UUID string value.
     */
    protected string $value;

    /**
     * Uuid constructor.
     *
     * @param  string|null  $uuid  The UUID value to wrap. If null, generates a new UUID v4.
     *
     * @throws InvalidArgumentException If the provided UUID is not valid.
     */
    public function __construct(?string $uuid = null)
    {
        $uuid = $uuid ?? RamseyUuid::uuid4()->toString();

        if (! RamseyUuid::isValid($uuid)) {
            throw new InvalidArgumentException("Invalid UUID: $uuid");
        }

        parent::__construct([
            'value' => $uuid,
        ]);
    }

    /**
     * Returns the UUID string value.
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * String representation of the UUID.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}

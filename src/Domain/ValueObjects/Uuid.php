<?php

namespace Incoder\DDD\Domain\ValueObjects;

use Ramsey\Uuid\Uuid as RamseyUuid;
use InvalidArgumentException;

/**
 * Class Uuid
 *
 * Represents a strongly typed UUID value object.
 * Ensures that the value is a valid UUID and provides immutability and equality comparison.
 *
 * @package Incoder\DDD\Domain\ValueObjects
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
     * @param string|null $uuid The UUID value to wrap. If null, generates a new UUID v4.
     *
     * @throws InvalidArgumentException If the provided UUID is not valid.
     */
    public function __construct(?string $uuid = null)
    {
        $uuid = $uuid ?? RamseyUuid::uuid4()->toString();

        if (!RamseyUuid::isValid($uuid)) {
            throw new InvalidArgumentException("Invalid UUID: $uuid");
        }

        parent::__construct([
            'value' => $uuid,
        ]);
    }

    /**
     * Returns the UUID string value.
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * String representation of the UUID.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}

<?php

namespace Incoder\DDD\Domain\ValueObjects;

/**
 * Represents a full name value object.
 */
class FullName extends ValueObject
{
    private string $firstName;

    private string $lastName;

    public function __construct(string $firstName, string $lastName)
    {
        $this->firstName = trim($firstName);
        $this->lastName = trim($lastName);
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function full(): string
    {
        return "{$this->firstName} {$this->lastName}";
    }

    public function __toString(): string
    {
        return $this->full();
    }
}

<?php

namespace Incoder\DDD\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Represents an email address value object.
 */
class EmailAddress extends ValueObject
{
    private string $value;

    public function __construct(string $email)
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address: $email");
        }

        $this->value = $email;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function domain(): string
    {
        return substr(strrchr($this->value, "@"), 1);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

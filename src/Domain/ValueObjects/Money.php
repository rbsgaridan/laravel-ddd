<?php

namespace Incoder\DDD\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Represents a money value object.
 */
class Money extends ValueObject
{
    private string $currency;

    private float $amount;

    public function __construct(float $amount, string $currency = 'PHP')
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Amount cannot be negative');
        }

        $this->amount = round($amount, 2);
        $this->currency = strtoupper($currency);
    }

    public function amount(): float
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function __toString(): string
    {
        return number_format($this->amount, 2).' '.$this->currency;
    }
}

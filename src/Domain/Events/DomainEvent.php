<?php

namespace Incoder\DDD\Domain\Events;

use DateTimeImmutable;

/**
 * Base class for domain events.
 */
abstract class DomainEvent
{
    protected DateTimeImmutable $occurredOn;

    public function __construct()
    {
        $this->occurredOn = new DateTimeImmutable();
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    abstract public function eventName(): string;
}

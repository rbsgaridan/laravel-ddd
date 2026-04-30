<?php

namespace Incoder\DDD\Domain\Entities;

use Illuminate\Support\Collection;
use Incoder\DDD\Domain\Events\DomainEvent;

/**
 * Base class for aggregate roots in a Domain-Driven Design (DDD) architecture.
 *
 * @template T of string
 *
 * @property-read string $id UUID primary key
 */
abstract class AggregateRoot extends Entity
{
    /**
     * The primary key type for all Aggregate .
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var Collection<int, object>
     */
    protected Collection $domainEvents;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->domainEvents = collect();
    }

    /**
     * Add a domain event to this aggregate root.
     *
     * @param  object  $event
     */
    protected function recordEvent(DomainEvent $event): void
    {
        $this->domainEvents->push($event);
    }

    /**
     * Get all domain events.
     *
     * @return array<int, object>
     */
    public function getDomainEvents(): array
    {
        return $this->domainEvents->all();
    }

    /**
     * Clear all recorded domain events.
     */
    public function clearDomainEvents(): void
    {
        $this->domainEvents = collect();
    }
}

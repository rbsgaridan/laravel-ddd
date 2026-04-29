<?php


namespace Incoder\DDD\Domain\Services;

use Incoder\DDD\Domain\Entities\AggregateRoot;

/**
 * @template T of AggregateRoot
 */
interface IDomainServiceFor
{
    /**
     * @return class-string<T>
     */
    public function supports(): string;
}

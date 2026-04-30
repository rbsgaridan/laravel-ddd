<?php

namespace Incoder\DDD\Domain\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Incoder\DDD\Domain\Entities\Entity;

/**
 * Interface IRepository
 *
 * This interface defines the basic operations for a repository in a Domain-Driven Design context.
 * It provides methods for finding, saving, deleting, and counting entities.
 *
 * @template T of IRepository
 */
interface IRepository
{
    /**
     * Find an entity by its ID.
     *
     * @return T|null
     */
    public function find(string $id);

    /**
     * Save an entity.
     *
     * @param  T  $entity
     * @return void
     */
    public function save(Entity $entity): Entity;

    /**
     * Update an existing entity.
     *
     * @param  T  $entity
     * @return void
     */
    public function update(string $id, Entity $entity);

    /**
     * Delete an entity.
     */
    public function delete(string $id): bool;

    /**
     * Find all entities.
     */
    public function findAll(): Collection;

    /**
     * Count all entities.
     */
    public function count(): int;

    /**
     * Paginate entities.
     */
    public function paginate(int $page = 1, int $perPage = 10, array $filters = [], array $sort = []): LengthAwarePaginator;

    public function isAny(callable $criteria): bool;

    public function getList(callable $predicate);

    public function getOne(callable $predicate);
}

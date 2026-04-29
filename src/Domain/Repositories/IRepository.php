<?php

namespace Incoder\DDD\Domain\Repositories;

use Illuminate\Support\Collection;
use Incoder\DDD\Domain\Entities\Entity;
use Illuminate\Pagination\LengthAwarePaginator;

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
     * @param string $id
     * @return T|null
     */
    public function find(string $id);

    /**
     * Save an entity.
     *
     * @param T $entity
     * @return void
     */
    public function save(Entity $entity): Entity;


    /**
     * Update an existing entity.
     *
     * @param string $id
     * @param T $entity
     * @return void
     */
    public function update(string $id, Entity $entity);

    /**
     * Delete an entity.
     *
     * @param string $id
     * @return bool
     */
    public function delete(string $id): bool;

    /**
     * Find all entities.
     *
     * @return Collection
     */
    public function findAll(): Collection;

    /**
     * Count all entities.
     *
     * @return int
     */
    public function count(): int;

    /**
     * Paginate entities.
     *
     * @param int $page
     * @param int $perPage
     * @param array $filters
     * @param array $sort
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function paginate(int $page = 1, int $perPage = 10, array $filters = [], array $sort = []): LengthAwarePaginator;


    public function isAny(callable $criteria): bool;
    
    public function getList(callable $predicate);

    public function getOne(callable $predicate);
}
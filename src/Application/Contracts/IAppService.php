<?php

namespace Incoder\DDD\Application\Contracts;

use Incoder\DDD\Application\DTOs\PaginatedDTOBase;
use Incoder\DDD\Application\DTOs\ResultData;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Interface IAppService
 *
 * Defines the contract for Spatie-based AppServices.
 *
 * @template T of Data
 */
interface IAppService
{
    /**
     * Get all entities mapped to Data DTOs.
     */
    public function getAll(): DataCollection;

    /**
     * Get a single entity by ID as Data DTO.
     */
    public function getById(mixed $id): ?ResultData;

    /**
     * Create a new entity.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ResultData;

    /**
     * Create a new entity or return the first existing one.
     *
     * @param  array<string, mixed>  $data
     */
    public function createOrFirst(array $attributes, array $values = []): ResultData;

    /**
     * Update an existing entity.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(mixed $id, array $data): ResultData;

    /**
     * Delete an entity.
     */
    public function delete(mixed $id): bool;

    /**
     * Paginate entities.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $sort
     */
    public function getPaged(
        int $page = 1,
        int $perPage = 10,
        array $filters = [],
        array $sort = []
    ): PaginatedDTOBase;
}

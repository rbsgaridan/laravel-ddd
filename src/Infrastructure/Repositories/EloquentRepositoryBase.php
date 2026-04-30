<?php

namespace Incoder\DDD\Infrastructure\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Incoder\DDD\Domain\Entities\AggregateRoot;
use Incoder\DDD\Domain\Entities\Entity;
use Incoder\DDD\Domain\Repositories\IRepository;
use InvalidArgumentException;

/**
 * Class EloquentRepositoryBase
 *
 * This class implements the IRepository interface using Eloquent ORM.
 * It provides methods for basic CRUD operations on entities.
 * It is designed to work with Eloquent models that extend the Entity class.
 * The model class is specified in the constructor and is used to perform database operations.
 *
 * @template T of Entity | AggregateRoot
 *
 * @implements IRepository<T>
 */
abstract class EloquentRepositoryBase implements IRepository
{
    /**
     * The model class that this repository will manage.
     */
    protected string $modelClass;

    /**
     * EloquentRepository constructor.
     *
     * @param  string  $modelClass  The fully qualified class name of the Eloquent model.
     */
    public function __construct(string $modelClass)
    {
        if (! is_subclass_of($modelClass, Entity::class)) {
            throw new InvalidArgumentException('Model class must extend Entity');
        }
        $this->modelClass = $modelClass;
    }

    /**
     * Find an entity by its ID.
     */
    public function find(string $id): ?Entity
    {
        return $this->modelClass::find($id);
    }

    /**
     * Save an entity to the database.
     *
     * @throws \Exception if the entity fails to save.
     */
    public function save(Entity $entity): Entity
    {
        if (! $entity->save()) {
            throw new \Exception('Failed to save entity');
        }

        return $entity->refresh();
    }

    /**
     * Create a new entity or return the first existing one using Laravel's built-in firstOrCreate().
     *
     * @param  array  $attributes  Attributes to check for existing record.
     * @param  array  $values  Values to use when creating a new record.
     */
    public function createOrFirst(array $attributes, array $values = []): Entity
    {
        return $this->modelClass::firstOrCreate($attributes, $values);
    }

    /**
     * Update an existing entity by its ID.
     *
     * @return bool
     *
     * @throws \Exception if the entity is not found.
     */
    public function update(mixed $id, Entity $entity)
    {
        $existingEntity = $this->modelClass::find($id);
        if (! $existingEntity) {
            throw new \Exception('Entity not found');
        }

        $existingEntity->fill($entity->getAttributes());

        if (! $existingEntity->save()) {
            throw new \Exception('Failed to update entity');
        }

        return $existingEntity->refresh();
    }

    /**
     * Delete an entity by its ID.
     *
     * @throws \Exception if the entity is not found.
     */
    public function delete(mixed $id): bool
    {
        $entity = $this->modelClass::find($id);
        if (! $entity) {
            throw new \Exception("Entity with ID {$id} not found");
        }

        return $entity->delete();
    }

    /**
     * Find all entities in the repository.
     */
    public function findAll(): Collection
    {
        return $this->modelClass::all();
    }

    /**
     * Count all entities in the repository.
     */
    public function count(): int
    {
        return $this->modelClass::count();
    }

    /**
     * Find entities by criteria.
     */
    public function findBy(array $criteria): Collection
    {
        $query = $this->modelClass::query();

        foreach ($criteria as $field => $value) {
            $query->where($field, $value);
        }

        return $query->get();
    }

    /**
     * Find entities by criteria.
     *
     * @return Collection
     */
    public function findOneBy(array $criteria): ?Entity
    {
        return $this->findBy($criteria)->first();
    }

    /**
     * Paginate the results with filters and sorting.
     */
    public function paginate(int $page = 1, int $perPage = 15, array $filters = [], array $sort = []): LengthAwarePaginator
    {
        $query = $this->modelClass::query();

        foreach ($filters as $field => $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $query->where($field, 'LIKE', "%{$value}%");
            } else {
                $query->where($field, $value);
            }
        }

        if (! empty($sort)) {
            foreach ($sort as $field => $direction) {
                $query->orderBy($field, $direction ?? 'asc');
            }
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Check if any entity exists matching the given criteria.
     *
     * @param  array  $criteria
     * @return bool
     *
     * Example usage:
     * $repository->isAny(function($query) {
     *     $query->where('salary_grade_name', 'Salary Grade 1');
     * });
     *
     * * // Or using a lambda:
     * $repository->isAny(fn($query) => $query->where('salary_grade_name', 'Salary Grade 1'));
     */
    public function isAny(callable $criteria): bool
    {
        $query = $this->modelClass::query();
        $criteria($query); // Let the closure modify the query

        return $query->exists();
    }

    public function getList(callable $predicate)
    {
        $query = $this->modelClass::query();
        $predicate($query);

        return $query;
    }

    public function getOne(callable $predicate)
    {
        $query = $this->modelClass::query();
        $predicate($query);

        return $query->first();
    }
}

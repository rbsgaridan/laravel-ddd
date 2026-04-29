<?php

namespace Incoder\DDD\Application\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Access\AuthorizationException;
use Incoder\DDD\Application\DTOs\ResultData;
use Incoder\DDD\Application\Contracts\IAppService;
use Incoder\DDD\Application\DTOs\DTOBase;
use Incoder\DDD\Domain\Entities\Entity;
use Incoder\DDD\Support\Attributes\AppServiceMiddleware;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Incoder\DDD\Application\DTOs\PaginatedDTOBase;
use Illuminate\Contracts\Auth\Guard; // or Factory if needed

/**
 * Class AppServiceBase
 *
 * Provides a base implementation for application services.
 * 
 * @template T of Entity
 * @implements IAppService<T>
 */
#[AppServiceMiddleware(['api', 'auth:sanctum'])]
abstract class AppServiceBase implements IAppService
{
    /**
     * @var<T>
     */
    protected mixed $repository;

    protected LoggerInterface $logger;

    /** @var class-string<T> */
    protected string $modelClass;

    /** @var class-string<Data> */
    protected string $modelDtoClass;

    /** @var class-string<DataCollection> */
    protected string $modelListDtoClass;

    /** @var class-string<DTOBase> */
    protected string $modelPaginatedClass;

    protected Guard $auth;

    protected ?string $getPolicyName = null;
    protected ?string $getListPolicyName = null;
    protected ?string $createPolicyName = null;
    protected ?string $updatePolicyName = null;
    protected ?string $deletePolicyName = null;


    /**
     * Summary of __construct
     * @param mixed $repository
     * @param string $modelClass
     * @param string $modelDtoClass
     * @param string $modelListDtoClass
     * @param string $modelPaginatedClass
     * @param mixed $logger
     * @param mixed $auth
     */
    public function __construct(
        $repository,
        string $modelClass,
        string $modelDtoClass,
        string $modelListDtoClass,
        string $modelPaginatedClass,
        Guard $auth,
        ?LoggerInterface $logger = null,
    ) {
        $this->repository = $repository;
        $this->modelClass = $modelClass;
        $this->modelDtoClass = $modelDtoClass;
        $this->modelListDtoClass = $modelListDtoClass;
        $this->modelPaginatedClass = $modelPaginatedClass;
        $this->logger = $logger ?? new NullLogger();
        $this->auth = $auth;
    }

    private function logActivity(string $description, Entity $entity, string $logName)
    {
        activity()
            ->performedOn($entity)
            ->causedBy($this->auth->user())
            ->useLog($logName)
            ->withProperties($entity)
            ->createdAt(now())
            ->log($description);
    }

    /**
     * Summary of getAll
     * @return DataCollection
     */
    
    public function getAll(): DataCollection
    {
        if ($this->getListPolicyName && ! $this->auth->user()?->can($this->getListPolicyName)) {
            throw new AuthorizationException("Unauthorized: [{$this->getListPolicyName}]");
        }

        return new DataCollection($this->modelListDtoClass, $this->repository->findAll()->all());
    }

    /**
     * Summary of getById
     * @param mixed $id
     * @return ResultData|null
     */
    public function getById(mixed $id): ?ResultData
    {
        if ($this->getPolicyName && ! $this->auth->user()?->can($this->getPolicyName)) {
            throw new AuthorizationException("Unauthorized: [{$this->getPolicyName}]");
        }

        $entity = $this->repository->find($id);
        return $entity ? new ResultData($entity, $this->modelDtoClass::from($entity->toArray())) : null;
    }

    /**
     * Create a new entity, optionally handling uploaded files via Spatie Media Library.
     *
     * This method is generic and works with any entity and its corresponding DTO.
     * If the $data array contains any UploadedFile instances, they will be added
     * to the specified media collection after the entity is saved.
     *
     * @param array $data        Key-value array of entity attributes. May include UploadedFile instances.
     * @return ResultData  Contains both the saved entity and its DTO.
     */
    public function create(array $data): ResultData
    {
        if ($this->createPolicyName && ! $this->auth->user()?->can($this->createPolicyName)) {
            throw new AuthorizationException("Unauthorized: [{$this->createPolicyName}]");
        }

        $entity = new $this->modelClass($data);
        $entityRepository = $this->repository->save($entity);
        // Build DTO from the saved entity so fields like `id` are present
        $dto = $this->modelDtoClass::from($entityRepository->toArray());
        return new ResultData($entityRepository, $dto);
    }

    /**
     * Create a new entity or return the first existing one using Laravel's built-in firstOrCreate().
     *
     * This method is generic and works with any entity and its corresponding DTO.
     * It ensures that duplicate records (based on given attributes) are not created.
     *
     * @param  array  $attributes  Attributes to check for existing record.
     * @param  array  $values      Values to use when creating a new record.
     * @return ResultData  Contains both the saved or existing entity and its DTO.
     */
    public function createOrFirst(array $attributes, array $values = []): ResultData
    {
        if ($this->createPolicyName && ! $this->auth->user()?->can($this->createPolicyName)) {
            throw new AuthorizationException("Unauthorized: [{$this->createPolicyName}]");
        }

        $entity = $this->repository->createOrFirst($attributes, $values);
        $dto = $this->modelDtoClass::from($entity->toArray());
        return new ResultData($entity, $dto);
    }

    /**
     * Summary of update
     * @param mixed $id
     * @param array $data
     * @return ResultData
     */
    public function update(mixed $id, array $data): ResultData
    {
        if ($this->updatePolicyName && ! $this->auth->user()?->can($this->updatePolicyName)) {
            throw new AuthorizationException("Unauthorized: [{$this->updatePolicyName}]");
        }

        $entity = new $this->modelClass($data);
        $updatedEntity = $this->repository->update($id, $entity);
        // Build DTO from the updated entity returned by repository to ensure all persisted fields are included
        return new ResultData($updatedEntity, $this->modelDtoClass::from($updatedEntity->toArray()));
    }

    /**
     * Summary of delete
     * @param mixed $id
     * @return bool
     */
    public function delete(mixed $id): bool
    {
        if ($this->deletePolicyName && ! $this->auth->user()?->can($this->deletePolicyName)) {
            throw new AuthorizationException("Unauthorized: [{$this->deletePolicyName}]");
        }

        return $this->repository->delete($id);
    }

    /**
     * Summary of getPaged
     * @param int $page
     * @param int $perPage
     * @param array $filters
     * @param array $sort
     * @return PaginatedDTOBase
     */
    public function getPaged(
        int $page = 1,
        int $perPage = 10,
        array $filters = [],
        array $sort = []
    ): PaginatedDTOBase {
        if ($this->getListPolicyName && ! $this->auth->user()?->can($this->getListPolicyName)) {
            throw new AuthorizationException("Unauthorized: [{$this->getListPolicyName}]");
        }

        $paginator = $this->repository->paginate($page, $perPage, $filters, $sort);
        return $this->modelPaginatedClass::fromPaginator($paginator, $this->modelListDtoClass);
    }

    /**
     * Summary of logRequest
     * @param string $requestName
     * @param array $parameters
     * @return void
     */
    protected function logRequest(string $requestName, array $parameters = []): void
    {
        $this->logger->info("Request: $requestName", $parameters);
    }
}

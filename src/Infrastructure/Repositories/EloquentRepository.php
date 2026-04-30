<?php

namespace Incoder\DDD\Infrastructure\Repositories;

use Incoder\DDD\Domain\Repositories\IRepository;

class EloquentRepository extends EloquentRepositoryBase implements IRepository
{
    public function __construct(string $modelClass)
    {
        parent::__construct($modelClass);
    }
}

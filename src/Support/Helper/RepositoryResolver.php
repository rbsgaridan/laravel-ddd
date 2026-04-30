<?php

namespace Incoder\DDD\Support\Helper;

use Incoder\DDD\Domain\Repositories\IRepository;
use Incoder\DDD\Infrastructure\Repositories\EloquentRepository;

class RepositoryResolver
{
    /**
     * Resolves the repository for a given model class.
     *
     * @param  string  $modelClass  The fully qualified class name of the model.
     * @return IRepository The repository instance for the specified model.
     *
     * @usage
     * $repository = RepositoryResolver::for(\App\Models\User::class);
     */
    public static function for(string $modelClass): IRepository
    {
        return new EloquentRepository($modelClass);
    }
}

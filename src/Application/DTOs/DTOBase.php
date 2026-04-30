<?php

namespace Incoder\DDD\Application\DTOs;

use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Base class for Data Transfer Objects (DTOs).
 *
 * Extend this in your DTOs if you want to keep a common base type.
 * Otherwise, you can extend Spatie's Data directly.
 */
abstract class DTOBase extends Data
{
    /**
     * Summary of toDTO
     *
     * @return DTOBase
     */
    public static function toDTO(Model $model): static
    {
        return static::from($model->toArray());
    }

    /**
     * Summary of toDTOs
     *
     * @return DataCollection|static[]
     */
    public static function toDTOs(iterable $models)
    {
        return static::collect(
            collect($models)->map(fn ($m) => static::toDTO($m))
        );
    }
}

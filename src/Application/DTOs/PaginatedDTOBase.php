<?php

namespace Incoder\DDD\Application\DTOs;

use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Summary of PaginatedDTOBase
 *
 * @template T
 *
 * @phpstan-consistent-constructor
 */
abstract class PaginatedDTOBase extends Data
{
    /**
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public DataCollection $data,
        public array $meta
    ) {}

    /**
     * Summary of fromPaginator
     *
     * @return PaginatedDTOBase
     */
    public static function fromPaginator(
        LengthAwarePaginator $paginator,
        string $dtoClass
    ): static {

        $collection = new DataCollection($dtoClass, $dtoClass::collect($paginator->items()));

        return new static(
            $collection,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]
        );
    }
}

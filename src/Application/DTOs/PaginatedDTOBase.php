<?php

namespace Incoder\DDD\Application\DTOs;

use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Data;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Summary of PaginatedDTOBase
 * @template T
 */
abstract class PaginatedDTOBase extends Data
{
    /**
     * @param DataCollection $data
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public DataCollection $data,
        public array $meta
    ) {}

    /**
     * Summary of fromPaginator
     * @param \Illuminate\Pagination\LengthAwarePaginator $paginator
     * @param string $dtoClass
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
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ]
        );
    }

}

<?php

namespace Incoder\DDD\Support\Attributes\OpenApi;

use Attribute;

/**
 * Declares an HTTP response for a route. Repeatable — add one per status code.
 *
 * Usage:
 *   #[ApiResponse(200, 'Returns paginated user list', UserPaginatedDTO::class)]
 *   #[ApiResponse(404, 'User not found')]
 *   #[ApiResponse(422, 'Validation error')]
 *   public function index() { ... }
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiResponse
{
    public function __construct(
        public int $statusCode,
        public string $description,
        /** @var class-string|null  DTO class to use as the response schema */
        public ?string $class = null
    ) {}
}

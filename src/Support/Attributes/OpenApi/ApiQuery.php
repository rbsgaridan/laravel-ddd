<?php

namespace Incoder\DDD\Support\Attributes\OpenApi;

use Attribute;

/**
 * Adds or overrides a query string parameter in the OpenAPI spec.
 * Repeatable — add one per query param.
 *
 * Usage:
 *   #[ApiQuery('search', 'string', 'Search keyword', required: false)]
 *   #[ApiQuery('per_page', 'integer', 'Items per page', required: false)]
 *   public function index(Request $request) { ... }
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiQuery
{
    public function __construct(
        public string $name,
        public string $type = 'string',
        public string $description = '',
        public bool $required = false,
        public mixed $example = null
    ) {}
}

<?php

namespace Incoder\DDD\Support\Attributes\OpenApi;

use Attribute;

/**
 * Adds or overrides a path parameter description in the OpenAPI spec.
 * Path params are auto-detected from {param} in the route URI, but use this
 * to override type, description, or example.
 * Repeatable — add one per path param.
 *
 * Usage:
 *   #[ApiParam('id', 'string', 'UUID of the user', example: '550e8400-e29b-41d4-a716-446655440000')]
 *   public function show(string $id) { ... }
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiParam
{
    public function __construct(
        public string $name,
        public string $type = 'string',
        public string $description = '',
        public mixed $example = null
    ) {}
}

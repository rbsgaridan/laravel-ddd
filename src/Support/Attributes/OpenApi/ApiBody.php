<?php

namespace Incoder\DDD\Support\Attributes\OpenApi;

use Attribute;

/**
 * Declares the expected JSON request body for a route, referencing a DTO class.
 * The DTO's public properties (or constructor params) are introspected to build
 * the JSON Schema automatically.
 *
 * Usage:
 *   #[ApiBody(UserCreateDTO::class)]
 *   public function store(Request $request) { ... }
 *
 *   #[ApiBody(UserCreateDTO::class, description: 'New user payload', required: true)]
 *   public function store(Request $request) { ... }
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ApiBody
{
    public function __construct(
        /** @var class-string  DTO class for schema inference */
        public string $class,
        public string $description = '',
        public bool $required = true
    ) {}
}

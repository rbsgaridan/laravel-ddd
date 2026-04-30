<?php

namespace Incoder\DDD\Support\Attributes\OpenApi;

use Attribute;

/**
 * Overrides the auto-inferred summary and description for a route operation.
 *
 * Usage:
 *   #[ApiOperation('List all users', description: 'Returns a paginated list of all active employees.')]
 *   public function index(Request $request) { ... }
 *
 *   #[ApiOperation('Create user', tags: ['Users', 'Admin'], deprecated: true)]
 *   public function store(Request $request) { ... }
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ApiOperation
{
    public function __construct(
        public string $summary,
        public string $description = '',
        /** @var string[] */
        public array $tags = [],
        public bool $deprecated = false
    ) {}
}

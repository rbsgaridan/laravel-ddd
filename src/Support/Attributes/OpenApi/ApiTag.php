<?php

namespace Incoder\DDD\Support\Attributes\OpenApi;

use Attribute;

/**
 * Groups all routes from this controller under a named Swagger tag.
 *
 * Usage:
 *   #[ApiTag('Users', 'Operations related to user management')]
 *   class UserController extends Controller { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ApiTag
{
    public function __construct(
        public string $name,
        public string $description = ''
    ) {}
}

<?php

namespace Incoder\DDD\Support\Attributes\OpenApi;

use Attribute;

/**
 * Overrides the security requirement for a route or entire controller.
 * By default, routes with 'auth' or 'auth:sanctum' middleware inherit
 * the 'sanctum' security scheme automatically.
 *
 * Pass an empty array to mark the endpoint as public (no security).
 *
 * Usage:
 *   #[ApiSecurity(['sanctum'])]          // require bearer token
 *   #[ApiSecurity([])]                   // explicitly public
 *   class UserController extends Controller { ... }
 *
 *   #[ApiSecurity(['sanctum', 'apiKey'])]
 *   public function sensitiveAction() { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class ApiSecurity
{
    /**
     * @param string[] $schemes  Names matching keys in config('api-docs.security_schemes')
     */
    public function __construct(
        public array $schemes = ['sanctum']
    ) {}
}

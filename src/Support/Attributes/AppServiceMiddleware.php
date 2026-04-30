<?php

namespace Incoder\DDD\Support\Attributes;

use Attribute;

/**
 * Sets the default middleware for ALL routes auto-registered from an AppService.
 *
 * Applied at the class level.  Individual method middleware can still be
 * overridden with #[RouteAttribute] or #[RequiresPermission].
 *
 * Usage
 * -----
 * // Require Sanctum auth (accepts browser cookie OR Bearer token)
 * #[AppServiceMiddleware(['auth:sanctum'])]
 * class UserAppService extends AppServiceBase { ... }
 *
 * // Web-only session auth
 * #[AppServiceMiddleware(['web', 'auth'])]
 * class ReportAppService extends AppServiceBase { ... }
 *
 * // No auth (public)
 * #[AppServiceMiddleware(['api'])]
 * class PublicJobAppService extends AppServiceBase { ... }
 *
 * Defaults to ['auth:sanctum'] when not specified (AppServices are internal APIs).
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AppServiceMiddleware
{
    public readonly array $middleware;

    /**
     * @param  string[]  $middleware  Middleware list to apply to every route in this AppService.
     */
    public function __construct(array $middleware = ['auth:sanctum'])
    {
        $this->middleware = $middleware;
    }
}

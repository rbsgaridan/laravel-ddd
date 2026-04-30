<?php

namespace Incoder\DDD\Support\Attributes;

use Attribute;

/**
 * Adds Spatie permission middleware to an AppService class or individual method.
 *
 * Class-level:  applies to every auto-registered route (CRUD + convention + attributed).
 * Method-level: overrides the class-level permission for that specific route.
 *
 * Under the hood this injects the Spatie `permission:{name}` middleware.
 * Multiple permissions perform an OR check (the user needs ANY of them).
 *
 * Usage
 * -----
 * // Class-level default — all routes need 'users.view'
 * #[AppServiceMiddleware(['auth:sanctum'])]
 * #[RequiresPermission('users.view')]
 * class UserAppService extends AppServiceBase
 * {
 *     // Uses class-level 'users.view'
 *     public function getAll() { ... }
 *
 *     // Overrides to 'users.manage' for this route only
 *     #[RequiresPermission('users.manage')]
 *     public function create(UserDTO $dto) { ... }
 *
 *     // No permission required for this route (still needs auth from class middleware)
 *     #[RequiresPermission]
 *     public function getById(string $id) { ... }
 * }
 *
 * Multiple permissions (OR logic — user needs at least one):
 * #[RequiresPermission('users.export', 'admin.superuser')]
 * public function export() { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RequiresPermission
{
    /** @var string[] */
    public readonly array $permissions;

    /**
     * @param  string  ...$permissions  One or more Spatie permission names.
     *                                  Pass no arguments to explicitly mark as "no permission check".
     */
    public function __construct(string ...$permissions)
    {
        $this->permissions = $permissions;
    }

    /**
     * Convert to Spatie permission middleware string(s).
     *
     * Multiple permissions become a single middleware with | separator
     * (Spatie's OR syntax): "permission:users.view|admin.superuser"
     *
     * @return string[] Middleware strings to append.
     */
    public function toMiddleware(): array
    {
        if (empty($this->permissions)) {
            return [];
        }

        return ['permission:'.implode('|', $this->permissions)];
    }
}

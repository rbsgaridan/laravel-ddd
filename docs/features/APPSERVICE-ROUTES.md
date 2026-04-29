# AppService Auto Route Registration

`incoder-ddd` automatically discovers every class in `Core\Application\**\*AppService` and
registers HTTP routes for it — no manual entries in `routes/web.php` or `routes/api.php`
required.

---

## Table of Contents

1. [How Discovery Works](#how-discovery-works)
2. [Base URI Convention](#base-uri-convention)
3. [Pattern 1 — CRUD Methods](#pattern-1--crud-methods)
4. [Pattern 2 — `#[RouteAttribute]` on Custom Methods](#pattern-2--routeattribute-on-custom-methods)
5. [Pattern 3 — HTTP-Prefix Convention](#pattern-3--http-prefix-convention)
6. [Parameter Binding](#parameter-binding)
7. [Authentication & Middleware](#authentication--middleware)
8. [Permissions](#permissions)
9. [Filtered Queries — `createFilteredQuery` / `whereIf`](#filtered-queries)
10. [Quick Reference](#quick-reference)

---

## How Discovery Works

At boot the `IncoderDDDServiceProvider` reads Composer's `autoload_classmap.php` and passes
it to `AppServiceRouteScanner`. The scanner filters for classes that satisfy **all** of:

| Condition | Example |
|---|---|
| Namespace starts with `Core\Application\` | `Core\Application\Users\UserAppService` |
| Class name ends with `AppService` | `UserAppService` |
| Not an interface (no leading `I`) | ~~`IUserAppService`~~ |
| Class exists and extends `AppServiceBase` | `class UserAppService extends AppServiceBase` |

After finding a matching class, `RouteRegistrar` registers up to three kinds of routes for it.

---

## Base URI Convention

```
/app/api/{servicename}
```

`{servicename}` is the short class name with `AppService` stripped, lowercased:

| Class | Base URI |
|---|---|
| `UserAppService` | `/app/api/user` |
| `JobPositionAppService` | `/app/api/job-position` |
| `PdsVersionAppService` | `/app/api/pds-version` |

---

## Pattern 1 — CRUD Methods

Any of the following **standard method names** on an AppService are automatically registered:

| Method | HTTP | Path | Route name |
|---|---|---|---|
| `getAll()` | `GET` | `/app/api/{entity}` | `app.api.{entity}.index` |
| `getPaged()` | `GET` | `/app/api/{entity}/paged` | `app.api.{entity}.paged` |
| `getById($id)` | `GET` | `/app/api/{entity}/{id}` | `app.api.{entity}.show` |
| `create(...)` | `POST` | `/app/api/{entity}` | `app.api.{entity}.store` |
| `update($id, ...)` | `PUT` | `/app/api/{entity}/{id}` | `app.api.{entity}.update` |
| `delete($id)` | `DELETE` | `/app/api/{entity}/{id}` | `app.api.{entity}.destroy` |

`{entity}` is the kebab-case class name with `AppService` stripped — e.g. `UserAppService` → `user`, `JobPositionAppService` → `job-position`.

Only methods that **exist** on the class are registered — you can implement any subset.

```php
class UserAppService extends AppServiceBase implements IUserAppService
{
    // Registers:
    // GET    /app/api/user           → app.api.user.index
    // GET    /app/api/user/paged     → app.api.user.paged
    // GET    /app/api/user/{id}      → app.api.user.show
    // POST   /app/api/user           → app.api.user.store
    // PUT    /app/api/user/{id}      → app.api.user.update
    // DELETE /app/api/user/{id}      → app.api.user.destroy
}
```

---

## Pattern 2 — `#[RouteAttribute]` on Custom Methods

Custom public methods decorated with `#[RouteAttribute]` get their route exactly as specified.
All `#[Api*]` OpenAPI override attributes work identically to controller methods.

```php
use Incoder\DDD\Support\Attributes\RouteAttribute;
use Incoder\DDD\Support\Attributes\OpenApi\ApiOperation;
use Incoder\DDD\Support\Attributes\OpenApi\ApiResponse;

class UserAppService extends AppServiceBase implements IUserAppService
{
    // GET /app/api/user/pending-approval
    #[RouteAttribute('GET', 'pending-approval', 'user.pending', ['auth:sanctum'])]
    #[ApiOperation('List users pending approval')]
    #[ApiResponse(200, 'Pending users', UserListDTO::class)]
    public function getPendingApproval(): DataCollection { ... }

    // POST /app/api/user/{id}/archive
    #[RouteAttribute('POST', '{id}/archive', 'user.archive', ['auth:sanctum'])]
    public function archive(string $id): bool { ... }
}
```

> **Note:** `#[RouteAttribute]` methods carry their **own explicit middleware list** and are
> not affected by `#[AppServiceMiddleware]` on the class.

---

## Pattern 3 — HTTP-Prefix Convention

Public methods **not** already handled by Pattern 1 or 2 are matched against HTTP verb
prefixes. The prefix is stripped, the remainder is converted to `kebab-case`, and optional
path parameters are appended.

```
getActiveByCampus(string $campus)
│── prefix  : "get"  → HTTP GET
│── remainder: "ActiveByCampus" → "active-by-campus"
│── $campus appears in remainder → path param
│── URI:  /app/api/employee/active-by-campus/{campus}
└── name: api.employee.active-by-campus/{campus}
```

Supported prefixes: `get` `post` `put` `patch` `delete` `options` `head`

```php
class EmployeeAppService extends AppServiceBase
{
    // GET /app/api/employee/active-by-campus/{campus}  → api.employee.active-by-campus/{campus}
    public function getActiveByCampus(string $campus): DataCollection { ... }

    // POST /app/api/employee/archive/{id}  → api.employee.archive/{id}
    public function postArchive(#[FromUri] string $id): bool { ... }

    // GET /app/api/employee/search         → api.employee.search   ($q is a query string param)
    public function getSearch(#[FromQuery] string $q): DataCollection { ... }

    // GET /app/api/employee/export/{format}  → api.employee.export/{format}
    public function getExport(string $format = 'csv'): Response { ... }
}
```

---

## Parameter Binding

How a method parameter is placed in the URL is resolved in this order:

| Attribute on parameter | Behaviour |
|---|---|
| `#[FromUri]` | Always a path segment `/{param}` |
| `#[FromQuery]` | Query string `?param=value` — never a path segment |
| `#[FromBody]` | Request body — never a path segment |
| *(none)* | Path segment **only if** the parameter name appears inside the base method name (case-insensitive). Otherwise treated as query/body. |

```php
// "id" appears in "ById" → path param
public function getById(string $id): ?UserDTO  // → /{id}

// "search" does NOT appear in "ActiveUsers" → query string
public function getActiveUsers(string $search): DataCollection  // → ?search=

// explicit override
public function postArchive(#[FromUri] string $userId): bool  // → /{userId}
```

---

## Authentication & Middleware

### `#[AppServiceMiddleware]`  *(class-level)*

Sets the middleware for every CRUD and HTTP-prefix convention route in the AppService.
Defaults to `['api']` (public) when the attribute is absent.

```php
use Incoder\DDD\Support\Attributes\AppServiceMiddleware;

// Accepts Bearer token (mobile) OR session cookie (browser SPA)
#[AppServiceMiddleware(['auth:sanctum'])]
class UserAppService extends AppServiceBase { ... }

// Session cookie only
#[AppServiceMiddleware(['web', 'auth'])]
class ReportAppService extends AppServiceBase { ... }

// Explicitly public
#[AppServiceMiddleware(['api'])]
class PublicJobsAppService extends AppServiceBase { ... }
```

> `#[RouteAttribute]` methods always use their own middleware list — they are unaffected.

---

## Permissions

### `#[RequiresPermission]`  *(class or method level)*

Appends a Spatie `permission:{name}` middleware check. **Method-level overrides
class-level.**

```php
use Incoder\DDD\Support\Attributes\AppServiceMiddleware;
use Incoder\DDD\Support\Attributes\RequiresPermission;

#[AppServiceMiddleware(['auth:sanctum'])]
#[RequiresPermission('users.view')]          // default for all routes
class UserAppService extends AppServiceBase implements IUserAppService
{
    // ✅ inherits class-level 'users.view'
    public function getAll(): DataCollection { ... }

    // ✅ overridden to 'users.manage'
    #[RequiresPermission('users.manage')]
    public function create(array $data): ResultData { ... }

    // ✅ OR logic — user needs at least one
    #[RequiresPermission('users.manage', 'admin.superuser')]
    public function delete(string $id): bool { ... }

    // ✅ no permission check (auth still required by class middleware)
    #[RequiresPermission]
    public function getMyProfile(string $id): ResultData { ... }
}
```

### Policy-based auth (built into `AppServiceBase`)

Set any of these protected properties in your AppService to enforce
`can()` checks at the service method level (independent of route middleware):

```php
class UserAppService extends AppServiceBase implements IUserAppService
{
    protected ?string $getPolicyName     = 'users.view';    // getById()
    protected ?string $getListPolicyName = 'users.view';    // getAll(), getPaged()
    protected ?string $createPolicyName  = 'users.manage';  // create(), createOrFirst()
    protected ?string $updatePolicyName  = 'users.manage';  // update()
    protected ?string $deletePolicyName  = 'users.manage';  // delete()
}
```

An `AuthorizationException` (→ HTTP 403) is thrown when the authenticated user does not
have the required permission.

---

## Filtered Queries

`AppServiceBase` provides an **ABP-equivalent of `CreateFilteredQueryAsync`** — a protected
hook that controls the Eloquent query used by `getAll()` and `getPaged()`.

### `createFilteredQuery(Builder $query, array $filters): Builder`

Override this method to define custom filtering logic. The result is passed directly to the
repository — the default simple `LIKE` / exact-match logic is only used when you do **not**
override.

```php
use Illuminate\Database\Eloquent\Builder;

class RiskEventAppService extends AppServiceBase implements IRiskEventAppService
{
    protected function createFilteredQuery(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                !empty($filters['search']),
                fn ($q) => $q->where(function ($q) use ($filters) {
                    $q->where('event_title',       'LIKE', "%{$filters['search']}%")
                      ->orWhere('event_description', 'LIKE', "%{$filters['search']}%");
                })
            )
            ->when(
                isset($filters['status']),
                fn ($q) => $q->where('status', $filters['status'])
            )
            ->when(
                isset($filters['occurrence_date_from']),
                fn ($q) => $q->where('occurrence_date', '>=', $filters['occurrence_date_from'])
            )
            ->when(
                isset($filters['occurrence_date_to']),
                fn ($q) => $q->where('occurrence_date', '<=', $filters['occurrence_date_to'])
            );
    }
}
```

### `static whereIf(Builder $query, bool $condition, Closure $callback): Builder`

A direct port of ABP's `.WhereIf()` — applies a constraint only when the condition is true.

```php
protected function createFilteredQuery(Builder $query, array $filters): Builder
{
    static::whereIf($query, !empty($filters['search']),
        fn ($q) => $q->where('name', 'LIKE', "%{$filters['search']}%"));

    static::whereIf($query, isset($filters['is_active']),
        fn ($q) => $q->where('is_active', $filters['is_active']));

    static::whereIf($query, isset($filters['campus_id']),
        fn ($q) => $q->where('campus_id', $filters['campus_id']));

    return $query;
}
```

### Eager-loading, scopes, and ordering

`createFilteredQuery` gives you full Builder access, so you can also add eager loads,
apply scopes, or override the default sort:

```php
protected function createFilteredQuery(Builder $query, array $filters): Builder
{
    return $query
        ->with(['department', 'position'])
        ->whereHas('department', fn ($q) => $q->where('is_active', true))
        ->when(empty($filters), fn ($q) => $q->latest());
}
```

---

## Quick Reference

```php
use Incoder\DDD\Application\Services\AppServiceBase;
use Incoder\DDD\Support\Attributes\AppServiceMiddleware;
use Incoder\DDD\Support\Attributes\RequiresPermission;
use Incoder\DDD\Support\Attributes\RouteAttribute;
use Incoder\DDD\Support\Attributes\FromUri;
use Incoder\DDD\Support\Attributes\FromQuery;
use Illuminate\Database\Eloquent\Builder;

#[AppServiceMiddleware(['auth:sanctum'])]   // 1. auth for all routes
#[RequiresPermission('employees.view')]    // 2. default permission
class EmployeeAppService extends AppServiceBase implements IEmployeeAppService
{
    // ── Policy guards (service-layer, independent of route middleware) ──────
    protected ?string $getListPolicyName = 'employees.view';
    protected ?string $createPolicyName  = 'employees.manage';
    protected ?string $updatePolicyName  = 'employees.manage';
    protected ?string $deletePolicyName  = 'employees.manage';

    // ── CRUD (Pattern 1) — auto-registered, no annotation needed ────────────
    // GET    /app/api/employee        → app.api.employee.index
    // GET    /app/api/employee/paged  → app.api.employee.paged
    // GET    /app/api/employee/{id}   → app.api.employee.show
    // POST   /app/api/employee        → app.api.employee.store
    // PUT    /app/api/employee/{id}   → app.api.employee.update
    // DELETE /app/api/employee/{id}   → app.api.employee.destroy

    // ── Custom attributed route (Pattern 2) ─────────────────────────────────
    #[RouteAttribute('POST', '{id}/transfer', 'employee.transfer', ['auth:sanctum'])]
    #[RequiresPermission('employees.transfer')]
    public function transfer(string $id, array $data): ResultData { ... }

    // ── HTTP-prefix convention (Pattern 3) ──────────────────────────────────
    // GET /app/api/employee/active-by-campus/{campus}
    public function getActiveByCampus(string $campus): DataCollection { ... }

    // GET /app/api/employee/search   ($q is a query string param)
    public function getSearch(#[FromQuery] string $q): DataCollection { ... }

    // ── Custom filtered query ────────────────────────────────────────────────
    protected function createFilteredQuery(Builder $query, array $filters): Builder
    {
        return $query
            ->with('department')
            ->when(
                !empty($filters['search']),
                fn ($q) => $q->where('last_name', 'LIKE', "%{$filters['search']}%")
            )
            ->when(
                isset($filters['department_id']),
                fn ($q) => $q->where('department_id', $filters['department_id'])
            )
            ->when(
                isset($filters['is_active']),
                fn ($q) => $q->where('is_active', $filters['is_active'])
            );
    }
}
```

### Attribute cheat sheet

| Attribute | Target | Purpose |
|---|---|---|
| `#[AppServiceMiddleware(['auth:sanctum'])]` | Class | Laravel middleware for all auto-registered routes |
| `#[RequiresPermission('perm')]` | Class or method | Spatie permission check; method-level overrides class |
| `#[RouteAttribute('GET', 'uri', 'name', ['mw'])]` | Method | Explicit route; bypasses class middleware |
| `#[FromUri]` | Parameter | Force path segment |
| `#[FromQuery]` | Parameter | Force query string; never a path segment |
| `#[FromBody]` | Parameter | Request body; never a path segment |

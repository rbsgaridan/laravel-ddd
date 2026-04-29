# Auto OpenAPI Documentation — `incoder-ddd`

Automatic OpenAPI 3.0 documentation for every route registered via `#[RouteAttribute]`, served through an interactive Swagger UI. Inspired by Python's **FastAPI** — zero annotation required to get started, with a full attribute system for fine-grained control.

---

## Table of Contents

1. [How It Works](#how-it-works)
2. [Accessing the Docs](#accessing-the-docs)
3. [Auto-Inference (Zero-Annotation Baseline)](#auto-inference-zero-annotation-baseline)
4. [Override Attributes Reference](#override-attributes-reference)
   - [`#[ApiTag]`](#apitag)
   - [`#[ApiOperation]`](#apioperation)
   - [`#[ApiBody]`](#apibody)
   - [`#[ApiResponse]`](#apiresponse)
   - [`#[ApiQuery]`](#apiquery)
   - [`#[ApiParam]`](#apiparam)
   - [`#[ApiSecurity]`](#apisecurity)
   - [`#[ApiHide]`](#apihide)
5. [AppService Routes](#appservice-routes)
   - [`#[AppServiceMiddleware]`](#appservicemiddleware)
   - [`#[RequiresPermission]`](#requirespermission)
6. [Authentication](#authentication)
7. [DTO Schema Inference](#dto-schema-inference)
8. [Artisan Command](#artisan-command)
9. [Configuration](#configuration)
10. [Full Example](#full-example)
11. [Architecture Reference](#architecture-reference)

---

## How It Works

```
GET /api/docs       ──► SwaggerUiController@ui    ──► Swagger UI HTML (CDN)
GET /api/docs/spec  ──► SwaggerUiController@spec   ──► OpenApiGenerator::generate() ──► OA3 JSON

php artisan api:generate-spec ──► same generator ──► writes to disk
```

On every request to `/api/docs/spec` the generator:

1. Scans `app/Http/Controllers/**/*Controller.php` for classes
2. Reads every public method that carries `#[RouteAttribute]`
3. Extracts path params from the URI, infers the tag from the class name, infers a human-readable summary from the method name
4. Reads any override attributes (`#[ApiBody]`, `#[ApiResponse]`, …) applied to that method or class
5. Introspects referenced DTO classes to produce JSON Schema objects
6. Returns the assembled OpenAPI 3.0.3 object as JSON

---

## Accessing the Docs

| App | URL |
|---|---|
| `jobs-usm` | http://localhost:8001/api/docs |
| `web-admin` | http://localhost:8000/api/docs |

The raw JSON spec is always available at the `/spec` sub-path:

```
http://localhost:8001/api/docs/spec
```

---

## Auto-Inference (Zero-Annotation Baseline)

Every route annotated with `#[RouteAttribute]` is automatically documented **without any additional attributes**. The following are inferred at no cost:

| Property | Inference Rule | Example |
|---|---|---|
| **Tag** | Controller class minus `Controller`, pluralised | `UserController` → `Users` |
| **Summary** | Method name camelCase → Title Words | `showPdsVersions` → `Show Pds Versions` |
| **Path params** | All `{param}` segments in the URI | `/employees/{id}` → `id: string (required)` |
| **Query params** | Method params carrying `#[FromQuery]` | `(#[FromQuery] string $search)` |
| **Security** | Routes with `auth` middleware → `sessionAuth` required | Routes without `auth` → public |
| **OperationId** | `{ControllerShortName}_{methodName}` | `UserController_index` |
| **HTTP methods** | Taken directly from `#[RouteAttribute]` | `['GET']`, `['POST']`, … |

---

## Authentication

### How this app authenticates

This project uses **Laravel session/cookie authentication** (the `web` guard) — the same mechanism used by the Inertia.js frontend. There is **no API bearer token**. Instead:

1. The browser sends credentials to `POST /login`
2. Laravel creates a server-side session and sets two cookies:
   | Cookie | Type | Purpose |
   |---|---|---|
   | `laravel_session` | HttpOnly (invisible to JS) | Identifies the session |
   | `XSRF-TOKEN` | Readable by JS | CSRF protection |
3. Every subsequent request automatically includes `laravel_session` (browser does this)
4. State-mutating requests (`POST`, `PUT`, `PATCH`, `DELETE`) must also include an `X-XSRF-TOKEN` header — the Swagger UI does this automatically

### Using Try It Out in Swagger UI

1. **Log in first** — open `/login` in the same browser tab and authenticate
2. **Return to `/api/docs`** — the session cookie is already there, no paste needed
3. Click **Try it out** on any protected endpoint and hit **Execute**
4. It works — the browser sends `laravel_session` automatically (same-origin request)

> There is **no Authorize button** to click and no token to copy. Session auth is transparent in the browser.

### Making an endpoint require authentication

Add `auth` to the `#[RouteAttribute]` middleware array. The `auth` middleware checks the `web` (session) guard:

```php
// ✅ Requires login — shown with a lock icon in Swagger UI
#[RouteAttribute('GET', 'employees', 'employees.index', ['auth'])]
public function index() { ... }

// ✅ Also valid (explicitly named guard)
#[RouteAttribute('GET', 'employees', 'employees.index', ['auth:sanctum'])]
public function index() { ... }
```

### Making an endpoint anonymous (public)

Omit `auth` from the middleware — use only `web` (or nothing):

```php
// ✅ Public — no lock icon in Swagger UI, no login required
#[RouteAttribute('GET', 'public/stats', 'stats.index', ['web'])]
public function stats() { ... }
```

You can also explicitly mark it public in the docs even if the middleware might vary:

```php
#[RouteAttribute('GET', 'healthcheck', 'health', ['web'])]
#[ApiSecurity([])]   // explicitly public in docs
public function health() { ... }
```

### AppService routes

AppService CRUD and HTTP-prefix convention routes have no auth by default (backward-compatible `api` middleware). Use `#[AppServiceMiddleware]` and `#[RequiresPermission]` to lock them down:

```php
#[AppServiceMiddleware(['auth:sanctum'])]  // auth for whole service
#[RequiresPermission('users.view')]        // default permission
class UserAppService extends AppServiceBase { ... }
```

See [AppService Routes → Security](#security-on-appservice-routes) for full details.

---

## Override Attributes Reference

All attributes live in `Incoder\DDD\Support\Attributes\OpenApi\`.

---

### `#[ApiTag]`

**Target:** Controller class  
**Purpose:** Set the Swagger UI section name and description for all routes in that controller.

```php
use Incoder\DDD\Support\Attributes\OpenApi\ApiTag;

#[ApiTag('Employees', 'HR employee management operations')]
class EmployeeController extends Controller
{
    // All methods in this controller appear under "Employees" in the UI
}
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$name` | `string` | *(required)* | Tag label shown in Swagger UI |
| `$description` | `string` | `''` | Optional longer description |

If omitted, the tag is inferred from the class name.

---

### `#[ApiOperation]`

**Target:** Controller method  
**Purpose:** Override the auto-inferred summary, add a description, override tags, or mark as deprecated.

```php
use Incoder\DDD\Support\Attributes\OpenApi\ApiOperation;

#[ApiOperation(
    summary: 'Create a new employee record',
    description: 'Validates the payload against EmployeeCreateDTO rules, then persists the record.',
    tags: ['Employees', 'Onboarding'],
    deprecated: false
)]
public function store(Request $request) { ... }
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$summary` | `string` | *(required)* | Short one-line description |
| `$description` | `string` | `''` | Longer markdown-supported description |
| `$tags` | `string[]` | `[]` | Override tag(s); falls back to class-level tag |
| `$deprecated` | `bool` | `false` | Renders a "deprecated" badge in the UI |

---

### `#[ApiBody]`

**Target:** Controller method  
**Purpose:** Declare the JSON request body and link it to a DTO class for automatic schema generation.

```php
use Incoder\DDD\Support\Attributes\OpenApi\ApiBody;

#[ApiBody(EmployeeCreateDTO::class)]
public function store(Request $request) { ... }

// With description and optional body
#[ApiBody(UserUpdateDTO::class, description: 'Partial update payload', required: false)]
public function update(string $id, Request $request) { ... }
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$class` | `class-string` | *(required)* | DTO class; its properties are introspected for the schema |
| `$description` | `string` | `''` | Optional description for the request body |
| `$required` | `bool` | `true` | Whether the body is mandatory |

> **Tip:** If your method has a typed parameter with `#[FromBody]` pointing to a DTO class, the schema is inferred automatically — `#[ApiBody]` is only needed to override or be explicit.

---

### `#[ApiResponse]`

**Target:** Controller method — **repeatable** (one per status code)  
**Purpose:** Document the responses your endpoint can return. Optionally link a DTO class as the response schema.

```php
use Incoder\DDD\Support\Attributes\OpenApi\ApiResponse;

#[ApiResponse(200, 'Paginated employee list', EmployeePaginatedDTO::class)]
#[ApiResponse(401, 'Unauthenticated')]
#[ApiResponse(422, 'Validation failed')]
public function index(Request $request) { ... }

#[ApiResponse(201, 'Employee created', EmployeeDTO::class)]
#[ApiResponse(409, 'Duplicate employee number')]
public function store(Request $request) { ... }
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$statusCode` | `int` | *(required)* | HTTP status code |
| `$description` | `string` | *(required)* | Human-readable description |
| `$class` | `class-string\|null` | `null` | DTO class to use as the response body schema |

If no `#[ApiResponse]` is given, a default `200 Success` response is added automatically.

---

### `#[ApiQuery]`

**Target:** Controller method — **repeatable** (one per query param)  
**Purpose:** Declare or override query string parameters.

```php
use Incoder\DDD\Support\Attributes\OpenApi\ApiQuery;

#[ApiQuery('search', 'string', 'Filter by name or email', required: false)]
#[ApiQuery('per_page', 'integer', 'Number of results per page (default 15)', required: false, example: 15)]
#[ApiQuery('status', 'string', 'active or archived', required: false)]
public function index(Request $request) { ... }
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$name` | `string` | *(required)* | Query parameter name |
| `$type` | `string` | `'string'` | JSON Schema type: `string`, `integer`, `boolean`, `number` |
| `$description` | `string` | `''` | Description shown in the UI |
| `$required` | `bool` | `false` | Whether the param is mandatory |
| `$example` | `mixed` | `null` | Example value shown in the UI |

> **Automatic alternative:** Add `#[FromQuery]` to a typed method parameter — it will be inferred without needing `#[ApiQuery]`.
> ```php
> public function index(#[\Incoder\DDD\Support\Attributes\FromQuery] string $search = '') { ... }
> ```

---

### `#[ApiParam]`

**Target:** Controller method — **repeatable** (one per path param)  
**Purpose:** Override the type, description, or example of a path parameter auto-detected from `{param}` in the URI.

```php
use Incoder\DDD\Support\Attributes\OpenApi\ApiParam;

// URI: /employees/{id}
#[ApiParam('id', 'string', 'UUID of the employee', example: '550e8400-e29b-41d4-a716-446655440000')]
public function show(string $id) { ... }

// URI: /schedules/{year}/{month}
#[ApiParam('year', 'integer', 'Four-digit year', example: 2026)]
#[ApiParam('month', 'integer', 'Month number 1–12', example: 3)]
public function byMonth(int $year, int $month) { ... }
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$name` | `string` | *(required)* | Must match the `{name}` in the route URI |
| `$type` | `string` | `'string'` | JSON Schema type |
| `$description` | `string` | `''` | Description |
| `$example` | `mixed` | `null` | Example value |

Path params are always `required: true` per the OpenAPI spec.

---

### `#[ApiSecurity]`

**Target:** Controller class **or** method  
**Purpose:** Override the auto-inferred security requirement. Class-level sets the default for all methods; method-level takes precedence.

```php
use Incoder\DDD\Support\Attributes\OpenApi\ApiSecurity;

// Require session auth on ALL methods in this controller (same as the default for 'auth' middleware)
#[ApiSecurity(['sessionAuth'])]
class EmployeeController extends Controller { ... }

// Explicitly mark one method as publicly accessible (no auth)
#[ApiSecurity([])]
public function publicStats() { ... }
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$schemes` | `string[]` | `['sessionAuth']` | Keys matching `security_schemes` in `config/api-docs.php`. Pass `[]` to mark as public. |

**Auto-inference:** When no `#[ApiSecurity]` is present, the system automatically marks any route whose `#[RouteAttribute]` middleware includes `auth` as requiring `sessionAuth`. Routes without `auth` in their middleware are treated as public.

---

### `#[ApiHide]`

**Target:** Controller class **or** method  
**Purpose:** Completely exclude a controller or individual endpoint from the generated spec. Equivalent to FastAPI's `include_in_schema=False`.

```php
use Incoder\DDD\Support\Attributes\OpenApi\ApiHide;

// Hide the entire controller
#[ApiHide]
class InternalWebhookController extends Controller { ... }

// Hide a single method while keeping the rest of the controller visible
public function index() { ... }

#[ApiHide]
public function legacyCallback(Request $request) { ... }
```

---

## AppService Routes

All routes auto-registered by `AppServiceRouteScanner` are also documented automatically. The scanner handles three registration patterns:

### Pattern 1 — Convention-based CRUD

Any AppService that inherits `AppServiceBase` and implements the standard method names gets documented at `/app/api/{service}` with the correct HTTP methods and inferred DTO schemas.

| AppService method | HTTP | Path | Response schema |
|---|---|---|---|
| `getAll()` | GET | `/app/api/{service}` | `{Entity}ListDTO` |
| `getPaged()` | GET | `/app/api/{service}/paged` | `{Entity}PaginatedDTO` |
| `getById($id)` | GET | `/app/api/{service}/{id}` | `{Entity}DTO` |
| `create(...)` | POST | `/app/api/{service}` | `{Entity}DTO` (body) |
| `update($id, ...)` | PUT | `/app/api/{service}/{id}` | `{Entity}DTO` (body) |
| `delete($id)` | DELETE | `/app/api/{service}/{id}` | 204 No Content |

The DTO classes are resolved automatically by convention:
```
UserAppService  →  namespace Core\Application\Users\Contracts\
                  → UserDTO, UserListDTO, UserPaginatedDTO
```

### Pattern 2 — `#[RouteAttribute]` on custom methods

Custom methods in an AppService that carry `#[RouteAttribute]` are documented the same way as controller methods. All `#[Api*]` override attributes work identically.

```php
class OrderAppService extends AppServiceBase implements IOrderAppService
{
    #[RouteAttribute('GET', 'pending', 'orders.pending', ['api'])]
    #[ApiOperation('List pending orders')]
    #[ApiResponse(200, 'Pending order list', OrderListDTO::class)]
    public function getPendingOrders(): DataCollection { ... }
}
// Documented at: GET /app/api/order/pending
```

### Pattern 3 — HTTP-prefix convention

Methods prefixed with an HTTP verb are documented automatically. Path parameters are inferred when the parameter name appears in the base method name.

```php
class EmployeeAppService extends AppServiceBase
{
    // GET /app/api/employee/active-by-campus/{campus}
    public function getActiveByCampus(string $campus): DataCollection { ... }

    // POST /app/api/employee/archive/{id}
    public function postArchive(#[FromUri] string $id): bool { ... }

    // GET /app/api/employee/search  (query param, not path param)
    public function getSearch(#[FromQuery] string $q): DataCollection { ... }
}
```

### Security on AppService routes

Two PHP attributes control auth and permissions on AppService routes.
They work at **class level** (default for all routes) and at **method level** (override for a specific route).

---

#### `#[AppServiceMiddleware]`

Sets the Laravel middleware stack for every auto-registered route in the AppService.

```php
use Incoder\DDD\Support\Attributes\AppServiceMiddleware;

// Requires Bearer token (mobile) OR session cookie (browser).
// This is the recommended default for any AppService used by mobile clients.
#[AppServiceMiddleware(['auth:sanctum'])]
class UserAppService extends AppServiceBase { ... }

// Browser / Inertia SPA only.
#[AppServiceMiddleware(['web', 'auth'])]
class ReportAppService extends AppServiceBase { ... }

// Public — no auth required.
#[AppServiceMiddleware(['api'])]
class PublicJobsAppService extends AppServiceBase { ... }
```

> **Default behaviour (no attribute):** middleware stays as `['api']` — public, backward compatible.

`#[RouteAttribute]` methods carry their **own** explicit middleware list and are not affected by `#[AppServiceMiddleware]`.

---

#### `#[RequiresPermission]`

Appends a Spatie `permission:{name}` middleware check.  Method-level overrides class-level.

```php
use Incoder\DDD\Support\Attributes\AppServiceMiddleware;
use Incoder\DDD\Support\Attributes\RequiresPermission;

#[AppServiceMiddleware(['auth:sanctum'])]
#[RequiresPermission('users.view')]       // ← default for all routes in this service
class UserAppService extends AppServiceBase implements IUserAppService
{
    // Uses class-level permission 'users.view'
    public function getAll(): DataCollection { ... }
    public function getById(string $id): UserDTO { ... }

    // Override: needs 'users.manage' instead
    #[RequiresPermission('users.manage')]
    public function create(UserDTO $dto): UserDTO { ... }

    // Override: needs EITHER permission (Spatie OR check)
    #[RequiresPermission('users.manage', 'admin.superuser')]
    public function delete(string $id): bool { ... }

    // Override: no permission check (auth still required via class middleware)
    #[RequiresPermission]
    public function getMyProfile(string $id): UserDTO { ... }
}
```

**In the generated spec**, permission-protected operations get:
- An `x-permissions` extension field (machine-readable)
- A ⚠️ note in `description` visible in Swagger UI

```json
{
  "operationId": "UserAppService_create",
  "description": "\n\n⚠️ **Required permission(s):** `users.manage`",
  "x-permissions": ["users.manage"],
  "security": [{"sanctum": []}, {"sessionAuth": []}]
}
```

---

#### Quick-reference

| Goal | Attribute |
|---|---|
| Require Bearer OR session cookie for all routes | `#[AppServiceMiddleware(['auth:sanctum'])]` on class |
| Require session cookie only | `#[AppServiceMiddleware(['web', 'auth'])]` on class |
| Public AppService | `#[AppServiceMiddleware(['api'])]` on class (or omit attribute) |
| Default permission for whole service | `#[RequiresPermission('resource.action')]` on class |
| Override permission for one method | `#[RequiresPermission('other.permission')]` on method |
| No permission check on one method | `#[RequiresPermission]` on method (empty = skip permission) |

---

## DTO Schema Inference

When a DTO class is referenced via `#[ApiBody]` or `#[ApiResponse]`, the `SchemaInferrer` introspects it automatically:

**It supports both DTO styles used in this project:**

### Style 1 — Public property DTOs

```php
class UserListDTO extends DTOBase
{
    public string $id;
    public string $first_name;
    public ?string $middle_name;   // nullable → "nullable": true
    public bool $is_active;
    public int $current_salary_grade;
}
```

### Style 2 — Constructor-param DTOs (Spatie Data style)

```php
class JobPositionCreateDTO extends DTOBase
{
    public function __construct(
        public string $salary_id,
        public ?string $position_description = null,
        public bool $is_active = true,
    ) {}
}
```

### Style 3 — DTOs with `rules()` (recommended for full schema)

The `rules()` method is parsed to enrich the schema with additional constraints:

```php
class UserDTO extends DTOBase
{
    public $first_name;
    public $email;
    public $birthdate;

    public static function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'email'      => ['nullable', 'email:rfc', 'max:255'],
            'birthdate'  => ['nullable', 'date'],
        ];
    }
}
```

Generates:

```json
{
  "type": "object",
  "required": ["first_name"],
  "properties": {
    "first_name": { "type": "string", "maxLength": 255 },
    "email":      { "type": "string", "format": "email", "maxLength": 255, "nullable": true },
    "birthdate":  { "type": "string", "format": "date", "nullable": true }
  }
}
```

### Validation rule → JSON Schema mapping

| Laravel Rule | JSON Schema effect |
|---|---|
| `required` | Added to `required[]` array |
| `nullable` | `"nullable": true` |
| `string` | `"type": "string"` |
| `integer` | `"type": "integer"` |
| `boolean` | `"type": "boolean"` |
| `numeric` | `"type": "number"` |
| `max:N` | `"maxLength": N` (string) or `"maximum": N` (number) |
| `min:N` | `"minLength": N` (string) or `"minimum": N` (number) |
| `email` / `email:rfc` | `"format": "email"` |
| `date` | `"format": "date"` |
| `in:a,b,c` | `"enum": ["a", "b", "c"]` |

---

## Artisan Command

```bash
# Write spec to public/api-docs.json (default)
php artisan api:generate-spec

# Custom output path
php artisan api:generate-spec --output=storage/openapi.json

# YAML format
php artisan api:generate-spec --format=yaml --output=public/api-docs.yaml

# Print to stdout (CI pipeline / pipe into validator)
php artisan api:generate-spec --stdout

# Validate the spec with a third-party tool
php artisan api:generate-spec --stdout | npx @redocly/cli lint -
```

### Options

| Option | Description | Default |
|---|---|---|
| `--output` | File path to write the spec | `public/api-docs.json` |
| `--format` | `json` or `yaml` | `json` |
| `--stdout` | Print to stdout instead of writing a file | off |

---

## Configuration

Publish the config stub to your application:

```bash
php artisan vendor:publish --tag=api-docs
```

This creates `config/api-docs.php`:

```php
return [
    // Feature flag — set to false to disable UI and spec endpoint in production
    'enabled' => env('API_DOCS_ENABLED', true),

    // Metadata shown in the UI header and spec info block
    'title'       => env('APP_NAME', 'Laravel') . ' API',
    'description' => '',
    'version'     => '1.0.0',

    // URL paths for the Swagger UI and spec endpoint
    'path'      => '/api/docs',
    'spec_path' => '/api/docs/spec',

    // Middleware applied to both routes
    // Examples:
    //   ['web']           — open (default, dev-friendly)
    //   ['web', 'auth']   — require login in production
    'middleware' => ['web'],

    // Extra servers listed in the spec (primary server = APP_URL)
    'servers' => [
        // ['url' => 'https://staging.example.com', 'description' => 'Staging'],
    ],

    // Security schemes registered under #/components/securitySchemes.
    // This app uses Laravel session/cookie auth (web guard, Sanctum SPA mode).
    // No bearer token — the browser sends the session cookie automatically.
    'security_schemes' => [
        'sessionAuth' => [
            'type'        => 'apiKey',
            'in'          => 'cookie',
            'name'        => 'laravel_session',
            'description' => 'Laravel session cookie. Log in at /login first — the browser sends it automatically.',
        ],
    ],
];
```

### Environment variables

| Variable | Effect |
|---|---|
| `API_DOCS_ENABLED=false` | Disables UI + spec routes and hides the feature entirely |

---

## Full Example

A real-world controller with all override attributes applied:

```php
<?php

namespace App\Http\Controllers;

use Core\Application\Employees\Contracts\EmployeeCreateDTO;
use Core\Application\Employees\Contracts\EmployeeDTO;
use Core\Application\Employees\Contracts\EmployeePaginatedDTO;
use Core\Application\Employees\IEmployeeAppService;
use Illuminate\Http\Request;
use Incoder\DDD\Support\Attributes\RouteAttribute;
use Incoder\DDD\Support\Attributes\OpenApi\ApiBody;
use Incoder\DDD\Support\Attributes\OpenApi\ApiHide;
use Incoder\DDD\Support\Attributes\OpenApi\ApiOperation;
use Incoder\DDD\Support\Attributes\OpenApi\ApiParam;
use Incoder\DDD\Support\Attributes\OpenApi\ApiQuery;
use Incoder\DDD\Support\Attributes\OpenApi\ApiResponse;
use Incoder\DDD\Support\Attributes\OpenApi\ApiSecurity;
use Incoder\DDD\Support\Attributes\OpenApi\ApiTag;

#[ApiTag('Employees', 'Manage employee records')]
#[ApiSecurity(['sessionAuth'])]       // all methods require session login
class EmployeeController extends Controller
{
    public function __construct(
        private readonly IEmployeeAppService $employeeService
    ) {}

    #[RouteAttribute('GET', 'employees', 'employees.index', ['auth'])]
    #[ApiOperation('List employees', 'Returns a paginated, filterable list of all employees.')]
    #[ApiQuery('search',   'string',  'Filter by name or email')]
    #[ApiQuery('per_page', 'integer', 'Items per page', example: 15)]
    #[ApiQuery('campus',   'string',  'Filter by campus')]
    #[ApiResponse(200, 'Paginated employee list', EmployeePaginatedDTO::class)]
    public function index(Request $request)
    {
        return response()->json($this->employeeService->getPaginated($request->all()));
    }

    #[RouteAttribute('GET', 'employees/{id}', 'employees.show', ['auth'])]
    #[ApiParam('id', 'string', 'Employee UUID')]
    #[ApiResponse(200, 'Employee record', EmployeeDTO::class)]
    #[ApiResponse(404, 'Employee not found')]
    public function show(string $id)
    {
        return response()->json($this->employeeService->getById($id));
    }

    #[RouteAttribute('POST', 'employees', 'employees.store', ['auth'])]
    #[ApiOperation('Create employee', 'Validates and creates a new employee record.')]
    #[ApiBody(EmployeeCreateDTO::class)]
    #[ApiResponse(201, 'Employee created', EmployeeDTO::class)]
    #[ApiResponse(409, 'Duplicate employee number')]
    #[ApiResponse(422, 'Validation failed')]
    public function store(Request $request)
    {
        $dto = EmployeeCreateDTO::from($request->validate(EmployeeCreateDTO::rules()));
        return response()->json($this->employeeService->create($dto), 201);
    }

    #[RouteAttribute('PUT', 'employees/{id}', 'employees.update', ['auth'])]
    #[ApiParam('id', 'string', 'Employee UUID')]
    #[ApiBody(EmployeeCreateDTO::class)]
    #[ApiResponse(200, 'Employee updated', EmployeeDTO::class)]
    #[ApiResponse(404, 'Employee not found')]
    public function update(string $id, Request $request)
    {
        $dto = EmployeeCreateDTO::from($request->validate(EmployeeCreateDTO::rules()));
        return response()->json($this->employeeService->update($id, $dto));
    }

    #[RouteAttribute('DELETE', 'employees/{id}', 'employees.destroy', ['auth'])]
    #[ApiParam('id', 'string', 'Employee UUID')]
    #[ApiResponse(204, 'Deleted')]
    #[ApiResponse(404, 'Employee not found')]
    public function destroy(string $id)
    {
        $this->employeeService->delete($id);
        return response()->noContent();
    }

    // This internal endpoint is visible in the app but hidden from the docs
    #[RouteAttribute('POST', 'employees/sync-payroll', 'employees.syncPayroll', ['auth'])]
    #[ApiHide]
    public function syncPayroll(Request $request) { ... }
}
```

---

## Architecture Reference

```
packages/incoder-ddd/src/Support/
│
├── Attributes/OpenApi/          Override attributes (PHP 8 #[Attribute])
│   ├── ApiTag.php               #[ApiTag('Name', 'desc')] on controller class
│   ├── ApiOperation.php         #[ApiOperation('summary', description:, tags:, deprecated:)] on method
│   ├── ApiBody.php              #[ApiBody(DTO::class, description:, required:)] on method
│   ├── ApiResponse.php          #[ApiResponse(200, 'desc', DTO::class)] on method — REPEATABLE
│   ├── ApiQuery.php             #[ApiQuery('name', 'type', 'desc', required:, example:)] — REPEATABLE
│   ├── ApiParam.php             #[ApiParam('name', 'type', 'desc', example:)] — REPEATABLE
│   ├── ApiSecurity.php          #[ApiSecurity(['sanctum'])] on class or method
│   └── ApiHide.php              #[ApiHide] on class or method
│
├── OpenApi/                     Core generation engine
│   ├── OpenApiGenerator.php     Orchestrator — scans controllers AND AppServices, assembles OA3 spec
│   ├── AppServiceDocScanner.php AppService CRUD + attribute + HTTP-prefix route documentation
│   ├── SchemaInferrer.php       DTO class → JSON Schema via reflection + rules() parsing
│   ├── RouteDocExtractor.php    Single controller method → OA3 operation object
│   └── SwaggerUiController.php  GET /api/docs (HTML) + GET /api/docs/spec (JSON)
│
└── Console/Commands/
    └── GenerateApiSpec.php      php artisan api:generate-spec
```

### Class responsibilities

| Class | Responsibility |
|---|---|
| `OpenApiGenerator` | Entry point. Scans all `*Controller.php` files **and** all AppService classes, then assembles the final OA3 spec. |
| `AppServiceDocScanner` | Handles the three AppService route patterns: CRUD conventions, `#[RouteAttribute]`, and HTTP-prefix conventions. Resolves DTO classes by namespace convention. |
| `RouteDocExtractor` | Processes a single `(ReflectionClass, ReflectionMethod, RouteAttribute)` triple for controller methods. |
| `SchemaInferrer` | Converts a PHP class to a JSON Schema fragment using reflection. Reads public properties, constructor params, and the optional static `rules()` method. Results are cached per class. |
| `SwaggerUiController` | Serves the Swagger UI HTML page (CDN-loaded, no npm) and the live JSON spec. Routes are registered automatically by `IncoderDDDServiceProvider` when `api-docs.enabled = true`. |
| `GenerateApiSpec` | Wraps `OpenApiGenerator` in an Artisan command with `--output`, `--format`, and `--stdout` options. |

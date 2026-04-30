# Developer Manual

Consumer guide for Laravel applications using `incoder/laravel-ddd`.

## 1. What This Package Is For

This package provides reusable Laravel DDD / Clean Architecture building blocks:

- domain base entities and aggregate roots
- repository contracts and Eloquent repository bases
- application service and DTO bases
- attribute-driven controller routing
- AppService auto-route registration
- OpenAPI / Swagger generation
- TypeScript proxy generation
- Flutter OpenAPI proxy generation
- SSRS reporting support

It is a Composer package, not a standalone Laravel app.

## 2. Installation

Install into a Laravel application:

```bash
composer require incoder/laravel-ddd
```

Laravel package discovery will register:

- `Incoder\DDD\Support\IncoderDDDServiceProvider`
- `Incoder\DDD\Support\Reporting\ReportServiceProvider`

Publish package config as needed:

```bash
php artisan vendor:publish --tag=incoder-ddd-config
php artisan vendor:publish --tag=api-docs
```

Published files:

- `config/incoder-ddd.php`
- `config/reporting.php`
- `config/api-docs.php`

## 3. Required Runtime Expectations

The package supports reusable base classes, but some features depend on conventions.

Default conventions in `config/incoder-ddd.php`:

- application namespace: `Core\Application`
- domain namespace: `Core\Domain`
- repository implementation namespace: `Core\Infrastructure\Eloquent\Repositories`
- domain path: `core/Domain`
- application path: `core/Application`
- repository path: `core/Infrastructure/Eloquent/Repositories`
- AppService route prefix: `/app/api`
- controller scan path: `app/Http/Controllers`

If your application uses different namespaces or paths, update `config/incoder-ddd.php`.

## 4. Optional Companion Packages

Some features assume packages or app infrastructure outside this repo:

- `spatie/laravel-permission`
  - recommended if you use `#[RequiresPermission(...)]`
- Sanctum or equivalent auth setup
  - recommended if you rely on the default `auth:sanctum` AppService middleware
- Inertia + Vue frontend stack
  - effectively required if you use `php artisan make:domain-crud`
- Java + OpenAPI Generator CLI JAR
  - required for Flutter proxy generation
- PHP SOAP extension
  - required for SSRS reporting support

## 5. Core Consumption Patterns

### 5.1 Domain Entities

Base classes:

- `Incoder\DDD\Domain\Entities\Entity`
- `Incoder\DDD\Domain\Entities\AggregateRoot`
- `Incoder\DDD\Domain\Entities\AuthenticableAggregateRoot`

Example:

```php
use Incoder\DDD\Domain\Entities\Entity;
use Incoder\DDD\Support\Attributes\FillableAttribute;

class Department extends Entity
{
    protected $table = 'admin.departments';

    public $incrementing = true;
    protected $keyType = 'int';

    #[FillableAttribute]
    protected string $name;
}
```

Confirmed behavior:

- `Entity` uses soft deletes
- fillable fields are detected from `#[FillableAttribute]`
- string keys generate UUIDs on create
- activity logging is integrated through `spatie/laravel-activitylog`

### 5.2 DTO Base

Use:

- `Incoder\DDD\Application\DTOs\DTOBase`
- `Incoder\DDD\Application\DTOs\PaginatedDTOBase`
- `Incoder\DDD\Application\DTOs\ResultData`

`DTOBase` extends `Spatie\LaravelData\Data`.

### 5.3 Repository Base

Use:

- `Incoder\DDD\Domain\Repositories\IRepository`
- `Incoder\DDD\Infrastructure\Repositories\EloquentRepositoryBase`
- `Incoder\DDD\Infrastructure\Repositories\EloquentRepository`

Typical pattern:

```php
interface IUserRepository extends IRepository
{
}

class UserRepository extends EloquentRepositoryBase implements IUserRepository
{
    public function __construct()
    {
        parent::__construct(User::class);
    }
}
```

### 5.4 AppService Base

Use:

- `Incoder\DDD\Application\Services\AppServiceBase`

This is the base for:

- CRUD application services
- auto-registered AppService routes
- OpenAPI AppService scanning
- TypeScript proxy generation

Default class middleware comes from `AppServiceBase`:

- `api`
- `auth:sanctum`

Override class-wide route middleware with `#[AppServiceMiddleware([...])]`.

## 6. Routing Model

### 6.1 Controller Attribute Routes

Use `#[RouteAttribute]` on controller methods.

If you omit middleware:

- `/api/...` and `/app/api/...` default to `api`
- non-API routes default to `web`

Example:

```php
use Incoder\DDD\Support\Attributes\RouteAttribute;

#[RouteAttribute(methods: ['GET'], uri: '/reports', name: 'reports.index')]
public function index()
{
}
```

The package scans `app/Http/Controllers` by default. This path is configurable.

### 6.2 AppService Auto-Routes

An AppService is auto-registered when it:

- is present in Composer’s classmap
- lives under the configured application namespace
- ends with `AppService`
- extends `AppServiceBase`

Default route base:

```text
/app/api/{service}
```

Supported route patterns:

- CRUD conventions:
  - `getAll`
  - `getPaged`
  - `getById`
  - `create`
  - `update`
  - `delete`
- custom routes with `#[RouteAttribute]`
- convention routes from method prefixes like `getSomething`, `postArchive`, `putAssign`

See [APPSERVICE-ROUTES.md](/C:/laravel-projects/packages/incoder-ddd/docs/features/APPSERVICE-ROUTES.md).

### 6.3 Permissions

Use:

- `#[AppServiceMiddleware([...])]`
- `#[RequiresPermission(...)]`

Important:

- `#[RequiresPermission(...)]` assumes your app has a `permission:` middleware alias
- this is typically provided by `spatie/laravel-permission`
- method-level `#[RequiresPermission(...)]` overrides class-level permission
- `#[RouteAttribute]` methods carry their own middleware list

## 7. OpenAPI / Swagger

The package can generate OpenAPI docs from:

- auto-discovered AppServices
- controller methods using `#[RouteAttribute]`

Default docs routes:

- UI: `/api/docs`
- spec: `/api/docs/spec`

Docs UI is only exposed in non-production when `config('api-docs.enabled')` is true.

Useful commands:

```bash
php artisan api:generate-spec
php artisan api:generate-spec --stdout
php artisan api:generate-spec --format=yaml --output=public/api-docs.yaml
```

See [OPENAPI-DOCS.md](/C:/laravel-projects/packages/incoder-ddd/docs/features/OPENAPI-DOCS.md).

## 8. TypeScript Proxies

Generate TypeScript proxies from discovered AppServices:

```bash
php artisan proxy:generate
php artisan proxy:generate --output=resources/js/proxies
```

Default output path:

```text
resources/js/proxies
```

This is configurable in `config/incoder-ddd.php`.

Generated output is based on the AppService/OpenAPI route model, so route conventions affect the proxy surface.

## 9. Flutter Proxies

Generate Flutter/Dart OpenAPI clients:

```bash
php artisan proxy:generate-flutter \
  --flutter-root=../flutter \
  --output=../flutter/lib/src/generated/openapi \
  --config=../flutter/tool/openapi-generator-config.yaml \
  --jar=../flutter/.tooling/openapi-generator/openapi-generator-cli-7.21.0.jar
```

Defaults are configurable in `config/incoder-ddd.php`.

Requirements:

- sibling Flutter project by default
- Java
- OpenAPI Generator CLI JAR

## 10. Scaffolding Commands

### 10.1 Generate a Domain Model

```bash
php artisan make:domain-model User --type=aggregate --format=string --incrementing=false --schema=admin
```

### 10.2 Generate Only the Model

```bash
php artisan make:domain-model Employee --type=entity --format=int --incrementing=true --schema=admin --only-model
```

### 10.3 Generate CRUD Around an Existing Model

```bash
php artisan make:domain-crud User --schema=admin --format=string --incrementing=false
```

After scaffolding:

```bash
composer dump-autoload
php artisan migrate
```

Important consumption note:

`make:domain-crud` is not a generic backend-only scaffold. It currently generates:

- application service contracts and implementations
- repository files
- migrations
- a web controller under the configured web controller path
- Vue/Inertia pages under the configured frontend pages path

It assumes a frontend stack with:

- Inertia
- Vue
- alias-based imports like `@/proxies/...`
- your own frontend UI components

If your app does not use that stack, do not use `make:domain-crud` as-is without customizing the generated output.

## 11. Reporting

Reporting is provided through:

- `Incoder\DDD\Support\Reporting\Contracts\IReportService`
- `Incoder\DDD\Support\Reporting\Facades\Report`

Supported methods:

- `generatePdfReport()`
- `streamPdfReport()`
- `getReportInfo()`
- `testConnection()`

Required environment variables:

```env
SSRS_BASE_URL=http://your-ssrs-server/ReportServer
SSRS_USERNAME=your_username
SSRS_PASSWORD=your_password
SSRS_TIMEOUT=120
SSRS_CACHE_TTL=0
```

Use HTTPS and least-privilege credentials in production.

See [README-REPORTING.md](/C:/laravel-projects/packages/incoder-ddd/docs/features/README-REPORTING.md).

## 12. Recommended Consumption Workflow

For a new Laravel app:

1. Install the package with Composer.
2. Publish `incoder-ddd` and `api-docs` config if you need custom conventions.
3. Decide whether your app will follow the default `Core\...` / `core/...` conventions.
4. Create entities, repositories, DTOs, and AppServices using the package base classes.
5. If using AppService auto-routes, keep services under the configured application namespace.
6. If using permissions, install and configure `spatie/laravel-permission`.
7. If using OpenAPI, enable docs routes only where appropriate.
8. If using scaffolding, review generated code before relying on it as final application code.
9. Generate TypeScript or Flutter clients only after route conventions and DTOs are stable.

## 13. Troubleshooting

### Routes are not being auto-registered

Check:

- the AppService class is in the configured application namespace
- the class ends with `AppService`
- the class extends `AppServiceBase`
- Composer classmap/autoload is current

Run:

```bash
composer dump-autoload
```

### Permissions do nothing

Check:

- your app has a `permission:` middleware alias
- permission names match your authorization system

### OpenAPI docs are missing AppService routes

Check:

- AppServices match the configured namespace and naming rules
- the route prefix is what you expect in `config/incoder-ddd.php`
- docs are enabled through `config/api-docs.php`

### Generated CRUD frontend does not fit the app

That is expected if your app does not use the assumed Inertia/Vue stack. Either customize the generated files or avoid `make:domain-crud`.

## 14. Safe Upgrade Guidance

Treat these as public package behavior in your app:

- command names
- config keys
- publish tags
- AppService route conventions
- generated output shapes
- service provider boot behavior

Before upgrading:

1. Review `CHANGELOG.md`.
2. Diff `config/incoder-ddd.php` and `config/api-docs.php`.
3. Re-run proxy generation if your app commits generated clients.
4. Re-test any app code that depends on auto-discovery, routing, or generated scaffolds.

## 15. Related Docs

- [Readme.MD](/C:/laravel-projects/packages/incoder-ddd/Readme.MD)
- [APPSERVICE-ROUTES.md](/C:/laravel-projects/packages/incoder-ddd/docs/features/APPSERVICE-ROUTES.md)
- [OPENAPI-DOCS.md](/C:/laravel-projects/packages/incoder-ddd/docs/features/OPENAPI-DOCS.md)
- [README-REPORTING.md](/C:/laravel-projects/packages/incoder-ddd/docs/features/README-REPORTING.md)

# Incoder Laravel DDD

Reusable Laravel DDD / Clean Architecture building blocks packaged for Composer.

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3-blue)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E12.21-red)](https://laravel.com)

## Overview

`incoder-ddd` provides shared foundations for Laravel applications that use a Domain / Application / Infrastructure split. The package includes:

- base entities and aggregate roots
- repository abstractions and Eloquent repository bases
- application service and DTO bases
- attribute-based route scanning
- AppService auto-route registration
- OpenAPI / Swagger generation
- TypeScript proxy generation
- Flutter OpenAPI proxy generation
- SSRS reporting helpers

The package is installable as a standalone Composer library and registers itself through Laravel package auto-discovery.

## Package Name

Current `composer.json` package name:

```bash
composer require incoder/laravel-ddd
```

## Requirements

- PHP `^8.3`
- `illuminate/support` `^12.21`
- `illuminate/database` `^12.21`
- `ramsey/uuid`
- `spatie/laravel-data`
- `spatie/laravel-activitylog`

## Installation

Install the package in a Laravel application:

```bash
composer require incoder/laravel-ddd
```

Laravel will discover `Incoder\DDD\Support\IncoderDDDServiceProvider` automatically.

Optional publish steps:

```bash
php artisan vendor:publish --tag=api-docs
php artisan vendor:publish --tag=incoder-ddd-config
```

This publishes:

- `config/api-docs.php`
- `config/reporting.php`

## What The Package Actually Assumes

Some features are generic. Some are opinionated and expect the consuming app to follow existing conventions already encoded in this package.

Current conventions used by runtime scanning and code generation:

- AppService discovery expects classes under `Core\Application\...`
- DI auto-binding expects `Core\Domain\...` and `Core\Infrastructure\Eloquent\Repositories\...`
- controller route scanning reads `app/Http/Controllers`
- scaffold commands generate files into `core/...`
- AppService HTTP routes are registered under `/app/api/{service}`

If your application does not follow those conventions, the generic base classes are still reusable, but the auto-discovery and generators may need adaptation.

## Core Building Blocks

### Domain Entities

Use `Entity`, `AggregateRoot`, or `AuthenticableAggregateRoot` as base classes for domain models.

```php
use Incoder\DDD\Domain\Entities\Entity;
use Incoder\DDD\Support\Attributes\FillableAttribute;

class Department extends Entity
{
    protected $table = 'departments';

    public $incrementing = true;
    protected $keyType = 'int';

    #[FillableAttribute]
    protected string $name;
}
```

Confirmed behavior from the codebase:

- soft deletes are enabled on `Entity`
- `#[FillableAttribute]` drives fillable-property detection
- string keys generate UUIDs on create
- activity logging is wired through `spatie/laravel-activitylog`

### DTO Base

`Incoder\DDD\Application\DTOs\DTOBase` extends `Spatie\LaravelData\Data` and provides `toDTO()` / `toDTOs()` helpers for Eloquent models.

### Repository Base

Repository contracts extend `Incoder\DDD\Domain\Repositories\IRepository`. Eloquent implementations can build on:

- `Incoder\DDD\Infrastructure\Repositories\EloquentRepository`
- `Incoder\DDD\Infrastructure\Repositories\EloquentRepositoryBase`

### AppService Base

`Incoder\DDD\Application\Services\AppServiceBase` provides CRUD-oriented application service helpers and is the base type used by AppService route / OpenAPI / proxy scanning.

The inherited default AppService middleware comes from `AppServiceBase`:

- `api`
- `auth:sanctum`

You can override class-wide route middleware with `#[AppServiceMiddleware(...)]`.

## Routing

### Controller Attribute Routing

`#[RouteAttribute]` can be used on controller methods. If no middleware is supplied:

- `/api/...` and `/app/api/...` routes default to `api`
- other routes default to `web`

Example:

```php
use Incoder\DDD\Support\Attributes\RouteAttribute;

#[RouteAttribute(methods: ['GET'], uri: '/reports', name: 'reports.index')]
public function index()
{
}
```

### AppService Auto-Routes

Classes that:

- are in the Composer classmap
- live under `Core\Application\...`
- end with `AppService`
- extend `AppServiceBase`

are auto-registered at boot under `/app/api/{service}`.

Supported patterns:

- CRUD conventions: `getAll`, `getPaged`, `getById`, `create`, `update`, `delete`
- custom routes with `#[RouteAttribute]`
- convention routes based on HTTP verb prefixes like `getActiveUsers` or `postArchive`

Permission integration is available through `#[RequiresPermission(...)]` if your app provides a compatible `permission` middleware alias such as the one from `spatie/laravel-permission`.

More detail: [docs/features/APPSERVICE-ROUTES.md](docs/features/APPSERVICE-ROUTES.md)

## OpenAPI / Swagger

The package can generate an OpenAPI spec from auto-registered AppServices and exposes Swagger UI in non-production environments when `config('api-docs.enabled')` is true.

Default routes:

- UI: `/api/docs`
- spec: `/api/docs/spec`

Generate a spec file:

```bash
php artisan api:generate-spec
php artisan api:generate-spec --format=yaml --output=public/api-docs.yaml
php artisan api:generate-spec --stdout
```

More detail: [docs/features/OPENAPI-DOCS.md](docs/features/OPENAPI-DOCS.md)

## Proxy Generation

### TypeScript

Generate TypeScript service proxies and DTO/schema models from the discovered AppServices:

```bash
php artisan proxy:generate
php artisan proxy:generate --output=resources/js/proxies
```

Note: the current command signature defaults to `resources/js/proxiees`. Pass `--output` if you want a different path.

### Flutter

Generate Flutter/Dart OpenAPI sources via the OpenAPI Generator CLI:

```bash
php artisan proxy:generate-flutter \
  --flutter-root=../flutter \
  --output=../flutter/lib/src/generated/openapi \
  --config=../flutter/tool/openapi-generator-config.yaml \
  --jar=../flutter/.tooling/openapi-generator/openapi-generator-cli-7.21.0.jar
```

The Flutter generator expects:

- a sibling Flutter project by default
- Java
- an OpenAPI Generator CLI JAR at the configured path

## Scaffolding Commands

### Create a Domain Model Scaffold

```bash
php artisan make:domain-model User --type=aggregate --format=string --incrementing=false --schema=admin
```

### Create Model Only

```bash
php artisan make:domain-model Employee --type=entity --format=int --incrementing=true --schema=admin --only-model
```

### Create CRUD Around an Existing Model

```bash
php artisan make:domain-crud User --schema=admin --format=string --incrementing=false
```

Important: these commands currently generate into `core/...` directories and `Core\...` namespaces in the consuming application.

## Reporting

The package includes SSRS reporting support through:

- `Incoder\DDD\Support\Reporting\Contracts\IReportService`
- `Incoder\DDD\Support\Reporting\Facades\Report`

Supported operations:

- `generatePdfReport()`
- `streamPdfReport()`
- `getReportInfo()`
- `testConnection()`

Required environment variables for reporting:

```env
SSRS_BASE_URL=http://your-ssrs-server/ReportServer
SSRS_USERNAME=your_username
SSRS_PASSWORD=your_password
SSRS_TIMEOUT=120
SSRS_CACHE_TTL=0
```

More detail: [docs/features/README-REPORTING.md](docs/features/README-REPORTING.md)

## Development Notes

- This repository is a Composer package, not a full Laravel app.
- Package behavior should remain stable for Composer consumers.
- If you change command names, config keys, publish tags, route conventions, generated output, or service-provider behavior, treat that as a public API change.

Useful checks:

```bash
composer validate --no-check-publish
composer dump-autoload
```

## License

MIT

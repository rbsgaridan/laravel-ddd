# AGENTS.md

## Purpose

Default instructions for AI coding agents working on the standalone `incoder-ddd` Composer package.

This package was split from an HRIS unirepo and should be maintainable and consumable by Laravel apps through Composer. Use repo evidence first; do not assume the old monorepo exists.

## Package Overview

- Composer name in `composer.json`: `incoder/laravel-ddd`
- README install example uses `incoder/ddd:@dev`; final published name is unresolved
- PHP: `^8.3`
- Illuminate: `illuminate/support`, `illuminate/database` `^12.21`
- Other runtime deps: `ramsey/uuid`, `spatie/laravel-data`, `spatie/laravel-activitylog`
- PSR-4: `Incoder\\DDD\\` -> `src/`
- Main folders: `src/Application`, `src/Domain`, `src/Infrastructure`, `src/Support`, `config`, `docs/features`
- Package provider: `Incoder\\DDD\\Support\\IncoderDDDServiceProvider`
- Nested provider: `Incoder\\DDD\\Support\\Reporting\\ReportServiceProvider`
- Published config:
  - `config/api-docs.php` via `api-docs`
  - `config/reporting.php` via `incoder-ddd-config`
- Registered commands:
  - `api:generate-spec`
  - `proxy:generate`
  - `proxy:generate-flutter`
  - `make:domain-model`
  - `make:domain-crud`
  - `migrate:fresh-schema`
- Confirmed feature areas: DDD base classes, repositories, DTO/app-service bases, attribute routing, OpenAPI, TS proxies, Flutter proxies, SSRS reporting
- No `tests/` directory
- No root `phpunit.xml*`, `pest.php`, `pint.json`, `.github/`, or Composer `scripts`

## Architecture Role

- Keep this package focused on reusable Laravel DDD / Clean Architecture foundations.
- Do not add HRIS-specific business logic, schemas, policies, seeders, or workflow assumptions.
- Consumers should not need the original monorepo.
- Prefer generic abstractions and extension points over app-specific shortcuts.

Current consumer assumptions already in the codebase:

- Some runtime and generator code assumes `core/Application`, `core/Domain`, `core/Infrastructure/Eloquent/Repositories`
- Some scanning/binding assumes `Core\\Application\\...` and `Core\\Domain\\...`
- Controller route scanning uses `app/Http/Controllers`
- AppService routes default to `/app/api/{service}`
- Proxy generators default to `resources/js/proxiees` and `../flutter/...`

Treat those conventions as existing public behavior unless intentionally changed with migration guidance.

## Public API and Backward Compatibility

- Treat exported classes, interfaces, traits, attributes, facades, commands, config keys, publish tags, route conventions, and provider behavior as public API.
- Prefer additive changes.
- Do not casually break namespaces, signatures, command names, config keys, route names, publish tags, generated output shape, or install/boot behavior.
- If a break is unavoidable, document migration steps in `CHANGELOG.md`, release notes, or `Readme.MD`.

## Composer Package Rules

- Keep `composer.json` accurate for name, autoload, requirements, and Laravel discovery.
- Preserve PSR-4 mapping unless a package-wide namespace migration is intentional.
- Keep the package installable from Packagist/VCS, not only local path repositories.
- Do not commit machine-specific paths, secrets, or environment-specific assumptions.
- Keep publishable config and discovery metadata stable.
- If the package name changes to `incoder/ddd`, update docs and release notes in the same change.

## Laravel Package Guidelines

- Register bindings, commands, config merges, routes, and publishes through service providers.
- Keep framework-specific code in support/infrastructure-style areas, not domain abstractions.
- Avoid adding new hard-coded app namespaces like `Core\\`, `App\\`, `Admin\\`, `Applicant\\` unless configurable or already established.
- Avoid adding new hard-coded schemas like `admin` or `applicant`.
- Avoid hard-coded output paths, middleware, and route prefixes unless configurable and documented.
- When changing boot behavior, verify provider registration, command visibility, publishes, and route registration in a Laravel app context.

## Testing and Validation

- Inspect `composer.json`, providers, and changed source files before choosing validation.
- No repo-local test harness is present, so use the smallest relevant validation and state gaps clearly.
- Verified repo-local command:
  - `composer validate --no-check-publish`
- Useful follow-up when relevant:
  - `composer dump-autoload`
  - `php artisan list`
  - `php artisan api:generate-spec --stdout`
  - `php artisan vendor:publish --tag=api-docs`
  - `php artisan vendor:publish --tag=incoder-ddd-config`
  - `php artisan proxy:generate`
  - `php artisan proxy:generate-flutter --skip-format`
- If `composer.json` changes, explain whether `composer.lock` was intentionally updated or left unchanged.

## Documentation Rules

- Update `Readme.MD` when package name, install steps, commands, config, routes, or generated outputs change.
- Update `docs/features` when routing, OpenAPI, reporting, or generator behavior changes.
- Document new config keys, publish tags, commands, extension points, and generated outputs.
- Keep examples generic unless explicitly marked as app-specific examples.

## Security and Privacy

- Never commit secrets, tokens, credentials, or `.env` values.
- Keep reporting credentials environment-driven.
- Do not log sensitive app data by default.
- Do not weaken consumer auth, authorization, middleware, CSRF, CORS, session, or docs exposure defaults.

## Agent Workflow

1. Read this file.
2. Inspect `composer.json` and relevant source before editing.
3. Decide whether the change touches public API or consumer conventions.
4. Prefer minimal, additive changes.
5. Add/update tests only if a harness exists or is intentionally introduced.
6. Update docs for public behavior.
7. Run the smallest relevant validation.
8. Summarize facts, changes, validation, unknowns, and compatibility impact.

## Response Format for Future Agents

### Confirmed
- Facts verified from repo files or commands

### Inferred
- Reasonable assumptions from code patterns

### Unknown
- Gaps or unverified items

### Changes Made
- Files changed and why

### Validation
- Commands run and results

### Compatibility / Risks
- Public API impact, migration concerns, or release-note needs

## Do Not Do

- Do not add HRIS-specific business logic.
- Do not assume the old monorepo is available.
- Do not introduce new hard-coded consumer namespaces, schemas, route prefixes, middleware, or output paths unless unavoidable.
- Do not introduce dependencies without package-level justification.
- Do not rename public namespaces, commands, config keys, publish tags, routes, or generated paths without migration guidance.
- Do not remove tests or weaken validation if a harness exists.
- Do not fabricate repo details or expose secrets.

## Optional Follow-up

If the repo grows, add focused docs under `docs/agents/` such as:

- `package-architecture.md`
- `release-process.md`
- `testing.md`

Keep this root file short.

<?php

namespace Incoder\DDD\Support\Routing;

use Illuminate\Support\Facades\Route;
use Incoder\DDD\Support\Attributes\AppServiceMiddleware;
use Incoder\DDD\Support\Attributes\FromBody;
use Incoder\DDD\Support\Attributes\FromQuery;
use Incoder\DDD\Support\Attributes\FromUri;
use Incoder\DDD\Support\Attributes\RequiresPermission;
use Incoder\DDD\Support\Attributes\RouteAttribute;
use ReflectionClass;
use ReflectionMethod;

class RouteRegistrar
{
    public function __construct(
        private string $baseUri,
        private string $class
    ) {
        // Constructor is now clean with only promoted properties
    }

    public function registerCrudRoutes(): void
    {
        $crudRoutes = [
            'getAll' => ['GET',    '',        'index'],
            'getPaged' => ['GET',    '/paged',  'paged'],
            'getById' => ['GET',    '/{id}',   'show'],
            'create' => ['POST',   '',        'store'],
            'update' => ['PUT',    '/{id}',   'update'],
            'delete' => ['DELETE', '/{id}',   'destroy'],
        ];

        $reflection = new ReflectionClass($this->class);

        foreach ($crudRoutes as $methodName => [$httpMethod, $path, $action]) {
            if (method_exists($this->class, $methodName)) {
                $method = $reflection->getMethod($methodName);
                $middleware = $this->buildEffectiveMiddleware($method);
                $this->registerRoute($httpMethod, $path, $methodName, "app.api.{$this->getEntityName()}.{$action}", $middleware);
            }
        }
    }

    public function registerCustomRoutes(): void
    {
        $reflection = new ReflectionClass($this->class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($this->isStandardCrudMethod($method->getName())) {
                continue;
            }

            // Handle attributed routes first
            if ($this->registerAttributedRoute($method)) {
                continue;
            }

            // Handle convention-based routes
            $this->registerConventionBasedRoute($method);
        }
    }

    private function isStandardCrudMethod(string $methodName): bool
    {
        return in_array($methodName, [
            'getAll', 'getById', 'create', 'update', 'delete', 'getPaged',
        ]);
    }

    private function registerAttributedRoute(ReflectionMethod $method): bool
    {
        $attributes = $method->getAttributes(RouteAttribute::class);

        if (empty($attributes)) {
            return false;
        }

        foreach ($attributes as $attribute) {
            $route = $attribute->newInstance();
            $this->makeAppServiceRoute(
                $route->methods,
                $this->baseUri.'/'.$route->uri,
                $method->getName(),
                (array) $route->middleware
            )->name($route->name);
        }

        return true;
    }

    private function registerConventionBasedRoute(ReflectionMethod $method): void
    {
        $httpMethodPrefixes = [
            'get' => 'GET', 'post' => 'POST', 'put' => 'PUT',
            'patch' => 'PATCH', 'delete' => 'DELETE',
            'options' => 'OPTIONS', 'head' => 'HEAD',
        ];

        $methodName = $method->getName();

        foreach ($httpMethodPrefixes as $prefix => $httpMethod) {
            if (! str_starts_with(strtolower($methodName), $prefix)) {
                continue;
            }

            $baseMethodName = substr($methodName, strlen($prefix));
            $uri = $this->buildUriWithParameters($method, $baseMethodName, $httpMethod);
            $middleware = $this->buildEffectiveMiddleware($method);

            $this->makeAppServiceRoute(
                [$httpMethod],
                $this->baseUri.'/'.$uri,
                $methodName,
                $middleware
            )->name("api.{$this->getEntityName()}.{$uri}");
            break;
        }
    }

    private function buildUriWithParameters(ReflectionMethod $method, string $baseMethodName, string $httpMethod): string
    {
        $uri = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $baseMethodName));

        foreach ($method->getParameters() as $param) {
            if ($this->shouldBeUriParameter($param, $baseMethodName, $httpMethod)) {
                $uri .= "/{{$param->getName()}}";
            }
        }

        return $uri;
    }

    private function shouldBeUriParameter($param, string $baseMethodName, string $httpMethod): bool
    {
        // Check attributes first
        if (! empty($param->getAttributes(FromUri::class))) {
            return true;
        }
        if (! empty($param->getAttributes(FromQuery::class))) {
            return false;
        }
        if (! empty($param->getAttributes(FromBody::class))) {
            return false;
        }

        // Convention-based fallback
        return str_contains($baseMethodName, $param->getName());
    }

    // ── Middleware resolution ────────────────────────────────────────────────

    /**
     * Build the effective middleware list for a given method:
     *   1. Class-level #[AppServiceMiddleware] (defaults to ['api'] if absent).
     *   2. Class-level #[RequiresPermission] (permission middleware appended).
     *   3. Method-level #[RequiresPermission] overrides the class-level permission.
     *
     * #[RouteAttribute] routes are NOT affected — they carry their own middleware.
     */
    private function buildEffectiveMiddleware(ReflectionMethod $method): array
    {
        $middleware = $this->getClassMiddleware();

        // Determine which permission applies: method-level wins over class-level.
        $methodAttrs = $method->getAttributes(RequiresPermission::class);
        if (! empty($methodAttrs)) {
            /** @var RequiresPermission $perm */
            $perm = $methodAttrs[0]->newInstance();
        } else {
            $classAttrs = (new ReflectionClass($this->class))->getAttributes(RequiresPermission::class);
            $perm = ! empty($classAttrs) ? $classAttrs[0]->newInstance() : null;
        }

        if ($perm !== null) {
            $middleware = array_merge($middleware, $perm->toMiddleware());
        }

        return $middleware;
    }

    /**
     * Read #[AppServiceMiddleware] from the class or any parent class; fall back to ['api'].
     * PHP's getAttributes() does not walk the inheritance chain, so we do it manually.
     */
    private function getClassMiddleware(): array
    {
        $current = new ReflectionClass($this->class);

        while ($current !== false) {
            $attrs = $current->getAttributes(AppServiceMiddleware::class);
            if (! empty($attrs)) {
                /** @var AppServiceMiddleware $attr */
                $attr = $attrs[0]->newInstance();

                return $attr->middleware;
            }
            $current = $current->getParentClass();
        }

        return ['api'];   // backward-compatible default
    }

    /**
     * Derive a short kebab-case entity name from the registered class,
     * stripping common suffixes (Controller, AppService, Service).
     *
     * e.g. "App\Http\Controllers\UserAppService" → "user"
     */
    private function getEntityName(): string
    {
        $base = class_basename($this->class);
        $base = preg_replace('/(?:AppService|Controller|Service)$/', '', $base);

        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $base));
    }

    private function registerRoute(
        string $method,
        string $path,
        string $action,
        ?string $name = null,
        array $middleware = ['api']
    ): void {
        $uri = ltrim($this->baseUri.$path, '/');
        $routes = Route::getRoutes();

        // Check for existing route with same method and URI
        foreach ($routes as $route) {
            if (
                in_array(strtoupper($method), $route->methods()) &&
                ltrim($route->uri(), '/') === $uri
            ) {
                // Route already exists, skip registration
                return;
            }
        }

        $this->makeAppServiceRoute([$method], $this->baseUri.$path, $action, $middleware)
            ->name($name ?? "api.{$action}");
    }

    private function makeAppServiceRoute(
        array $methods,
        string $uri,
        string $action,
        array $middleware
    ) {
        return Route::match($methods, $uri, AppServiceRouteController::class)
            ->defaults('_app_service_class', $this->class)
            ->defaults('_app_service_method', $action)
            ->middleware($middleware);
    }
}

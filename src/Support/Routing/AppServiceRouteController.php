<?php

namespace Incoder\DDD\Support\Routing;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Incoder\DDD\Support\Attributes\FromBody;
use Incoder\DDD\Support\Attributes\FromQuery;
use Incoder\DDD\Support\Attributes\FromUri;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Spatie\LaravelData\Data;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AppServiceRouteController
{
    public function __invoke(Request $request): mixed
    {
        $class = $request->route()?->defaults['_app_service_class'] ?? null;
        $method = $request->route()?->defaults['_app_service_method'] ?? null;

        if (! is_string($class) || ! is_string($method)) {
            throw new HttpException(500, 'AppService route metadata is incomplete.');
        }

        $service = app($class);
        $reflection = new ReflectionMethod($service, $method);
        $arguments = $this->resolveMethodArguments($reflection, $request);

        return app()->call([$service, $method], $arguments);
    }

    private function resolveMethodArguments(ReflectionMethod $method, Request $request): array
    {
        $httpMethod = strtoupper($request->method());
        $bodySources = [];

        foreach ($method->getParameters() as $parameter) {
            if ($this->determineParameterSource($parameter, $request, $httpMethod, $method->getName()) === 'body') {
                $bodySources[] = $parameter->getName();
            }
        }

        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $source = $this->determineParameterSource($parameter, $request, $httpMethod, $method->getName());

            if ($source === 'container') {
                continue;
            }

            $resolved = $this->resolveParameterValue($parameter, $source, $request, count($bodySources));

            if ($resolved['resolved']) {
                $arguments[$parameter->getName()] = $resolved['value'];
            }
        }

        return $arguments;
    }

    private function determineParameterSource(
        ReflectionParameter $parameter,
        Request $request,
        string $httpMethod,
        string $methodName
    ): string {
        if (! empty($parameter->getAttributes(FromUri::class))) {
            return 'route';
        }

        if (! empty($parameter->getAttributes(FromQuery::class))) {
            return 'query';
        }

        if (! empty($parameter->getAttributes(FromBody::class))) {
            return 'body';
        }

        if ($this->routeHasParameter($request, $parameter->getName())) {
            return 'route';
        }

        if ($httpMethod === 'GET' || $httpMethod === 'HEAD') {
            return $this->shouldResolveFromContainer($parameter) ? 'container' : 'query';
        }

        if ($this->shouldResolveFromContainer($parameter) && ! $this->requestHasBodyKey($request, $parameter->getName())) {
            return 'container';
        }

        return 'body';
    }

    private function resolveParameterValue(
        ReflectionParameter $parameter,
        string $source,
        Request $request,
        int $bodyParameterCount
    ): array {
        return match ($source) {
            'route' => $this->resolveRouteParameter($parameter, $request),
            'query' => $this->resolveQueryParameter($parameter, $request),
            'body' => $this->resolveBodyParameter($parameter, $request, $bodyParameterCount),
            default => ['resolved' => false, 'value' => null],
        };
    }

    private function resolveRouteParameter(ReflectionParameter $parameter, Request $request): array
    {
        if (! $this->routeHasParameter($request, $parameter->getName())) {
            return ['resolved' => false, 'value' => null];
        }

        return ['resolved' => true, 'value' => $request->route($parameter->getName())];
    }

    private function resolveQueryParameter(ReflectionParameter $parameter, Request $request): array
    {
        $query = $request->query();

        if (array_key_exists($parameter->getName(), $query)) {
            return ['resolved' => true, 'value' => $query[$parameter->getName()]];
        }

        return ['resolved' => false, 'value' => null];
    }

    private function resolveBodyParameter(
        ReflectionParameter $parameter,
        Request $request,
        int $bodyParameterCount
    ): array {
        $typeName = $this->getNamedType($parameter)?->getName();

        if ($typeName !== null && is_a($typeName, UploadedFile::class, true)) {
            if ($request->hasFile($parameter->getName())) {
                return ['resolved' => true, 'value' => $request->file($parameter->getName())];
            }

            return ['resolved' => false, 'value' => null];
        }

        if ($typeName !== null && is_a($typeName, Data::class, true)) {
            return ['resolved' => true, 'value' => $typeName::from($request->all())];
        }

        $payload = $request->all();
        $name = $parameter->getName();

        if (array_key_exists($name, $payload)) {
            return ['resolved' => true, 'value' => $payload[$name]];
        }

        if ($typeName === 'array') {
            if ($bodyParameterCount === 1) {
                return ['resolved' => true, 'value' => $payload];
            }

            return ['resolved' => false, 'value' => null];
        }

        if ($bodyParameterCount === 1 && $typeName === null) {
            return ['resolved' => true, 'value' => $payload];
        }

        return ['resolved' => false, 'value' => null];
    }

    private function shouldResolveFromContainer(ReflectionParameter $parameter): bool
    {
        $type = $this->getNamedType($parameter);

        if ($type === null || $type->isBuiltin()) {
            return false;
        }

        $typeName = $type->getName();

        if (is_a($typeName, Request::class, true)) {
            return true;
        }

        if (is_a($typeName, UploadedFile::class, true)) {
            return false;
        }

        if (is_a($typeName, Data::class, true)) {
            return false;
        }

        return true;
    }

    private function requestHasBodyKey(Request $request, string $name): bool
    {
        return array_key_exists($name, $request->all()) || $request->hasFile($name);
    }

    private function routeHasParameter(Request $request, string $name): bool
    {
        $parameters = $request->route()?->parameters() ?? [];

        return array_key_exists($name, $parameters);
    }

    private function getNamedType(ReflectionParameter $parameter): ?ReflectionNamedType
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType ? $type : null;
    }
}

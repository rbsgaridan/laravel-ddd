<?php

namespace Incoder\DDD\Support\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RouteAttribute
{
    protected const ALLOWED_METHODS = [
        'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'
    ];

    /**
     * @param array|string $methods HTTP methods (e.g. 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD')
     * @param string       $uri      URI path (e.g. "/users")
     * @param string|null  $name     Optional route name
     * @param array|string $middleware Middleware groups or middlewares
     */
    public function __construct(
        public array|string $methods = ['GET'],
        public string $uri = '/',
        public ?string $name = null,
        public array|string $middleware = []
    ) {

        $this->methods = array_map('strtoupper', (array) $this->methods);

        foreach ($this->methods as $method) {
            if (!in_array($method, self::ALLOWED_METHODS, true)) {
                throw new \InvalidArgumentException("Invalid HTTP method: {$method}");
            }
        }
        $this->uri = $uri;
        $this->name = $name;
        $this->middleware = $this->normalizeMiddleware((array) $this->middleware, $this->uri);
    }

    /**
     * Keep web-default behavior for classic web routes while avoiding forced
     * web middleware for API routes.
     */
    private function normalizeMiddleware(array $middleware, string $uri): array
    {
        $middleware = array_values(array_unique(array_filter(array_map(
            static fn ($value) => is_string($value) ? trim($value) : '',
            $middleware
        ))));

        $trimmedUri = ltrim($uri, '/');
        $isApiUri = str_starts_with($trimmedUri, 'api/') || str_starts_with($trimmedUri, 'app/api/');
        $hasWeb = in_array('web', $middleware, true);
        $hasApi = in_array('api', $middleware, true);

        if ($middleware === []) {
            return $isApiUri ? ['api'] : ['web'];
        }

        if ($isApiUri && !$hasApi && !$hasWeb) {
            array_unshift($middleware, 'api');
            return array_values(array_unique($middleware));
        }

        if (!$isApiUri && !$hasWeb && !$hasApi) {
            array_unshift($middleware, 'web');
            return array_values(array_unique($middleware));
        }

        return $middleware;
    }
}

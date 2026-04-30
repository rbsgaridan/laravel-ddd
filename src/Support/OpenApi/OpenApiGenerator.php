<?php

namespace Incoder\DDD\Support\OpenApi;

use Illuminate\Support\Str;
use Incoder\DDD\Support\Attributes\OpenApi\ApiHide;
use Incoder\DDD\Support\Attributes\OpenApi\ApiTag;
use Incoder\DDD\Support\Attributes\RouteAttribute;
use Incoder\DDD\Support\Helper\PackageConfig;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;

/**
 * Main orchestrator for OpenAPI 3.0 spec generation.
 *
 * Scans the application's Controllers directory (the same path used by
 * RouteScanner) and builds a complete OA3 spec array by combining:
 *
 *   1. Route info from #[RouteAttribute]
 *   2. Override meta from #[ApiOperation], #[ApiBody], etc.
 *   3. Auto-inferred summaries, tags, path params, and security from middleware
 *   4. DTO schemas from SchemaInferrer
 *
 * Usage:
 *   $spec = (new OpenApiGenerator())->generate();
 *   $json = json_encode($spec, JSON_PRETTY_PRINT);
 */
class OpenApiGenerator
{
    private SchemaInferrer $schemaInferrer;

    public function __construct()
    {
        $this->schemaInferrer = new SchemaInferrer;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Generate the full OpenAPI 3.0.3 specification as an associative array.
     */
    public function generate(): array
    {
        $spec = $this->buildBaseSpec();

        // ── Controllers ───────────────────────────────────────────────────
        // foreach ($this->findControllerClasses() as $class) {
        //     $this->processController($class, $spec);
        // }

        // ── AppService convention + attributed routes ─────────────────────
        $classMap = PackageConfig::loadClassMap();
        if ($classMap !== []) {
            (new AppServiceDocScanner($this->schemaInferrer))->scan($classMap, $spec);
        }

        // Collect every tag name actually referenced by at least one operation
        $usedTags = [];
        foreach ($spec['paths'] as $pathItem) {
            foreach ($pathItem as $operation) {
                foreach ($operation['tags'] ?? [] as $t) {
                    $usedTags[$t] = true;
                }
            }
        }

        // De-duplicate tags by name AND remove tags with no operations
        $seen = [];
        $spec['tags'] = array_values(array_filter($spec['tags'], function ($tag) use (&$seen, $usedTags) {
            $name = $tag['name'];
            if (isset($seen[$name]) || ! isset($usedTags[$name])) {
                return false;
            }

            return $seen[$name] = true;
        }));

        // Sort paths alphabetically for readability
        ksort($spec['paths']);

        return $spec;
    }

    // -----------------------------------------------------------------------
    // Spec scaffold
    // -----------------------------------------------------------------------

    private function buildBaseSpec(): array
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('api-docs.title', config('app.name', 'API').' API'),
                'description' => config('api-docs.description', ''),
                'version' => config('api-docs.version', '1.0.0'),
            ],
            'servers' => $this->buildServers(),
            'paths' => [],
            'components' => [
                'schemas' => [],
                'securitySchemes' => config('api-docs.security_schemes', [
                    'sessionAuth' => [
                        'type' => 'apiKey',
                        'in' => 'cookie',
                        'name' => 'laravel_session',
                        'description' => 'Laravel session cookie. Log in at /login first — the browser sends it automatically.',
                    ],
                ]),
            ],
            'tags' => [],
        ];

        // Remove empty description
        if ($spec['info']['description'] === '') {
            unset($spec['info']['description']);
        }

        return $spec;
    }

    private function buildServers(): array
    {
        $servers = [['url' => config('app.url', 'http://localhost'), 'description' => 'Application server']];

        foreach (config('api-docs.servers', []) as $server) {
            $servers[] = $server;
        }

        return $servers;
    }

    // -----------------------------------------------------------------------
    // Controller scanning
    // -----------------------------------------------------------------------

    /**
     * @return string[] Fully qualified class names of discovered controllers
     */
    private function findControllerClasses(): array
    {
        $classes = [];
        $finder = new Finder;
        $finder->files()->in(app_path('Http/Controllers'))->name('*Controller.php');

        foreach ($finder as $file) {
            $fqcn = $this->classFromFile($file->getRealPath());
            if ($fqcn && class_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }

    private function classFromFile(string $path): ?string
    {
        $content = file_get_contents($path);

        if (
            preg_match('/namespace\s+(.+?);/s', $content, $nsMatch) &&
            preg_match('/\bclass\s+(\w+)/', $content, $classMatch)
        ) {
            return $nsMatch[1].'\\'.$classMatch[1];
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Per-controller processing
    // -----------------------------------------------------------------------

    private function processController(string $class, array &$spec): void
    {
        $reflection = new ReflectionClass($class);

        // Skip abstract classes and the base Controller
        if ($reflection->isAbstract()) {
            return;
        }

        // Skip if #[ApiHide] on the class
        if (! empty($reflection->getAttributes(ApiHide::class))) {
            return;
        }

        $tag = $this->resolveTag($reflection);
        $tagDesc = $this->resolveTagDescription($reflection);

        // Register the tag (de-duplicated later)
        $spec['tags'][] = array_filter([
            'name' => $tag,
            'description' => $tagDesc,
        ]);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Only methods declared on this controller (not inherited)
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            // Skip if #[ApiHide] on the method
            if (! empty($method->getAttributes(ApiHide::class))) {
                continue;
            }

            $routeAttrs = $method->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (empty($routeAttrs)) {
                continue;
            }

            foreach ($routeAttrs as $routeAttr) {
                /** @var RouteAttribute $route */
                $route = $routeAttr->newInstance();

                if (! in_array('api', (array) $route->middleware, true)) {
                    continue;
                }

                $oaPath = $this->normaliseUri($route->uri);

                $extractor = new RouteDocExtractor(
                    $reflection,
                    $method,
                    $route,
                    $this->schemaInferrer
                );

                $operation = $extractor->extract($tag);

                // Hoist DTO schemas into #/components/schemas
                foreach ($operation['__schemas'] ?? [] as $name => $schema) {
                    $spec['components']['schemas'][$name] = $schema;
                }
                unset($operation['__schemas']);

                // Register each HTTP method as a separate path operation
                foreach ($route->methods as $httpMethod) {
                    $lcMethod = strtolower($httpMethod);
                    if (! isset($spec['paths'][$oaPath])) {
                        $spec['paths'][$oaPath] = [];
                    }
                    $spec['paths'][$oaPath][$lcMethod] = $operation;
                }
            }
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Resolve the tag name for a controller, either from #[ApiTag] or inferred
     * from the controller class name (UserController → Users).
     */
    private function resolveTag(ReflectionClass $reflection): string
    {
        $attrs = $reflection->getAttributes(ApiTag::class);
        if (! empty($attrs)) {
            return $attrs[0]->newInstance()->name;
        }

        $short = $reflection->getShortName();
        $short = preg_replace('/Controller$/', '', $short);

        return Str::plural($short);
    }

    private function resolveTagDescription(ReflectionClass $reflection): string
    {
        $attrs = $reflection->getAttributes(ApiTag::class);
        if (! empty($attrs)) {
            return $attrs[0]->newInstance()->description;
        }

        return '';
    }

    /**
     * Normalise a route URI to an OpenAPI path:
     *   - Ensure leading slash
     *   - Strip trailing slash
     */
    private function normaliseUri(string $uri): string
    {
        $path = '/'.ltrim(rtrim($uri, '/'), '/');

        // {param} is already OA3-compatible; leave as-is
        return $path;
    }
}

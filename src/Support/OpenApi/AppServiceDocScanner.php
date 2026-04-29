<?php

namespace Incoder\DDD\Support\OpenApi;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Illuminate\Support\Str;
use Incoder\DDD\Application\Services\AppServiceBase;
use Incoder\DDD\Support\Attributes\AppServiceMiddleware;
use Incoder\DDD\Support\Attributes\FromBody;
use Incoder\DDD\Support\Attributes\FromQuery;
use Incoder\DDD\Support\Attributes\FromUri;
use Incoder\DDD\Support\Attributes\RequiresPermission;
use Incoder\DDD\Support\Attributes\RouteAttribute;
use Incoder\DDD\Support\Attributes\OpenApi\ApiBody;
use Incoder\DDD\Support\Attributes\OpenApi\ApiHide;
use Incoder\DDD\Support\Attributes\OpenApi\ApiOperation;
use Incoder\DDD\Support\Attributes\OpenApi\ApiParam;
use Incoder\DDD\Support\Attributes\OpenApi\ApiQuery;
use Incoder\DDD\Support\Attributes\OpenApi\ApiResponse;
use Incoder\DDD\Support\Attributes\OpenApi\ApiSecurity;
use Incoder\DDD\Support\Attributes\OpenApi\ApiTag;

/**
 * Generates OpenAPI path items for all AppService classes.
 *
 * Handles the three route registration patterns used by AppServiceRouteScanner
 * + RouteRegistrar:
 *
 *   1. Convention-based CRUD methods  (getAll, getById, create, update, delete, getPaged)
 *      Registered at: /app/api/{service}[/{id}]
 *
 *   2. #[RouteAttribute] on custom public methods
 *      Registered at: /app/api/{service}/{route->uri}
 *
 *   3. HTTP-prefix convention  (getActiveUsers → GET /app/api/{service}/active-users)
 *      Registered at: /app/api/{service}/{kebab-name}[/{params}]
 */
class AppServiceDocScanner
{
    /** CRUD method → [httpMethod, pathSuffix, responseDtoKey] */
    private const CRUD_MAP = [
        'getAll'   => ['GET',    '',       'listDto'],
        'getPaged' => ['GET',    '/paged', 'pagedDto'],
        'getById'  => ['GET',    '/{id}',  'dto'],
        'create'   => ['POST',   '',       'dto'],
        'update'   => ['PUT',    '/{id}',  'dto'],
        'delete'   => ['DELETE', '/{id}',  null],
    ];

    private const HTTP_PREFIXES = [
        'get'     => 'GET',
        'post'    => 'POST',
        'put'     => 'PUT',
        'patch'   => 'PATCH',
        'delete'  => 'DELETE',
        'options' => 'OPTIONS',
        'head'    => 'HEAD',
    ];

    public function __construct(
        private readonly SchemaInferrer $schemaInferrer
    ) {}

    // -----------------------------------------------------------------------
    // Public API  (called by OpenApiGenerator)
    // -----------------------------------------------------------------------

    /**
     * @param array  $classMap  Composer autoload_classmap contents
     * @param array  $spec      The in-progress OA3 spec (mutated in-place)
     */
    public function scan(array $classMap, array &$spec): void
    {
        foreach ($this->findAppServiceClasses($classMap) as $class) {
            $this->processAppService($class, $spec);
        }
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @return string[] FQCNs of concrete AppService subclasses */
    private function findAppServiceClasses(array $classMap): array
    {
        return array_values(array_filter(
            array_keys($classMap),
            fn ($class) =>
                str_starts_with($class, 'Core\\Application\\') &&
                str_ends_with($class, 'AppService') &&
                !str_starts_with(class_basename($class), 'I') &&
                class_exists($class) &&
                is_subclass_of($class, AppServiceBase::class)
        ));
    }

    private function processAppService(string $class, array &$spec): void
    {
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            return;
        }

        // #[ApiHide] on the class → skip entirely
        if (!empty($reflection->getAttributes(ApiHide::class))) {
            return;
        }

        $baseUri = $this->resolveBaseUri($reflection);
        $tag     = $this->resolveTag($reflection);
        $tagDesc = $this->resolveTagDescription($reflection);
        $dtos    = $this->resolveDtoClasses($class);

        // Register tag (duplicates cleaned up by OpenApiGenerator)
        $spec['tags'][] = array_filter(['name' => $tag, 'description' => $tagDesc]);

        $isApiService = $this->classHasApiMiddleware($reflection);

        // 1 ── Convention CRUD methods ──────────────────────────────────────
        foreach (self::CRUD_MAP as $methodName => [$httpMethod, $suffix, $responseDtoKey]) {
            if (!$isApiService) {
                continue;
            }
            if (!method_exists($class, $methodName)) {
                continue;
            }
            $method = $reflection->getMethod($methodName);
            if (!empty($method->getAttributes(ApiHide::class))) {
                continue;
            }

            $oaPath    = $this->normalisePath($baseUri . $suffix);
            $operation = $this->buildCrudOperation(
                $reflection, $method, $httpMethod, $tag,
                $dtos, $responseDtoKey, $spec
            );

            $spec['paths'][$oaPath][strtolower($httpMethod)] = $operation;
        }

        // 2 ── #[RouteAttribute] on custom methods ─────────────────────────
        // 3 ── HTTP-prefix convention routes ───────────────────────────────
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if (array_key_exists($method->getName(), self::CRUD_MAP)) {
                continue;
            }
            if (!empty($method->getAttributes(ApiHide::class))) {
                continue;
            }
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

            // Pattern 2: explicit #[RouteAttribute]
            $routeAttrs = $method->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (!empty($routeAttrs)) {
                foreach ($routeAttrs as $routeAttr) {
                    /** @var RouteAttribute $route */
                    $route   = $routeAttr->newInstance();
                    if (!$this->routeHasApiMiddleware($route)) {
                        continue;
                    }
                    $oaPath  = $this->normalisePath($baseUri . '/' . ltrim($route->uri, '/'));
                    $op      = $this->buildAttributedOperation($reflection, $method, $route, $tag, $dtos, $spec);
                    foreach ($route->methods as $httpMethod) {
                        $spec['paths'][$oaPath][strtolower($httpMethod)] = $op;
                    }
                }
                continue;
            }

            // Pattern 3: HTTP prefix convention
            if ($isApiService) {
                $this->processConventionMethod($reflection, $method, $baseUri, $tag, $dtos, $spec);
            }
        }
    }

    // ── CRUD operation builder ───────────────────────────────────────────────

    private function buildCrudOperation(
        ReflectionClass  $class,
        ReflectionMethod $method,
        string           $httpMethod,
        string           $tag,
        array            $dtos,
        ?string          $responseDtoKey,
        array            &$spec
    ): array {
        $methodName = $method->getName();
        $summary    = $this->inferSummary($methodName);

        $op = [
            'operationId' => $class->getShortName() . '_' . $methodName,
            'tags'        => [$tag],
            'summary'     => $summary,
            'parameters'  => [],
            'responses'   => [],
            'security'    => $this->resolveSecurityForMethod($class),
        ];

        $this->appendPermissionInfo($op, $class, $method);

        // Path param for methods that operate on a single record
        if (in_array($methodName, ['getById', 'update', 'delete'], true)) {
            $op['parameters'][] = [
                'name'     => 'id',
                'in'       => 'path',
                'required' => true,
                'schema'   => ['type' => 'string'],
            ];
        }

        // Standard query params for getPaged (mirrors AppServiceBase::getPaged signature)
        if ($methodName === 'getPaged') {
            $op['parameters'] = [
                ['name' => 'page',     'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 1]],
                ['name' => 'perPage',  'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 10]],
                ['name' => 'filters',  'in' => 'query', 'required' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'object']], 'style' => 'deepObject', 'explode' => true],
                ['name' => 'sort',     'in' => 'query', 'required' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'object']], 'style' => 'deepObject', 'explode' => true],
            ];
        }

        // Request body for create / update
        if (in_array($methodName, ['create', 'update'], true) && isset($dtos['dto'])) {
            $bodyDtoClass = $dtos['dto'];
            if (class_exists($bodyDtoClass)) {
                $this->registerDtoAndNested($bodyDtoClass, $spec);
                $shortName                          = class_basename($bodyDtoClass);
                $op['requestBody'] = [
                    'required' => true,
                    'content'  => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$shortName}"]]],
                ];
            }
        }

        // Default response
        if ($methodName === 'delete') {
            $op['responses']['204'] = ['description' => 'Deleted successfully'];
        } elseif ($responseDtoKey && isset($dtos[$responseDtoKey]) && class_exists($dtos[$responseDtoKey])) {
            $respClass  = $dtos[$responseDtoKey];
            $this->registerDtoAndNested($respClass, $spec);
            $shortName  = class_basename($respClass);
            $op['responses']['200'] = [
                'description' => 'Success',
                'content'     => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$shortName}"]]],
            ];
        } else {
            $op['responses']['200'] = ['description' => 'Success'];
        }

        // Apply any #[ApiOperation] / #[ApiResponse] / #[ApiQuery] overrides
        $op = $this->applyOverrides($class, $method, $op, $dtos, $spec);

        return $op;
    }

    // ── Attributed (custom #[RouteAttribute]) method builder ────────────────

    private function buildAttributedOperation(
        ReflectionClass  $class,
        ReflectionMethod $method,
        RouteAttribute   $route,
        string           $tag,
        array            $dtos,
        array            &$spec
    ): array {
        // Start with path params from URI, then append FromQuery / FromBody method params
        $parameters    = $this->extractPathParams($route->uri);
        $bodyClass     = null;
        $isWriteMethod = !empty(array_intersect($route->methods, ['POST', 'PUT', 'PATCH']));

        foreach ($method->getParameters() as $param) {
            if (!empty($param->getAttributes(FromQuery::class))) {
                $type     = $param->getType();
                $typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : 'string';
                $parameters[] = [
                    'name'     => $param->getName(),
                    'in'       => 'query',
                    'required' => !$param->isOptional() && !($type?->allowsNull()),
                    'schema'   => $this->schemaInferrer->phpTypeNameToSchema($typeName),
                ];
            } elseif (!empty($param->getAttributes(FromBody::class))) {
                // #[FromBody] → request body
                $type     = $param->getType();
                $typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : null;
                if ($typeName && class_exists($typeName)) {
                    $bodyClass = $typeName;
                }
            } elseif ($isWriteMethod && $bodyClass === null) {
                // Unattributed typed DTO param on a write method → infer as body
                $type     = $param->getType();
                $typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : 'string';
                if (class_exists($typeName) && !in_array($typeName, ['array', 'string', 'int', 'float', 'bool', 'mixed'], true)) {
                    $bodyClass = $typeName;
                }
            }
        }

        $op = [
            'operationId' => $class->getShortName() . '_' . $method->getName(),
            'tags'        => [$tag],
            'summary'     => $this->inferSummary($method->getName()),
            'parameters'  => $parameters,
            'responses'   => ['200' => ['description' => 'Success']],
            'security'    => $this->inferSecurity($route),
        ];

        $this->appendPermissionInfo($op, $class, $method);

        // Add requestBody for write methods — explicit #[FromBody]/typed DTO wins, fallback to convention dto
        if ($isWriteMethod) {
            $resolved = $bodyClass
                ?? (isset($dtos['dto']) && class_exists($dtos['dto']) ? $dtos['dto'] : null);

            if ($resolved !== null) {
                $this->registerDtoAndNested($resolved, $spec);
                $shortName = class_basename($resolved);
                $op['requestBody'] = [
                    'required' => true,
                    'content'  => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$shortName}"]]],
                ];
            }
        }

        $op = $this->applyOverrides($class, $method, $op, $dtos, $spec);

        return $op;
    }

    // ── HTTP-prefix convention builder ───────────────────────────────────────

    private function processConventionMethod(
        ReflectionClass  $class,
        ReflectionMethod $method,
        string           $baseUri,
        string           $tag,
        array            $dtos,
        array            &$spec
    ): void {
        $methodName  = $method->getName();
        $methodLower = strtolower($methodName);

        foreach (self::HTTP_PREFIXES as $prefix => $httpMethod) {
            if (!str_starts_with($methodLower, $prefix)) {
                continue;
            }

            $basePart = substr($methodName, strlen($prefix));
            $kebab    = Str::kebab($basePart);

            // Build path params from method signature
            $paramSegments  = [];
            $parameters     = [];
            $explicitBodyClass = null;  // set when a param is annotated #[FromBody]

            foreach ($method->getParameters() as $param) {
                if ($this->isUriParam($param, $basePart)) {
                    $paramSegments[] = '{' . $param->getName() . '}';
                    $type = $param->getType();
                    $typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : 'string';
                    $parameters[] = [
                        'name'     => $param->getName(),
                        'in'       => 'path',
                        'required' => true,
                        'schema'   => $this->schemaInferrer->phpTypeNameToSchema($typeName),
                    ];
                } elseif (!empty($param->getAttributes(FromQuery::class))) {
                    $type     = $param->getType();
                    $typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : 'string';
                    $parameters[] = [
                        'name'     => $param->getName(),
                        'in'       => 'query',
                        'required' => false,
                        'schema'   => $this->schemaInferrer->phpTypeNameToSchema($typeName),
                    ];
                } elseif (!empty($param->getAttributes(FromBody::class))) {
                    // Collect the body DTO type to attach as requestBody below
                    $type     = $param->getType();
                    $typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : null;
                    if ($typeName && class_exists($typeName)) {
                        $explicitBodyClass = $typeName;
                    }
                } else {
                    // No attribute and not matched by name convention.
                    // For GET/HEAD treat as a query param; for others, if it is a
                    // typed DTO class (non-primitive), treat it as the request body.
                    $type     = $param->getType();
                    $typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : 'string';
                    if (in_array($httpMethod, ['GET', 'HEAD'], true)) {
                        $parameters[] = [
                            'name'     => $param->getName(),
                            'in'       => 'query',
                            'required' => !$param->isOptional() && !($type?->allowsNull()),
                            'schema'   => $this->schemaInferrer->phpTypeNameToSchema($typeName),
                        ];
                    } elseif ($explicitBodyClass === null && class_exists($typeName) && !in_array($typeName, ['array', 'string', 'int', 'float', 'bool', 'mixed'], true)) {
                        // Unattributed typed DTO parameter → infer as request body
                        $explicitBodyClass = $typeName;
                    }
                }
            }

            $uri    = rtrim($baseUri . '/' . $kebab . ($paramSegments ? '/' . implode('/', $paramSegments) : ''), '/');
            $oaPath = $this->normalisePath($uri);

            $op = [
                'operationId' => $class->getShortName() . '_' . $methodName,
                'tags'        => [$tag],
                'summary'     => $this->inferSummary($methodName),
                'parameters'  => $parameters,
                'responses'   => ['200' => ['description' => 'Success']],
                'security'    => $this->resolveSecurityForMethod($class),
            ];

            $this->appendPermissionInfo($op, $class, $method);

            // Add requestBody for non-GET methods:
            //   1. #[FromBody] or typed DTO param → use that class
            //   2. Fallback to convention dto if the method takes array/no typed params
            if (!in_array($httpMethod, ['GET', 'HEAD'], true)) {
                $bodyClass = $explicitBodyClass
                    ?? (isset($dtos['dto']) && class_exists($dtos['dto']) ? $dtos['dto'] : null);

                if ($bodyClass !== null) {
                    $this->registerDtoAndNested($bodyClass, $spec);
                    $shortName = class_basename($bodyClass);
                    $op['requestBody'] = [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$shortName}"]]],
                    ];
                }
            }

            $op = $this->applyOverrides($class, $method, $op, $dtos, $spec);

            $spec['paths'][$oaPath][strtolower($httpMethod)] = $op;

            break;  // stop after first matching prefix
        }
    }

    // ── Override attribute applicator ────────────────────────────────────────

    /**
     * Apply #[ApiOperation], #[ApiResponse], #[ApiBody], #[ApiQuery], #[ApiParam],
     * and #[ApiSecurity] overrides to an already-scaffolded operation array.
     */
    private function applyOverrides(
        ReflectionClass  $class,
        ReflectionMethod $method,
        array            $op,
        array            $dtos,
        array            &$spec
    ): array {
        // ApiOperation
        $opAttrs = $method->getAttributes(ApiOperation::class);
        if (!empty($opAttrs)) {
            /** @var ApiOperation $apiOp */
            $apiOp = $opAttrs[0]->newInstance();
            $op['summary'] = $apiOp->summary;
            if ($apiOp->description !== '') {
                $op['description'] = $apiOp->description;
            }
            if (!empty($apiOp->tags)) {
                $op['tags'] = $apiOp->tags;
            }
            if ($apiOp->deprecated) {
                $op['deprecated'] = true;
            }
        }

        // ApiBody
        $bodyAttrs = $method->getAttributes(ApiBody::class);
        if (!empty($bodyAttrs)) {
            /** @var ApiBody $body */
            $body      = $bodyAttrs[0]->newInstance();
            $shortName = class_basename($body->class);
            if (class_exists($body->class)) {
                $this->registerDtoAndNested($body->class, $spec);
            }
            $rb = [
                'required' => $body->required,
                'content'  => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$shortName}"]]],
            ];
            if ($body->description !== '') {
                $rb['description'] = $body->description;
            }
            $op['requestBody'] = $rb;
        }

        // ApiResponse (repeatable -> overrides all defaults if present)
        $respAttrs = $method->getAttributes(ApiResponse::class, \ReflectionAttribute::IS_INSTANCEOF);
        if (!empty($respAttrs)) {
            $op['responses'] = [];
            foreach ($respAttrs as $attr) {
                /** @var ApiResponse $resp */
                $resp     = $attr->newInstance();
                $response = ['description' => $resp->description];
                if ($resp->class !== null && class_exists($resp->class)) {
                    $this->registerDtoAndNested($resp->class, $spec);
                    $sn = class_basename($resp->class);
                    $response['content'] = ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$sn}"]]];
                }
                $op['responses'][(string) $resp->statusCode] = $response;
            }
        }

        // ApiQuery (repeatable)
        foreach ($method->getAttributes(ApiQuery::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            /** @var ApiQuery $q */
            $q     = $attr->newInstance();
            $entry = [
                'name'     => $q->name,
                'in'       => 'query',
                'required' => $q->required,
                'schema'   => ['type' => $q->type],
            ];
            if ($q->description !== '') {
                $entry['description'] = $q->description;
            }
            if ($q->example !== null) {
                $entry['example'] = $q->example;
            }
            // Replace or append
            $existing = array_search($q->name, array_column($op['parameters'], 'name'));
            if ($existing !== false) {
                $op['parameters'][$existing] = $entry;
            } else {
                $op['parameters'][] = $entry;
            }
        }

        // ApiParam (repeatable)
        foreach ($method->getAttributes(ApiParam::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            /** @var ApiParam $p */
            $p     = $attr->newInstance();
            $entry = [
                'name'     => $p->name,
                'in'       => 'path',
                'required' => true,
                'schema'   => ['type' => $p->type],
            ];
            if ($p->description !== '') {
                $entry['description'] = $p->description;
            }
            if ($p->example !== null) {
                $entry['example'] = $p->example;
            }
            $existing = array_search($p->name, array_column($op['parameters'], 'name'));
            if ($existing !== false) {
                $op['parameters'][$existing] = $entry;
            } else {
                $op['parameters'][] = $entry;
            }
        }

        // ApiSecurity (method-level > class-level)
        $methodSec = $method->getAttributes(ApiSecurity::class);
        $classSec  = $class->getAttributes(ApiSecurity::class);
        $secAttrs  = !empty($methodSec) ? $methodSec : $classSec;
        if (!empty($secAttrs)) {
            /** @var ApiSecurity $sec */
            $sec = $secAttrs[0]->newInstance();
            $op['security'] = empty($sec->schemes)
                ? []
                : array_map(fn ($s) => [$s => []], $sec->schemes);
        }

        return $op;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns true when the class uses the 'api' middleware (explicitly or by default).
     * Walks the inheritance chain since PHP's getAttributes() is not inherited.
     */
    private function classHasApiMiddleware(ReflectionClass $reflection): bool
    {
        $current = $reflection;

        while ($current !== false) {
            $attrs = $current->getAttributes(AppServiceMiddleware::class);
            if (!empty($attrs)) {
                /** @var AppServiceMiddleware $attr */
                $attr = $attrs[0]->newInstance();
                return in_array('api', $attr->middleware, true);
            }
            $current = $current->getParentClass();
        }

        return true; // RouteRegistrar defaults to ['api']
    }

    private function routeHasApiMiddleware(RouteAttribute $route): bool
    {
        return in_array('api', (array) $route->middleware, true);
    }

    private function resolveBaseUri(ReflectionClass $reflection): string
    {
        $shortName   = $reflection->getShortName();
        $serviceName = strtolower(str_replace('AppService', '', $shortName));
        return "/app/api/{$serviceName}";
    }

    private function resolveTag(ReflectionClass $reflection): string
    {
        $attrs = $reflection->getAttributes(ApiTag::class);
        if (!empty($attrs)) {
            return $attrs[0]->newInstance()->name;
        }
        $entity = str_replace('AppService', '', $reflection->getShortName());
        return Str::plural($entity);
    }

    private function resolveTagDescription(ReflectionClass $reflection): string
    {
        $attrs = $reflection->getAttributes(ApiTag::class);
        return !empty($attrs) ? $attrs[0]->newInstance()->description : '';
    }

    /**
     * Infer DTO classes by convention from the AppService namespace.
     *
     * UserAppService in Core\Application\Users
     *   → dto       = Core\Application\Users\Contracts\UserDTO
     *   → listDto   = Core\Application\Users\Contracts\UserListDTO
     *   → pagedDto  = Core\Application\Users\Contracts\UserPaginatedDTO
     *
     * @return array{dto: string, listDto: string, pagedDto: string}
     */
    private function resolveDtoClasses(string $serviceClass): array
    {
        $reflection  = new ReflectionClass($serviceClass);
        $namespace   = $reflection->getNamespaceName();
        $entity      = str_replace('AppService', '', $reflection->getShortName());
        $contractsNs = $namespace . '\\Contracts\\';

        return [
            'dto'      => $contractsNs . $entity . 'DTO',
            'listDto'  => $contractsNs . $entity . 'ListDTO',
            'pagedDto' => $contractsNs . $entity . 'PaginatedDTO',
        ];
    }

    /** Extract path parameters from a URI string like /foo/{id}/bar/{slug} */
    private function extractPathParams(string $uri): array
    {
        $params = [];
        preg_match_all('/\{(\w+)\}/', $uri, $matches);
        foreach ($matches[1] ?? [] as $name) {
            $params[] = [
                'name'     => $name,
                'in'       => 'path',
                'required' => true,
                'schema'   => ['type' => 'string'],
            ];
        }
        return $params;
    }

    private function inferSecurity(RouteAttribute $route): array
    {
        foreach ($route->middleware as $mw) {
            if ($mw === 'auth:sanctum' || $mw === 'sanctum') {
                return [['sanctum' => []], ['sessionAuth' => []]];
            }
            if ($mw === 'auth') {
                return [['sessionAuth' => []]];
            }
        }
        return [];
    }

    /**
     * Derive the security requirement from #[AppServiceMiddleware] on the class.
     * Falls back to no security when the class has no attribute (legacy 'api' middleware).
     */
    private function resolveSecurityForMethod(ReflectionClass $class): array
    {
        $attrs = $class->getAttributes(AppServiceMiddleware::class);
        if (empty($attrs)) {
            return [];  // no attribute → legacy ['api'] middleware → no auth in docs
        }

        /** @var AppServiceMiddleware $attr */
        $attr = $attrs[0]->newInstance();
        foreach ($attr->middleware as $mw) {
            if ($mw === 'auth:sanctum' || $mw === 'sanctum') {
                return [['sanctum' => []], ['sessionAuth' => []]];
            }
            if ($mw === 'auth') {
                return [['sessionAuth' => []]];
            }
        }
        return [];
    }

    /**
     * Append permission information to an operation array.
     *
     * Method-level #[RequiresPermission] overrides class-level.
     * Adds an 'x-permissions' extension (machine-readable) and a markdown
     * note to 'description' (visible in Swagger UI).
     */
    private function appendPermissionInfo(array &$op, ReflectionClass $class, ReflectionMethod $method): void
    {
        $methodAttrs = $method->getAttributes(RequiresPermission::class);
        if (!empty($methodAttrs)) {
            /** @var RequiresPermission $perm */
            $perm = $methodAttrs[0]->newInstance();
        } else {
            $classAttrs = $class->getAttributes(RequiresPermission::class);
            $perm  = !empty($classAttrs) ? $classAttrs[0]->newInstance() : null;
        }

        if ($perm === null || empty($perm->permissions)) {
            return;
        }

        $op['x-permissions'] = $perm->permissions;

        $permList = implode('`, `', $perm->permissions);
        $note     = "\n\n⚠️ **Required permission(s):** `{$permList}`";
        $op['description'] = ($op['description'] ?? '') . $note;
    }

    private function inferSummary(string $methodName): string
    {
        $words = preg_replace_callback('/([A-Z])/', fn ($m) => ' ' . $m[1], $methodName);
        return ucwords(strtolower(trim($words)));
    }

    private function normalisePath(string $uri): string
    {
        return '/' . ltrim(rtrim($uri, '/'), '/');
    }

    /**
     * Decides whether a method parameter should become a URI path segment,
     * mirroring the logic in RouteRegistrar::shouldBeUriParameter().
     */
    private function isUriParam(\ReflectionParameter $param, string $baseMethodName): bool
    {
        if (!empty($param->getAttributes(FromUri::class)))  return true;
        if (!empty($param->getAttributes(FromQuery::class))) return false;
        if (!empty($param->getAttributes(FromBody::class)))  return false;
        return str_contains(strtolower($baseMethodName), strtolower($param->getName()));
    }

    /**
     * Register a DTO schema and all nested DTOs recursively.
     * Prevents missing schema errors when one DTO references another.
     */
    private function registerDtoAndNested(string $dtoClass, array &$spec): void
    {
        $shortName = class_basename($dtoClass);

        // Avoid re-registering
        if (isset($spec['components']['schemas'][$shortName])) {
            return;
        }

        // Register this DTO
        $spec['components']['schemas'][$shortName] = $this->schemaInferrer->fromClass($dtoClass);

        // Recursively register all nested DTOs
        foreach ($this->schemaInferrer->getNestedDtoClasses($dtoClass) as $nestedShortName) {
            // Try to resolve short name to full class name
            $nestedClass = $this->resolveClassFromShortName($nestedShortName);
            if ($nestedClass && class_exists($nestedClass)) {
                $this->registerDtoAndNested($nestedClass, $spec);
            }
        }
    }

    /**
     * Attempt to resolve a short class name (e.g. "ItemPositionDTO")
     * to a fully qualified class name using the DDD convention path:
     * Core\Application\{PluralStudlyName}\Contracts\{ClassName}
     */
    private function resolveClassFromShortName(string $shortName): ?string
    {
        // Strip "DTO" suffix to get base name: ItemPositionDTO → ItemPosition
        $baseName = str_replace('DTO', '', $shortName);

        // Pluralize to get namespace folder: ItemPosition → ItemPositions
        $pluralName = Str::pluralStudly($baseName);

        // Build FQCN: Core\Application\ItemPositions\Contracts\ItemPositionDTO
        $fqcn = "Core\\Application\\{$pluralName}\\Contracts\\{$shortName}";

        if (class_exists($fqcn)) {
            return $fqcn;
        }

        return null;
    }
}

<?php

namespace Incoder\DDD\Support\OpenApi;

use Incoder\DDD\Support\Attributes\FromBody;
use Incoder\DDD\Support\Attributes\FromQuery;
use Incoder\DDD\Support\Attributes\OpenApi\ApiBody;
use Incoder\DDD\Support\Attributes\OpenApi\ApiOperation;
use Incoder\DDD\Support\Attributes\OpenApi\ApiParam;
use Incoder\DDD\Support\Attributes\OpenApi\ApiQuery;
use Incoder\DDD\Support\Attributes\OpenApi\ApiResponse;
use Incoder\DDD\Support\Attributes\OpenApi\ApiSecurity;
use Incoder\DDD\Support\Attributes\RouteAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Extracts a single OpenAPI path-item operation object from a controller method
 * that carries a RouteAttribute.
 *
 * The caller receives:
 *   - The operation array (OA3 compliant)
 *   - A side-channel `__schemas` key containing any DTO schemas to register
 *     in #/components/schemas.
 */
class RouteDocExtractor
{
    /** DTO schemas collected while building this operation */
    private array $collectedSchemas = [];

    public function __construct(
        private readonly ReflectionClass $class,
        private readonly ReflectionMethod $method,
        private readonly RouteAttribute $route,
        private readonly SchemaInferrer $schemaInferrer
    ) {}

    // -----------------------------------------------------------------------
    // Public entry point
    // -----------------------------------------------------------------------

    /**
     * Build the OA3 operation object.
     *
     * @param  string  $defaultTag  Inferred tag from the controller name
     * @return array OA3 operation array + '__schemas' sidecar key
     */
    public function extract(string $defaultTag): array
    {
        $operation = [
            'operationId' => $this->buildOperationId(),
            'tags' => [$defaultTag],
            'summary' => $this->inferSummary(),
            'description' => '',
            'parameters' => $this->buildParameters(),
            'responses' => $this->buildResponses(),
            'security' => $this->buildSecurity(),
        ];

        // ── ApiOperation override ────────────────────────────────────────
        $opAttrs = $this->method->getAttributes(ApiOperation::class);
        if (! empty($opAttrs)) {
            /** @var ApiOperation $op */
            $op = $opAttrs[0]->newInstance();
            $operation['summary'] = $op->summary;
            $operation['description'] = $op->description;
            if (! empty($op->tags)) {
                $operation['tags'] = $op->tags;
            }
            if ($op->deprecated) {
                $operation['deprecated'] = true;
            }
        }

        // ── Request body ─────────────────────────────────────────────────
        $body = $this->buildRequestBody();
        if ($body !== null) {
            $operation['requestBody'] = $body;
        }

        // ── Remove description if empty ───────────────────────────────────
        if ($operation['description'] === '') {
            unset($operation['description']);
        }

        // ── Attach collected schemas for the generator to hoist ───────────
        $operation['__schemas'] = $this->collectedSchemas;

        return $operation;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function buildOperationId(): string
    {
        return $this->class->getShortName().'_'.$this->method->getName();
    }

    private function inferSummary(): string
    {
        $name = $this->method->getName();
        // camelCase → space-separated words: listActiveUsers → List Active Users
        $words = preg_replace_callback('/([A-Z])/', fn ($m) => ' '.$m[1], $name);

        return ucwords(strtolower(trim($words)));
    }

    // ── Parameters ──────────────────────────────────────────────────────────

    private function buildParameters(): array
    {
        $params = [];  // keyed to de-duplicate

        // 1. Auto-detect path params from {param} in URI
        preg_match_all('/\{(\w+)\}/', $this->route->uri, $matches);
        foreach ($matches[1] ?? [] as $name) {
            $params['path_'.$name] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }

        // 2. Override / enrich path params via #[ApiParam]
        foreach ($this->method->getAttributes(ApiParam::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            /** @var ApiParam $p */
            $p = $attr->newInstance();
            $entry = [
                'name' => $p->name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => $p->type],
            ];
            if ($p->description !== '') {
                $entry['description'] = $p->description;
            }
            if ($p->example !== null) {
                $entry['example'] = $p->example;
            }
            $params['path_'.$p->name] = $entry;
        }

        // 3. Auto-detect query params from #[FromQuery] on method parameters
        foreach ($this->method->getParameters() as $param) {
            if (empty($param->getAttributes(FromQuery::class))) {
                continue;
            }
            $pName = $param->getName();
            $type = $param->getType();
            $typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : 'string';
            $entry = [
                'name' => $pName,
                'in' => 'query',
                'required' => ! $param->isOptional() && ! ($type?->allowsNull()),
                'schema' => $this->schemaInferrer->phpTypeNameToSchema($typeName),
            ];
            $params['query_'.$pName] = $entry;
        }

        // 4. Explicit #[ApiQuery] overrides / additions
        foreach ($this->method->getAttributes(ApiQuery::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            /** @var ApiQuery $q */
            $q = $attr->newInstance();
            $entry = [
                'name' => $q->name,
                'in' => 'query',
                'required' => $q->required,
                'schema' => ['type' => $q->type],
            ];
            if ($q->description !== '') {
                $entry['description'] = $q->description;
            }
            if ($q->example !== null) {
                $entry['example'] = $q->example;
            }
            $params['query_'.$q->name] = $entry;
        }

        return array_values($params);
    }

    // ── Request body ────────────────────────────────────────────────────────

    private function buildRequestBody(): ?array
    {
        // Priority 1: explicit #[ApiBody] on the method
        $bodyAttrs = $this->method->getAttributes(ApiBody::class);
        if (! empty($bodyAttrs)) {
            /** @var ApiBody $body */
            $body = $bodyAttrs[0]->newInstance();
            $shortName = class_basename($body->class);
            $this->collectedSchemas[$shortName] = $this->schemaInferrer->fromClass($body->class);

            $result = [
                'required' => $body->required,
                'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$shortName}"]]],
            ];
            if ($body->description !== '') {
                $result['description'] = $body->description;
            }

            return $result;
        }

        // Priority 2: method parameter with #[FromBody] and a typed DTO class
        foreach ($this->method->getParameters() as $param) {
            if (empty($param->getAttributes(FromBody::class))) {
                continue;
            }
            $type = $param->getType();
            if (! ($type instanceof ReflectionNamedType) || ! class_exists($type->getName())) {
                continue;
            }
            $className = $type->getName();
            $shortName = class_basename($className);
            $this->collectedSchemas[$shortName] = $this->schemaInferrer->fromClass($className);

            return [
                'required' => ! $type->allowsNull(),
                'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$shortName}"]]],
            ];
        }

        return null;
    }

    // ── Responses ───────────────────────────────────────────────────────────

    private function buildResponses(): array
    {
        $responses = [];
        $respAttrs = $this->method->getAttributes(ApiResponse::class, \ReflectionAttribute::IS_INSTANCEOF);

        if (! empty($respAttrs)) {
            foreach ($respAttrs as $attr) {
                /** @var ApiResponse $resp */
                $resp = $attr->newInstance();
                $response = ['description' => $resp->description];

                if ($resp->class !== null && class_exists($resp->class)) {
                    $shortName = class_basename($resp->class);
                    $this->collectedSchemas[$shortName] = $this->schemaInferrer->fromClass($resp->class);
                    $response['content'] = [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$shortName}"],
                        ],
                    ];
                }

                $responses[(string) $resp->statusCode] = $response;
            }
        } else {
            // Default: a generic 200 response
            $responses['200'] = ['description' => 'Success'];
        }

        return $responses;
    }

    // ── Security ────────────────────────────────────────────────────────────

    private function buildSecurity(): array
    {
        // Method-level attribute takes precedence, then class-level
        $methodSec = $this->method->getAttributes(ApiSecurity::class);
        $classSec = $this->class->getAttributes(ApiSecurity::class);

        $secAttrs = ! empty($methodSec) ? $methodSec : $classSec;

        if (! empty($secAttrs)) {
            /** @var ApiSecurity $sec */
            $sec = $secAttrs[0]->newInstance();

            if (empty($sec->schemes)) {
                return [];   // explicitly public
            }

            return array_map(fn ($scheme) => [$scheme => []], $sec->schemes);
        }

        // Auto-infer from route middleware.
        // auth:sanctum  →  accepts both Bearer token (mobile) and session cookie (SPA)
        // auth           →  session cookie only (web guard)
        foreach ((array) $this->route->middleware as $mw) {
            if ($mw === 'auth:sanctum' || $mw === 'sanctum') {
                return [['sanctum' => []], ['sessionAuth' => []]];
            }
            if ($mw === 'auth') {
                return [['sessionAuth' => []]];
            }
        }

        return [];
    }
}

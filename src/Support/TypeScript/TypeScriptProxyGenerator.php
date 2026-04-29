<?php

namespace Incoder\DDD\Support\TypeScript;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Incoder\DDD\Application\Services\AppServiceBase;
use Incoder\DDD\Support\OpenApi\OpenApiGenerator;
use Incoder\DDD\Support\OpenApi\SchemaInferrer;
use ReflectionClass;
use Spatie\LaravelData\Data;

class TypeScriptProxyGenerator
{
    public function __construct(
        private readonly Filesystem $files = new Filesystem(),
        private readonly SchemaInferrer $schemaInferrer = new SchemaInferrer(),
    ) {
    }

    /**
     * @return array{services:int, dtoSchemas:int, outputPath:string, files:string[]}
     */
    public function generate(string $outputPath): array
    {
        $classMap = require base_path('vendor/composer/autoload_classmap.php');
        $appServices = $this->findAppServiceClasses($classMap);
        $spec = (new OpenApiGenerator())->generate();

        $operationsByService = $this->collectOperations($spec);
        $dtoSchemas = $this->collectDtoSchemas($classMap, $appServices);

        foreach (($spec['components']['schemas'] ?? []) as $schemaName => $schema) {
            $dtoSchemas[$schemaName] ??= $schema;
        }

        $root = base_path($outputPath);
        $servicesDir = $root . DIRECTORY_SEPARATOR . 'services';

        $this->files->ensureDirectoryExists($servicesDir);
        $this->deleteGeneratedFiles($root, $servicesDir);

        $writtenFiles = [];

        $modelsPath = $root . DIRECTORY_SEPARATOR . 'models.ts';
        $this->files->put($modelsPath, $this->buildModelsFile($dtoSchemas));
        $writtenFiles[] = $modelsPath;

        $runtimePath = $root . DIRECTORY_SEPARATOR . 'runtime.ts';
        $this->files->put($runtimePath, $this->buildRuntimeFile());
        $writtenFiles[] = $runtimePath;

        $serviceExportLines = [];

        ksort($operationsByService);

        foreach ($operationsByService as $serviceName => $serviceMeta) {
            $servicePath = $servicesDir . DIRECTORY_SEPARATOR . $serviceName . '.ts';
            $this->files->put($servicePath, $this->buildServiceFile($serviceName, $serviceMeta));
            $writtenFiles[] = $servicePath;
            $serviceExportLines[] = "export * from './{$serviceName}';";
        }

        $servicesIndexPath = $servicesDir . DIRECTORY_SEPARATOR . 'index.ts';
        $this->files->put(
            $servicesIndexPath,
            $this->generatedHeader('Generated service exports.') . implode("\n", $serviceExportLines) . "\n"
        );
        $writtenFiles[] = $servicesIndexPath;

        $indexPath = $root . DIRECTORY_SEPARATOR . 'index.ts';
        $this->files->put($indexPath, $this->buildRootIndexFile());
        $writtenFiles[] = $indexPath;

        return [
            'services' => count($operationsByService),
            'dtoSchemas' => count($dtoSchemas),
            'outputPath' => $root,
            'files' => $writtenFiles,
        ];
    }

    /**
     * @param array<class-string, string> $classMap
     * @return array<int, class-string>
     */
    private function findAppServiceClasses(array $classMap): array
    {
        return array_values(array_filter(
            array_keys($classMap),
            fn (string $class) =>
                str_starts_with($class, 'Core\\Application\\')
                && str_ends_with($class, 'AppService')
                && !str_starts_with(class_basename($class), 'I')
                && class_exists($class)
                && is_subclass_of($class, AppServiceBase::class)
        ));
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, array{basePath:string, operations:array<int, array<string, mixed>>}>
     */
    private function collectOperations(array $spec): array
    {
        $grouped = [];

        foreach (($spec['paths'] ?? []) as $path => $methods) {
            if (!str_starts_with($path, '/app/api/')) {
                continue;
            }

            foreach ($methods as $httpMethod => $operation) {
                $operationId = (string) ($operation['operationId'] ?? '');
                if ($operationId === '' || !str_contains($operationId, '_')) {
                    continue;
                }

                [$serviceName, $methodName] = explode('_', $operationId, 2);
                $basePath = $this->extractServiceBasePath($path);

                $grouped[$serviceName] ??= [
                    'basePath' => $basePath,
                    'operations' => [],
                ];

                $grouped[$serviceName]['operations'][] = [
                    'name' => $methodName,
                    'path' => $path,
                    'httpMethod' => strtoupper((string) $httpMethod),
                    'summary' => $operation['summary'] ?? $methodName,
                    'parameters' => $operation['parameters'] ?? [],
                    'requestBody' => $operation['requestBody'] ?? null,
                    'responses' => $operation['responses'] ?? [],
                ];
            }
        }

        foreach ($grouped as &$serviceMeta) {
            usort(
                $serviceMeta['operations'],
                fn (array $a, array $b) => [$a['name'], $a['httpMethod'], $a['path']] <=> [$b['name'], $b['httpMethod'], $b['path']]
            );
        }

        return $grouped;
    }

    /**
     * @param array<class-string, string> $classMap
     * @param array<int, class-string> $appServices
     * @return array<string, array<string, mixed>>
     */
    private function collectDtoSchemas(array $classMap, array $appServices): array
    {
        $schemas = [];

        foreach ($appServices as $serviceClass) {
            $serviceReflection = new ReflectionClass($serviceClass);
            $contractsPrefix = $serviceReflection->getNamespaceName() . '\\Contracts\\';

            foreach (array_keys($classMap) as $class) {
                if (!str_starts_with($class, $contractsPrefix)) {
                    continue;
                }

                if (!class_exists($class) || !is_subclass_of($class, Data::class)) {
                    continue;
                }

                $schemas[class_basename($class)] = $this->schemaInferrer->fromClass($class);
            }
        }

        ksort($schemas);

        return $schemas;
    }

    private function buildModelsFile(array $schemas): string
    {
        $lines = [
            $this->generatedHeader('Generated DTO and schema-backed TypeScript models.'),
        ];

        foreach ($schemas as $schemaName => $schema) {
            $lines[] = $this->renderNamedSchema((string) $schemaName, is_array($schema) ? $schema : []);
            $lines[] = '';
        }

        foreach ($this->findMissingSchemaReferences($schemas) as $missingType) {
            $lines[] = "export type {$missingType} = Record<string, unknown>;";
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    private function buildRuntimeFile(): string
    {
        return $this->generatedHeader('Generated proxy runtime helpers.') . <<<'TS'
export type ProxyQueryValue =
    | string
    | number
    | boolean
    | null
    | undefined
    | ProxyQueryValue[]
    | { [key: string]: ProxyQueryValue };

export type ProxyQueryParams = Record<string, ProxyQueryValue>;

export function buildUrl(pathTemplate: string, pathParams: object = {}): string {
    const values = pathParams as Record<string, unknown>;

    return pathTemplate.replace(/\{(\w+)\}/g, (_, key: string) => {
        if (!(key in values)) {
            throw new Error(`Missing required path parameter: ${key}`);
        }

        return encodeURIComponent(String(values[key]));
    });
}

export function normalizePayload<T>(payload: unknown): T {
    if (payload && typeof payload === 'object') {
        const record = payload as Record<string, unknown>;

        if ('dto' in record && 'entity' in record) {
            return record.dto as T;
        }

        if ('result' in record) {
            const keys = Object.keys(record);
            const wrapperKeys = new Set(['result', 'message', 'status', 'success', 'errors']);

            if (keys.every((key) => wrapperKeys.has(key))) {
                return record.result as T;
            }
        }
    }

    return payload as T;
}
TS;
    }

    private function buildRootIndexFile(): string
    {
        return $this->generatedHeader('Generated proxy entrypoint.') . <<<'TS'
export * from './models';
export * from './runtime';
export * from './services';
TS;
    }

    /**
     * @param array{basePath:string, operations:array<int, array<string, mixed>>} $serviceMeta
     */
    private function buildServiceFile(string $serviceName, array $serviceMeta): string
    {
        $typeImports = [];
        $helperTypeBlocks = [];
        $methods = [];

        foreach ($serviceMeta['operations'] as $operation) {
            $prepared = $this->prepareOperation($serviceName, $serviceMeta['basePath'], $operation);
            $typeImports = array_merge($typeImports, $prepared['typeImports']);
            $helperTypeBlocks = array_merge($helperTypeBlocks, $prepared['helperTypeBlocks']);
            $methods[] = $prepared['method'];
        }

        $typeImports = array_values(array_unique(array_filter($typeImports)));
        sort($typeImports);
        $helperTypeBlocks = array_values(array_unique($helperTypeBlocks));

        $imports = ["import axios from 'axios';"];

        if (!empty($typeImports)) {
            $imports[] = "import type { " . implode(', ', $typeImports) . " } from '../models';";
        }

        $imports[] = "import { buildUrl, normalizePayload } from '../runtime';";

        $className = $serviceName . 'Proxy';
        $instanceName = Str::camel($className);

        $lines = [
            $this->generatedHeader("Generated proxy for {$serviceName}."),
            implode("\n", $imports),
            '',
        ];

        if (!empty($helperTypeBlocks)) {
            $lines[] = implode("\n\n", $helperTypeBlocks);
            $lines[] = '';
        }

        $lines[] = "export class {$className} {";
        $lines[] = "    private readonly baseUrl = '{$serviceMeta['basePath']}';";
        $lines[] = '';

        foreach ($methods as $index => $method) {
            $lines[] = $this->indent($method, 4);
            if ($index !== array_key_last($methods)) {
                $lines[] = '';
            }
        }

        $lines[] = '}';
        $lines[] = '';
        $lines[] = "export const {$instanceName} = new {$className}();";

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $operation
     * @return array{typeImports:array<int, string>, helperTypeBlocks:array<int, string>, method:string}
     */
    private function prepareOperation(string $serviceName, string $basePath, array $operation): array
    {
        $httpMethod = strtoupper((string) $operation['httpMethod']);
        $methodName = (string) $operation['name'];
        $responseType = $this->resolveResponseType($operation['responses'] ?? []);
        if ($methodName === 'getAll' && $responseType !== 'unknown' && $responseType !== 'void' && !str_starts_with($responseType, 'Array<')) {
            $responseType = "Array<{$responseType}>";
        }
        $requestBodySchema = $this->extractSchemaFromRequestBody($operation['requestBody']);
        $bodyType = $requestBodySchema ? $this->schemaToTsType($requestBodySchema) : null;
        $bodyNamedType = $requestBodySchema ? $this->extractNamedTypes($requestBodySchema) : [];

        $pathParams = [];
        $queryParams = [];

        foreach ($operation['parameters'] as $parameter) {
            $location = $parameter['in'] ?? null;
            if ($location === 'path') {
                $pathParams[] = $parameter;
            } elseif ($location === 'query') {
                $queryParams[] = $parameter;
            }
        }

        $helperTypes = [];
        $typeImports = $this->extractNamedTypes($this->extractResponseSchema($operation['responses'] ?? []));
        $typeImports = array_merge($typeImports, $bodyNamedType);

        $usesParamsObject = count($pathParams) > 1 || count($queryParams) > 0;
        $paramsTypeName = null;

        if ($usesParamsObject) {
            $paramsTypeName = $serviceName . Str::studly($methodName) . 'Params';
            $helperTypes[] = $this->renderParamsType($paramsTypeName, array_merge($pathParams, $queryParams));
        }

        $signatureArgs = [];
        $urlExpression = 'this.baseUrl';
        $pathParamsVariable = 'undefined';
        $queryParamsVariable = null;

        if ($usesParamsObject && $paramsTypeName !== null) {
            $signatureArgs[] = "params: {$paramsTypeName}";
            $pathParamsVariable = 'params';
            $queryParamsVariable = !empty($queryParams) ? $this->renderQueryObject('params', $queryParams) : null;
        } elseif (count($pathParams) === 1) {
            $pathParam = $pathParams[0];
            $pathParamType = $this->schemaToTsType($pathParam['schema'] ?? ['type' => 'string']);
            $signatureArgs[] = $pathParam['name'] . ': ' . $pathParamType;
            $pathParamsVariable = '{ ' . $pathParam['name'] . ' }';
        } elseif (!empty($queryParams)) {
            $paramsTypeName = $serviceName . Str::studly($methodName) . 'QueryParams';
            $helperTypes[] = $this->renderParamsType($paramsTypeName, $queryParams);
            $signatureArgs[] = "params: {$paramsTypeName}";
            $queryParamsVariable = 'params';
        }

        if (!empty($pathParams)) {
            $urlExpression = "buildUrl('{$operation['path']}', {$pathParamsVariable})";
        } elseif ($operation['path'] !== $basePath) {
            $urlExpression = "'" . $operation['path'] . "'";
        }

        if ($bodyType !== null) {
            $signatureArgs[] = 'data: ' . $bodyType;
        }

        $responseTypeImport = $this->extractNamedTypes($this->extractResponseSchema($operation['responses'] ?? []));
        $typeImports = array_merge($typeImports, $responseTypeImport);
        $typeImports = array_values(array_unique($typeImports));

        $signature = implode(', ', $signatureArgs);
        $returnType = $responseType === 'void' ? 'Promise<void>' : 'Promise<' . $responseType . '>';

        $methodLines = [];
        $methodLines[] = '/**';
        $methodLines[] = " * {$httpMethod} {$operation['path']}";
        $methodLines[] = ' */';
        $methodLines[] = "async {$methodName}({$signature}): {$returnType} {";

        $requestUrlExpression = $operation['path'] === $basePath ? 'this.baseUrl' : $urlExpression;

        $configExpression = $queryParamsVariable ? "{ params: {$queryParamsVariable} }" : null;

        if ($responseType === 'void') {
            $axiosCall = $this->buildAxiosCall($httpMethod, $requestUrlExpression, $configExpression, $bodyType !== null ? 'data' : null, false);
            $methodLines[] = '    await ' . $axiosCall . ';';
            $methodLines[] = '}';
        } else {
            $axiosCall = $this->buildAxiosCall($httpMethod, $requestUrlExpression, $configExpression, $bodyType !== null ? 'data' : null, true, $responseType);
            $methodLines[] = '    const response = await ' . $axiosCall . ';';
            $methodLines[] = "    return normalizePayload<{$responseType}>(response.data);";
            $methodLines[] = '}';
        }

        return [
            'typeImports' => $typeImports,
            'helperTypeBlocks' => $helperTypes,
            'method' => implode("\n", $methodLines),
        ];
    }

    /**
     * @param array<string, mixed>|null $requestBody
     * @return array<string, mixed>|null
     */
    private function extractSchemaFromRequestBody(mixed $requestBody): ?array
    {
        if (!is_array($requestBody)) {
            return null;
        }

        $content = $requestBody['content'] ?? null;
        if (!is_array($content) || empty($content)) {
            return null;
        }

        $firstMediaType = reset($content);
        if (!is_array($firstMediaType)) {
            return null;
        }

        return is_array($firstMediaType['schema'] ?? null) ? $firstMediaType['schema'] : null;
    }

    /**
     * @param array<string, mixed> $responses
     */
    private function resolveResponseType(array $responses): string
    {
        $schema = $this->extractResponseSchema($responses);

        if ($schema === null) {
            foreach (array_keys($responses) as $statusCode) {
                if ((string) $statusCode === '204') {
                    return 'void';
                }
            }

            return 'unknown';
        }

        return $this->schemaToTsType($schema);
    }

    /**
     * @param array<string, mixed> $responses
     * @return array<string, mixed>|null
     */
    private function extractResponseSchema(array $responses): ?array
    {
        $preferredCodes = ['200', '201', '202', '203', '206'];

        foreach ($preferredCodes as $statusCode) {
            $response = $responses[$statusCode] ?? null;
            $schema = $this->extractSchemaFromResponse($response);
            if ($schema !== null) {
                return $schema;
            }
        }

        foreach ($responses as $response) {
            $schema = $this->extractSchemaFromResponse($response);
            if ($schema !== null) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractSchemaFromResponse(mixed $response): ?array
    {
        if (!is_array($response)) {
            return null;
        }

        $content = $response['content'] ?? null;
        if (!is_array($content) || empty($content)) {
            return null;
        }

        $firstMediaType = reset($content);
        if (!is_array($firstMediaType)) {
            return null;
        }

        return is_array($firstMediaType['schema'] ?? null) ? $firstMediaType['schema'] : null;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function extractNamedTypes(?array $schema): array
    {
        if ($schema === null) {
            return [];
        }

        $namedTypes = [];

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $name = basename(str_replace('\\', '/', $schema['$ref']));
            if ($name !== 'UploadedFile') {
                $namedTypes[] = $name;
            }
        }

        foreach (['items', 'additionalProperties'] as $childKey) {
            if (isset($schema[$childKey]) && is_array($schema[$childKey])) {
                $namedTypes = array_merge($namedTypes, $this->extractNamedTypes($schema[$childKey]));
            }
        }

        foreach (['allOf', 'oneOf', 'anyOf'] as $listKey) {
            if (!isset($schema[$listKey]) || !is_array($schema[$listKey])) {
                continue;
            }

            foreach ($schema[$listKey] as $item) {
                if (is_array($item)) {
                    $namedTypes = array_merge($namedTypes, $this->extractNamedTypes($item));
                }
            }
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $propertySchema) {
                if (is_array($propertySchema)) {
                    $namedTypes = array_merge($namedTypes, $this->extractNamedTypes($propertySchema));
                }
            }
        }

        return array_values(array_unique($namedTypes));
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function schemaToTsType(array $schema): string
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $name = basename(str_replace('\\', '/', $schema['$ref']));
            return $name === 'UploadedFile' ? 'File' : $name;
        }

        if (isset($schema['allOf']) && is_array($schema['allOf']) && !empty($schema['allOf'])) {
            $parts = array_map(
                fn (array $item): string => $this->schemaToTsType($item),
                array_values(array_filter($schema['allOf'], 'is_array'))
            );

            $type = implode(' & ', array_filter($parts));
            return $this->applyNullable($type !== '' ? $type : 'unknown', $schema);
        }

        foreach (['oneOf', 'anyOf'] as $unionKey) {
            if (isset($schema[$unionKey]) && is_array($schema[$unionKey]) && !empty($schema[$unionKey])) {
                $parts = array_map(
                    fn (array $item): string => $this->schemaToTsType($item),
                    array_values(array_filter($schema[$unionKey], 'is_array'))
                );
                $type = implode(' | ', array_filter($parts));
                return $this->applyNullable($type !== '' ? $type : 'unknown', $schema);
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            $values = array_map(
                static fn ($value): string => is_string($value)
                    ? "'" . str_replace("'", "\\'", trim($value, '"')) . "'"
                    : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value),
                $schema['enum']
            );

            return $this->applyNullable(implode(' | ', $values), $schema);
        }

        $type = $schema['type'] ?? null;

        if ($type === 'array') {
            $itemType = isset($schema['items']) && is_array($schema['items'])
                ? $this->schemaToTsType($schema['items'])
                : 'unknown';

            return $this->applyNullable("Array<{$itemType}>", $schema);
        }

        if ($type === 'object' || isset($schema['properties']) || isset($schema['additionalProperties'])) {
            if (isset($schema['properties']) && is_array($schema['properties'])) {
                $required = array_values(array_filter($schema['required'] ?? [], 'is_string'));
                $lines = ["{"];

                foreach ($schema['properties'] as $propertyName => $propertySchema) {
                    $propertyType = is_array($propertySchema) ? $this->schemaToTsType($propertySchema) : 'unknown';
                    $optionalMarker = in_array((string) $propertyName, $required, true) ? '' : '?';
                    $safeName = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $propertyName)
                        ? $propertyName
                        : "'" . str_replace("'", "\\'", (string) $propertyName) . "'";

                    $lines[] = "  {$safeName}{$optionalMarker}: {$propertyType};";
                }

                $lines[] = "}";

                return $this->applyNullable(implode("\n", $lines), $schema);
            }

            if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
                $valueType = $this->schemaToTsType($schema['additionalProperties']);
                return $this->applyNullable("Record<string, {$valueType}>", $schema);
            }

            return $this->applyNullable('Record<string, unknown>', $schema);
        }

        $mapped = match ($type) {
            'integer', 'number' => 'number',
            'boolean' => 'boolean',
            'string' => 'string',
            default => 'unknown',
        };

        return $this->applyNullable($mapped, $schema);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function applyNullable(string $type, array $schema): string
    {
        if (($schema['nullable'] ?? false) === true && !str_contains($type, 'null')) {
            return "{$type} | null";
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function renderNamedSchema(string $schemaName, array $schema): string
    {
        $type = $this->schemaToTsType($schema);

        if (($schema['type'] ?? null) === 'object' || isset($schema['properties'])) {
            if (str_starts_with($type, "{\n")) {
                return "export interface {$schemaName} {$type}";
            }
        }

        return "export type {$schemaName} = {$type};";
    }

    /**
     * @param array<int, array<string, mixed>> $parameters
     */
    private function renderParamsType(string $typeName, array $parameters): string
    {
        $lines = ["export interface {$typeName} {"];

        foreach ($parameters as $parameter) {
            $name = (string) ($parameter['name'] ?? 'value');
            $type = $this->schemaToTsType(is_array($parameter['schema'] ?? null) ? $parameter['schema'] : ['type' => 'string']);
            $optional = ($parameter['required'] ?? false) ? '' : '?';
            $safeName = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)
                ? $name
                : "'" . str_replace("'", "\\'", $name) . "'";

            $lines[] = "    {$safeName}{$optional}: {$type};";
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $queryParams
     */
    private function renderQueryObject(string $sourceVar, array $queryParams): string
    {
        $entries = array_map(
            fn (array $parameter): string => (string) $parameter['name'] . ': ' . $sourceVar . '.' . (string) $parameter['name'],
            $queryParams
        );

        return '{ ' . implode(', ', $entries) . ' }';
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     * @return array<int, string>
     */
    private function findMissingSchemaReferences(array $schemas): array
    {
        $known = array_fill_keys(array_keys($schemas), true);
        $missing = [];

        foreach ($schemas as $schema) {
            foreach ($this->extractNamedTypes($schema) as $typeName) {
                if (!isset($known[$typeName]) && $typeName !== 'File') {
                    $missing[$typeName] = true;
                }
            }
        }

        $missingTypes = array_keys($missing);
        sort($missingTypes);

        return $missingTypes;
    }

    private function buildAxiosCall(
        string $httpMethod,
        string $urlExpression,
        ?string $configExpression,
        ?string $bodyVariable,
        bool $typed,
        ?string $responseType = null,
    ): string {
        $generic = $typed && $responseType !== null ? "<{$responseType}>" : '';

        return match ($httpMethod) {
            'GET' => 'axios.get' . $generic . '(' . $urlExpression . ($configExpression ? ', ' . $configExpression : '') . ')',
            'DELETE' => 'axios.delete' . $generic . '(' . $urlExpression . ($configExpression ? ', ' . $configExpression : '') . ')',
            default => 'axios.' . strtolower($httpMethod) . $generic . '(' . $urlExpression . ', ' . ($bodyVariable ?? 'undefined') . ($configExpression ? ', ' . $configExpression : '') . ')',
        };
    }

    private function extractServiceBasePath(string $path): string
    {
        $trimmed = ltrim($path, '/');
        $segments = explode('/', $trimmed);
        $serviceSegment = $segments[2] ?? '';

        return '/app/api/' . $serviceSegment;
    }

    private function generatedHeader(string $description): string
    {
        return <<<TS
/* eslint-disable */
/**
 * {$description}
 *
 * This file was generated by `php artisan proxy:generate`.
 * Do not edit it by hand.
 */

TS;
    }

    private function indent(string $content, int $spaces): string
    {
        $indent = str_repeat(' ', $spaces);
        return preg_replace('/^/m', $indent, $content) ?? $content;
    }

    private function deleteGeneratedFiles(string $root, string $servicesDir): void
    {
        foreach (['models.ts', 'runtime.ts', 'index.ts'] as $fileName) {
            $path = $root . DIRECTORY_SEPARATOR . $fileName;
            if ($this->files->exists($path)) {
                $this->files->delete($path);
            }
        }

        if ($this->files->isDirectory($servicesDir)) {
            foreach ($this->files->files($servicesDir) as $file) {
                if (str_ends_with($file->getFilename(), '.ts')) {
                    $this->files->delete($file->getPathname());
                }
            }
        }
    }
}

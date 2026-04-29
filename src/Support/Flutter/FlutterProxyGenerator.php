<?php

namespace Incoder\DDD\Support\Flutter;

use Illuminate\Filesystem\Filesystem;
use Incoder\DDD\Support\OpenApi\OpenApiGenerator;
use RuntimeException;
use stdClass;
use Symfony\Component\Process\Process;

class FlutterProxyGenerator
{
    public function __construct(
        private readonly Filesystem $files = new Filesystem(),
    ) {
    }

    /**
     * @return array{outputPath:string,specPath:string,generator:string,jarPath:string,formatted:bool}
     */
    public function generate(array $options = []): array
    {
        $flutterRoot = $this->resolvePath($options['flutter_root'] ?? '../flutter');
        $outputPath = $this->resolvePath($options['output'] ?? '../flutter/lib/src/generated/openapi');
        $configPath = $this->resolvePath($options['config'] ?? '../flutter/tool/openapi-generator-config.yaml');
        $jarPath = $this->resolvePath($options['jar'] ?? '../flutter/.tooling/openapi-generator/openapi-generator-cli-7.21.0.jar');
        $generator = (string) ($options['generator'] ?? 'dart-dio');
        $skipFormat = (bool) ($options['skip_format'] ?? false);
        $skipValidateSpec = (bool) ($options['skip_validate_spec'] ?? true);

        if (!$this->files->isDirectory($flutterRoot)) {
            throw new RuntimeException("Flutter root was not found at '{$flutterRoot}'.");
        }

        if (!$this->files->exists($configPath)) {
            throw new RuntimeException("OpenAPI generator config was not found at '{$configPath}'.");
        }

        if (!$this->files->exists($jarPath)) {
            throw new RuntimeException("OpenAPI generator JAR was not found at '{$jarPath}'.");
        }

        $workingDir = $flutterRoot;
        $tmpRoot = $workingDir . DIRECTORY_SEPARATOR . '.tooling' . DIRECTORY_SEPARATOR . 'tmp';
        $specPath = $tmpRoot . DIRECTORY_SEPARATOR . 'openapi-spec.json';
        $tempOutputDir = $tmpRoot . DIRECTORY_SEPARATOR . 'openapi-output';

        $this->files->ensureDirectoryExists($tmpRoot);
        $this->files->deleteDirectory($tempOutputDir);
        $this->files->ensureDirectoryExists($tempOutputDir);

        $spec = $this->normalizeSpecForGenerator((new OpenApiGenerator())->generate());
        $this->files->put(
            $specPath,
            json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $command = [
            'java',
            '-jar',
            $jarPath,
            'generate',
            '-i',
            $specPath,
            '-g',
            $generator,
            '-c',
            $configPath,
            '-o',
            $tempOutputDir,
        ];

        if ($skipValidateSpec) {
            $command[] = '--skip-validate-spec';
        }

        $this->runProcess(
            $command,
            $workingDir,
            300000,
            'OpenAPI generator failed.'
        );

        $generatedLibDir = $tempOutputDir . DIRECTORY_SEPARATOR . 'lib';

        if (!$this->files->isDirectory($generatedLibDir)) {
            throw new RuntimeException("Generator output did not contain a 'lib' directory at '{$generatedLibDir}'.");
        }

        $this->files->deleteDirectory($outputPath);
        $this->files->ensureDirectoryExists($outputPath);
        $this->files->copyDirectory($generatedLibDir, $outputPath);

        $formatted = false;
        if (!$skipFormat && $this->commandExists('dart')) {
            $this->runProcess(
                ['dart', 'format', $outputPath],
                $workingDir,
                120000,
                'Dart formatting failed.'
            );
            $formatted = true;
        }

        return [
            'outputPath' => $outputPath,
            'specPath' => $specPath,
            'generator' => $generator,
            'jarPath' => $jarPath,
            'formatted' => $formatted,
            'skipValidateSpec' => $skipValidateSpec,
        ];
    }

    private function resolvePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || str_starts_with($path, '\\\\')
            || str_starts_with($path, '/');
    }

    private function commandExists(string $command): bool
    {
        $process = Process::fromShellCommandline("where {$command}");
        $process->run();

        return $process->isSuccessful();
    }

    private function runProcess(
        array $command,
        string $workingDirectory,
        int $timeoutSeconds,
        string $failureMessage
    ): void {
        $process = new Process($command, $workingDirectory, null, null, $timeoutSeconds);
        $process->run(function ($type, $buffer): void {
            echo $buffer;
        });

        if (!$process->isSuccessful()) {
            throw new RuntimeException($failureMessage . PHP_EOL . trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function normalizeSpecForGenerator(array $spec): array
    {
        $schemas = $spec['components']['schemas'] ?? [];

        if (!is_array($schemas)) {
            return $spec;
        }

        foreach ($schemas as $schemaName => $schema) {
            if (!is_array($schema)) {
                continue;
            }

            $schemas[$schemaName] = $this->normalizeSchema($schema, is_string($schemaName) ? $schemaName : null);
        }

        foreach ($schemas as $schemaName => $schema) {
            if (!is_array($schema) || !is_string($schemaName) || !str_ends_with($schemaName, 'PaginatedDTO')) {
                continue;
            }

            $dataSchema = $schema['properties']['data'] ?? null;
            if (!is_array($dataSchema) || ($dataSchema['type'] ?? null) !== 'array') {
                continue;
            }

            $itemRef = $dataSchema['items']['$ref'] ?? null;
            if (!is_string($itemRef)) {
                continue;
            }

            $targetSchemaName = basename(str_replace('\\', '/', $itemRef));
            $targetSchema = $schemas[$targetSchemaName] ?? null;

            if ($this->isFreeFormObjectSchema($targetSchema)) {
                $schemas[$schemaName]['properties']['data']['items'] = [
                    'type' => 'object',
                    'additionalProperties' => true,
                ];
            }
        }

        $spec['components']['schemas'] = $schemas;

        if (isset($spec['paths']) && is_array($spec['paths'])) {
            foreach ($spec['paths'] as $path => $methods) {
                if (!is_array($methods)) {
                    continue;
                }

                foreach ($methods as $httpMethod => $operation) {
                    if (!is_array($operation)) {
                        continue;
                    }

                    if (isset($operation['parameters']) && is_array($operation['parameters'])) {
                        foreach ($operation['parameters'] as $index => $parameter) {
                            if (!is_array($parameter)) {
                                continue;
                            }

                            if (isset($parameter['schema']) && is_array($parameter['schema'])) {
                                $operation['parameters'][$index]['schema'] = $this->normalizeSchema($parameter['schema']);
                            }
                        }
                    }

                    if (isset($operation['requestBody']['content']) && is_array($operation['requestBody']['content'])) {
                        foreach ($operation['requestBody']['content'] as $contentType => $content) {
                            if (!is_array($content) || !isset($content['schema']) || !is_array($content['schema'])) {
                                continue;
                            }

                            $operation['requestBody']['content'][$contentType]['schema'] = $this->normalizeSchema($content['schema']);
                        }
                    }

                    if (isset($operation['responses']) && is_array($operation['responses'])) {
                        foreach ($operation['responses'] as $status => $response) {
                            if (!is_array($response) || !isset($response['content']) || !is_array($response['content'])) {
                                continue;
                            }

                            foreach ($response['content'] as $contentType => $content) {
                                if (!is_array($content) || !isset($content['schema']) || !is_array($content['schema'])) {
                                    continue;
                                }

                                $operation['responses'][$status]['content'][$contentType]['schema'] = $this->normalizeSchema($content['schema']);
                            }
                        }
                    }

                    $spec['paths'][$path][$httpMethod] = $operation;
                }
            }
        }

        return $spec;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $schema, ?string $schemaName = null): array
    {
        if (($schema['type'] ?? null) === 'object' && array_key_exists('properties', $schema) && $schema['properties'] === []) {
            $schema['properties'] = new stdClass();
            $schema['additionalProperties'] = true;
        }

        if (($schema['type'] ?? null) === 'object'
            && !array_key_exists('properties', $schema)
            && !array_key_exists('additionalProperties', $schema)
            && !isset($schema['$ref'])
        ) {
            $schema['additionalProperties'] = true;
        }

        if (($schema['type'] ?? null) === 'array' && !isset($schema['items'])) {
            $schema['items'] = ['type' => 'string'];
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $propertyName => $propertySchema) {
                if (!is_array($propertySchema)) {
                    continue;
                }

                if (($propertySchema['type'] ?? null) === 'array' && !isset($propertySchema['items'])) {
                    if ($schemaName !== null && str_ends_with($schemaName, 'PaginatedDTO') && $propertyName === 'meta') {
                        $propertySchema = [
                            'type' => 'object',
                            'additionalProperties' => true,
                        ];
                    } else {
                        $propertySchema['items'] = ['type' => 'string'];
                    }
                }

                if ($schemaName !== null
                    && str_ends_with($schemaName, 'PaginatedDTO')
                    && $propertyName === 'data'
                    && ($propertySchema['type'] ?? null) === 'array'
                    && (($propertySchema['items']['type'] ?? null) === 'object')
                ) {
                    $itemDtoName = preg_replace('/PaginatedDTO$/', 'DTO', $schemaName);
                    if (is_string($itemDtoName) && $itemDtoName !== '') {
                        $propertySchema['items'] = ['$ref' => "#/components/schemas/{$itemDtoName}"];
                    }
                }

                $schema['properties'][$propertyName] = $this->normalizeSchema($propertySchema);
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->normalizeSchema($schema['items']);
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $compositeKey) {
            if (!isset($schema[$compositeKey]) || !is_array($schema[$compositeKey])) {
                continue;
            }

            foreach ($schema[$compositeKey] as $index => $part) {
                if (is_array($part)) {
                    $schema[$compositeKey][$index] = $this->normalizeSchema($part);
                }
            }
        }

        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            $schema['additionalProperties'] = $this->normalizeSchema($schema['additionalProperties']);
        }

        return $schema;
    }

    private function isFreeFormObjectSchema(mixed $schema): bool
    {
        if (!is_array($schema) || ($schema['type'] ?? null) !== 'object') {
            return false;
        }

        $properties = $schema['properties'] ?? null;

        if ($properties instanceof stdClass) {
            return count((array) $properties) === 0;
        }

        return is_array($properties) && $properties === [];
    }
}

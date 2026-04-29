<?php

namespace Incoder\DDD\Support\OpenApi;

use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Infers a JSON Schema object from a PHP DTO class.
 *
 * Supports two DTO styles used in this project:
 *   1. Public property DTOs  (e.g. UserListDTO - all public $property)
 *   2. Constructor-parameter DTOs  (e.g. Spatie Data style)
 *
 * Also parses the optional static rules() method to mark fields as required.
 */
class SchemaInferrer
{
    /** Cache of already-inferred schemas keyed by class name */
    private array $cache = [];

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Build a JSON Schema array for a DTO class.
     * Returns a raw schema (not a $ref) so callers can embed or register it.
     *
     * @param class-string $className
     */
    public function fromClass(string $className): array
    {
        if (isset($this->cache[$className])) {
            return $this->cache[$className];
        }

        if (!class_exists($className)) {
            return ['type' => 'object', 'description' => "Unknown class: {$className}"];
        }

        $reflection = new ReflectionClass($className);
        $schema     = ['type' => 'object', 'properties' => []];
        $required   = [];

        // Parse static rules() for required / nullable hints
        $rules = $this->parseRules($className);

        // Prefer constructor params (Spatie Data / typed constructors)
        $constructor = $reflection->getConstructor();
        if ($constructor && count($constructor->getParameters()) > 0) {
            foreach ($constructor->getParameters() as $param) {
                $name   = $param->getName();
                $type   = $param->getType();
                $result = $this->phpTypeToJsonSchema($type);

                $schema['properties'][$name] = $this->applyRuleHints($result, $rules[$name] ?? []);

                $isNullable = $type && $type->allowsNull();
                $hasDefault = $param->isOptional();
                if (!$isNullable && !$hasDefault) {
                    $required[] = $name;
                }
            }
        } else {
            // Fall back to public properties
            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                if ($prop->isStatic()) {
                    continue;
                }
                $name   = $prop->getName();
                $type   = $prop->getType();
                $result = $this->phpTypeToJsonSchema($type);

                $schema['properties'][$name] = $this->applyRuleHints($result, $rules[$name] ?? []);

                // Required = declared as non-nullable and present in rules as 'required'
                if (isset($rules[$name])) {
                    if (in_array('required', $rules[$name], true)) {
                        $required[] = $name;
                    }
                }
            }
        }

        if (!empty($required)) {
            $schema['required'] = array_values(array_unique($required));
        }

        $this->cache[$className] = $schema;

        return $schema;
    }

    /**
     * Convert a PHP type name string (as returned by ReflectionNamedType::getName())
     * to a simple JSON Schema type string.  Used for parameter type hints.
     */
    public function phpTypeNameToJson(string $typeName): string
    {
        return match ($typeName) {
            'int'    => 'integer',
            'float'  => 'number',
            'bool'   => 'boolean',
            default  => 'string',   // string, mixed, unknown classes
        };
    }

    /**
     * Build a JSON Schema fragment for a plain PHP type name.
     * Always includes `items` for array types (required by OA3 generators).
     */
    public function phpTypeNameToSchema(string $typeName, bool $nullable = false): array
    {
        $schema = match ($typeName) {
            'int'   => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool'  => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            default => ['type' => 'string'],
        };
        if ($nullable) {
            $schema['nullable'] = true;
        }
        return $schema;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Convert a ReflectionType to a JSON Schema fragment.
     * Returns the schema array (may include $ref for known DTO classes).
     */
    private function phpTypeToJsonSchema(?\ReflectionType $type): array
    {
        if ($type === null) {
            return ['type' => 'string'];
        }

        // Union type (int|string etc.) → fallback to string
        if ($type instanceof ReflectionUnionType) {
            return ['type' => 'string'];
        }

        /** @var ReflectionNamedType $type */
        $nullable = $type->allowsNull();
        $name     = $type->getName();

        // Bare array type (no generics) → treat as object (map/dictionary)
        // additionalProperties with empty schema allows any value type
        if ($name === 'array') {
            $schema = ['type' => 'object', 'additionalProperties' => ['type' => 'string']];
            if ($nullable) {
                $schema['nullable'] = true;
            }
            return $schema;
        }

        $primitiveMap = [
            'int'    => 'integer',
            'float'  => 'number',
            'bool'   => 'boolean',
            'string' => 'string',
            'mixed'  => 'string',
        ];

        if (isset($primitiveMap[$name])) {
            $schema = ['type' => $primitiveMap[$name]];
            if ($nullable) {
                $schema['nullable'] = true;
            }
            return $schema;
        }

        // DateTime types
        if (in_array($name, [\DateTime::class, \DateTimeImmutable::class, \DateTimeInterface::class, 'Carbon\Carbon'], true)) {
            $schema = ['type' => 'string', 'format' => 'date-time'];
            if ($nullable) {
                $schema['nullable'] = true;
            }
            return $schema;
        }

        // Enum
        if (class_exists($name) && (new ReflectionClass($name))->isEnum()) {
            /** @var \BackedEnum $name */
            $cases = array_column($name::cases(), 'value');
            $schema = ['type' => 'string', 'enum' => $cases];
            if ($nullable) {
                $schema['nullable'] = true;
            }
            return $schema;
        }

        // Spatie LaravelData collection types → treat as array of objects
        // (DataCollection is a known class but has no registered schema component)
        if (is_a($name, \Spatie\LaravelData\DataCollection::class, true) ||
            is_a($name, \Spatie\LaravelData\CursorPaginatedDataCollection::class, true) ||
            is_a($name, \Spatie\LaravelData\PaginatedDataCollection::class, true)) {
            $schema = ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]];
            if ($nullable) {
                $schema['nullable'] = true;
            }
            return $schema;
        }

        // Known class (likely another DTO) → use $ref
        if (class_exists($name)) {
            $shortName = class_basename($name);
            if ($nullable) {
                return ['nullable' => true, 'allOf' => [['$ref' => "#/components/schemas/{$shortName}"]]];
            }
            return ['$ref' => "#/components/schemas/{$shortName}"];
        }

        return ['type' => 'string'];
    }

    /**
     * Pull required/nullable hints from the rules() array.
     *
     * @param  string $className
     * @return array<string, string[]>  keyed by property name
     */
    private function parseRules(string $className): array
    {
        if (!method_exists($className, 'rules')) {
            return [];
        }

        try {
            $raw = $className::rules();
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $parsed = [];
        foreach ($raw as $field => $ruleSet) {
            if (is_string($ruleSet)) {
                $ruleSet = explode('|', $ruleSet);
            }
            $parsed[$field] = array_values(array_filter(array_map(
                static fn ($rule): ?string => is_string($rule) ? trim($rule) : null,
                (array) $ruleSet
            )));
        }

        return $parsed;
    }

    /**
     * Overlay additional metadata (nullable, description, format) derived from
     * the Laravel validation rules onto an already-built JSON Schema fragment.
     */
    private function applyRuleHints(array $schema, array $rules): array
    {
        if (empty($rules)) {
            return $schema;
        }

        if (in_array('nullable', $rules, true)) {
            $schema['nullable'] = true;
        }

        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'max:')) {
                $max = (int) substr($rule, 4);
                if (($schema['type'] ?? '') === 'string') {
                    $schema['maxLength'] = $max;
                } elseif (in_array($schema['type'] ?? '', ['integer', 'number'], true)) {
                    $schema['maximum'] = $max;
                }
            } elseif (str_starts_with($rule, 'min:')) {
                $min = (int) substr($rule, 4);
                if (($schema['type'] ?? '') === 'string') {
                    $schema['minLength'] = $min;
                } elseif (in_array($schema['type'] ?? '', ['integer', 'number'], true)) {
                    $schema['minimum'] = $min;
                }
            } elseif ($rule === 'email' || $rule === 'email:rfc') {
                $schema['format'] = 'email';
            } elseif ($rule === 'date') {
                $schema['format'] = 'date';
            } elseif (str_starts_with($rule, 'in:')) {
                $values           = explode(',', substr($rule, 3));
                $schema['enum']   = $values;
            } elseif ($rule === 'integer') {
                $schema['type'] = 'integer';
            } elseif ($rule === 'boolean') {
                $schema['type'] = 'boolean';
            } elseif ($rule === 'numeric') {
                $schema['type'] = 'number';
            } elseif ($rule === 'string') {
                if (!isset($schema['type'])) {
                    $schema['type'] = 'string';
                }
            }
        }

        return $schema;
    }

    /**
     * Discover all nested DTO class names referenced in a given DTO class.
     * Scans the schema for $ref entries and extracts class names.
     *
     * @param class-string $className
     * @return class-string[]  Fully qualified class names of nested DTOs
     */
    public function getNestedDtoClasses(string $className): array
    {
        $schema = $this->fromClass($className);
        $nested = [];
        $this->extractNestedDtos($schema, $nested);
        return array_values(array_unique($nested));
    }

    /**
     * Recursively extract all $ref entries from a schema.
     * Returns short class names like 'ItemPositionDTO'.
     */
    private function extractNestedDtos(array $schema, array &$nested): void
    {
        if (isset($schema['$ref'])) {
            // Extract class name from "#/components/schemas/ItemPositionDTO"
            if (preg_match('#/components/schemas/([^/]+)$#', $schema['$ref'], $m)) {
                $nested[] = $m[1];
            }
        }

        if (isset($schema['allOf'])) {
            foreach ((array) $schema['allOf'] as $subSchema) {
                $this->extractNestedDtos($subSchema, $nested);
            }
        }

        if (isset($schema['properties'])) {
            foreach ((array) $schema['properties'] as $prop) {
                $this->extractNestedDtos($prop, $nested);
            }
        }

        if (isset($schema['items'])) {
            $this->extractNestedDtos($schema['items'], $nested);
        }
    }
}

<?php

namespace Incoder\DDD\Support\Console\Commands;

use Illuminate\Console\Command;
use Incoder\DDD\Support\OpenApi\OpenApiGenerator;

/**
 * Artisan command: api:generate-spec
 *
 * Generates the OpenAPI 3.0 specification for all routes registered via
 * #[RouteAttribute] and writes the result to disk (or stdout).
 *
 * Examples:
 *   php artisan api:generate-spec
 *   php artisan api:generate-spec --output=public/api-docs.json
 *   php artisan api:generate-spec --format=yaml --output=public/api-docs.yaml
 *   php artisan api:generate-spec --stdout
 */
class GenerateApiSpec extends Command
{
    protected $signature = 'api:generate-spec
        {--output=  : File path to write the spec (default: public/api-docs.json)}
        {--format=json : Output format: json or yaml}
        {--stdout  : Print the spec to stdout instead of writing a file}
        {--pretty  : Pretty-print the JSON output (default: on)}';

    protected $description = 'Generate the OpenAPI 3.0 specification from all RouteAttribute-annotated controllers';

    public function handle(): int
    {
        $this->info('🔍  Scanning controllers for RouteAttribute routes…');

        $generator = new OpenApiGenerator();
        $spec      = $generator->generate();
        $spec['paths'] = array_filter(
            $spec['paths'] ?? [],
            fn (string $path): bool => $this->shouldIncludePath($path),
            ARRAY_FILTER_USE_KEY
        );

        $pathCount = count($spec['paths'] ?? []);
        $this->line("    Found <comment>{$pathCount}</comment> unique paths.");

        $format = strtolower($this->option('format') ?? 'json');
        if (!in_array($format, ['json', 'yaml'], true)) {
            $this->error("Unsupported format: '{$format}'. Use 'json' or 'yaml'.");
            return self::FAILURE;
        }

        $content = match ($format) {
            'yaml'  => $this->toYaml($spec),
            default => json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };

        if ($this->option('stdout')) {
            $this->output->writeln($content);
            return self::SUCCESS;
        }

        $defaultName = "api-docs.{$format}";
        $outputPath  = $this->option('output') ?: public_path($defaultName);

        // Ensure directory exists
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outputPath, $content);

        $this->info("✅  Spec written to <comment>{$outputPath}</comment>");
        $this->line("    Paths: <comment>{$pathCount}</comment> | Schemas: <comment>" . count($spec['components']['schemas'] ?? []) . "</comment>");

        return self::SUCCESS;
    }

    private function shouldIncludePath(string $path): bool
    {
        return str_starts_with($path, '/app/api/')
            || $path === '/app/api'
            || str_starts_with($path, '/sanctum/')
            || $path === '/sanctum';
    }

    // -----------------------------------------------------------------------
    // YAML serialisation
    // -----------------------------------------------------------------------

    /**
     * Convert the spec array to YAML using Symfony's Yaml component,
     * which ships with every Laravel installation.
     */
    private function toYaml(array $spec): string
    {
        if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            $this->warn('symfony/yaml not found — falling back to JSON.');
            return json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return \Symfony\Component\Yaml\Yaml::dump($spec, 10, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
}

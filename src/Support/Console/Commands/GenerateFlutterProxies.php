<?php

namespace Incoder\DDD\Support\Console\Commands;

use Illuminate\Console\Command;
use Incoder\DDD\Support\Flutter\FlutterProxyGenerator;
use Incoder\DDD\Support\Helper\PackageConfig;

class GenerateFlutterProxies extends Command
{
    protected $signature = 'proxy:generate-flutter
        {--flutter-root= : Relative path to the Flutter app root}
        {--output= : Relative output path for generated Flutter sources}
        {--config= : Relative path to the OpenAPI generator config file}
        {--jar= : Relative path to the OpenAPI Generator CLI jar}
        {--generator= : OpenAPI Generator generator name}
        {--skip-validate-spec : Skip strict OpenAPI validation before generation}
        {--skip-format : Skip running dart format on generated sources}';

    protected $description = 'Generate Flutter OpenAPI proxies using the OpenAPI Generator CLI';

    public function handle(FlutterProxyGenerator $generator): int
    {
        $this->info('Generating Flutter OpenAPI proxies...');

        $result = $generator->generate([
            'flutter_root' => (string) ($this->option('flutter-root') ?: PackageConfig::flutterRoot()),
            'output' => (string) ($this->option('output') ?: PackageConfig::flutterOutputPath()),
            'config' => (string) ($this->option('config') ?: PackageConfig::flutterConfigPath()),
            'jar' => (string) ($this->option('jar') ?: PackageConfig::flutterJarPath()),
            'generator' => (string) ($this->option('generator') ?: PackageConfig::flutterGenerator()),
            'skip_validate_spec' => (bool) $this->option('skip-validate-spec'),
            'skip_format' => (bool) $this->option('skip-format'),
        ]);

        $this->info("Generator: <comment>{$result['generator']}</comment>");
        $this->line("Spec: <comment>{$result['specPath']}</comment>");
        $this->line("Jar: <comment>{$result['jarPath']}</comment>");
        $this->line("Output: <comment>{$result['outputPath']}</comment>");
        $this->line('Skip spec validation: <comment>'.($result['skipValidateSpec'] ? 'yes' : 'no').'</comment>');
        $this->line('Formatted: <comment>'.($result['formatted'] ? 'yes' : 'no').'</comment>');

        return self::SUCCESS;
    }
}

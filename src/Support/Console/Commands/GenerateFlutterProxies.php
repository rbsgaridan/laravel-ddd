<?php

namespace Incoder\DDD\Support\Console\Commands;

use Illuminate\Console\Command;
use Incoder\DDD\Support\Flutter\FlutterProxyGenerator;

class GenerateFlutterProxies extends Command
{
    protected $signature = 'proxy:generate-flutter
        {--flutter-root=../flutter : Relative path to the Flutter app root}
        {--output=../flutter/lib/src/generated/openapi : Relative output path for generated Flutter sources}
        {--config=../flutter/tool/openapi-generator-config.yaml : Relative path to the OpenAPI generator config file}
        {--jar=../flutter/.tooling/openapi-generator/openapi-generator-cli-7.21.0.jar : Relative path to the OpenAPI Generator CLI jar}
        {--generator=dart-dio : OpenAPI Generator generator name}
        {--skip-validate-spec : Skip strict OpenAPI validation before generation}
        {--skip-format : Skip running dart format on generated sources}';

    protected $description = 'Generate Flutter OpenAPI proxies using the OpenAPI Generator CLI';

    public function handle(FlutterProxyGenerator $generator): int
    {
        $this->info('Generating Flutter OpenAPI proxies...');

        $result = $generator->generate([
            'flutter_root' => (string) $this->option('flutter-root'),
            'output' => (string) $this->option('output'),
            'config' => (string) $this->option('config'),
            'jar' => (string) $this->option('jar'),
            'generator' => (string) $this->option('generator'),
            'skip_validate_spec' => (bool) $this->option('skip-validate-spec'),
            'skip_format' => (bool) $this->option('skip-format'),
        ]);

        $this->info("Generator: <comment>{$result['generator']}</comment>");
        $this->line("Spec: <comment>{$result['specPath']}</comment>");
        $this->line("Jar: <comment>{$result['jarPath']}</comment>");
        $this->line("Output: <comment>{$result['outputPath']}</comment>");
        $this->line('Skip spec validation: <comment>' . ($result['skipValidateSpec'] ? 'yes' : 'no') . '</comment>');
        $this->line('Formatted: <comment>' . ($result['formatted'] ? 'yes' : 'no') . '</comment>');

        return self::SUCCESS;
    }
}

<?php

namespace Incoder\DDD\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ServiceProvider;
use Incoder\DDD\Support\IncoderDDDServiceProvider;
use Incoder\DDD\Support\Routing\RouteScanner;
use Incoder\DDD\Tests\TestCase;

class IncoderDDDServiceProviderTest extends TestCase
{
    public function test_it_registers_expected_commands_and_bindings(): void
    {
        $commands = app(Kernel::class)->all();

        $this->assertArrayHasKey('api:generate-spec', $commands);
        $this->assertArrayHasKey('proxy:generate', $commands);
        $this->assertArrayHasKey('proxy:generate-flutter', $commands);
        $this->assertArrayHasKey('make:domain-model', $commands);
        $this->assertArrayHasKey('make:domain-crud', $commands);
        $this->assertArrayHasKey('migrate:fresh-schema', $commands);
        $this->assertTrue(app()->bound(RouteScanner::class));
    }

    public function test_it_exposes_publish_groups_for_package_configs(): void
    {
        $apiDocsPublishes = ServiceProvider::pathsToPublish(IncoderDDDServiceProvider::class, 'api-docs');
        $packagePublishes = ServiceProvider::pathsToPublish(IncoderDDDServiceProvider::class, 'incoder-ddd-config');

        $this->assertContains(config_path('api-docs.php'), array_values($apiDocsPublishes));
        $this->assertContains(config_path('incoder-ddd.php'), array_values($packagePublishes));
    }
}

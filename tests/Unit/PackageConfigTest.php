<?php

namespace Incoder\DDD\Tests\Unit;

use Incoder\DDD\Support\Helper\PackageConfig;
use Incoder\DDD\Tests\TestCase;

class PackageConfigTest extends TestCase
{
    public function test_it_uses_default_conventions(): void
    {
        $this->assertSame('Core\\Application', PackageConfig::applicationNamespace());
        $this->assertSame('core/Domain', PackageConfig::domainPath());
        $this->assertSame('/app/api', PackageConfig::appServiceRoutePrefix());
        $this->assertSame('resources/js/proxies', PackageConfig::typeScriptOutputPath());
    }

    public function test_it_loads_an_empty_class_map_when_missing(): void
    {
        config()->set('incoder-ddd.discovery.composer_classmap', 'missing/autoload_classmap.php');

        $this->assertSame([], PackageConfig::loadClassMap());
    }
}

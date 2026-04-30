<?php

namespace Incoder\DDD\Tests\Unit;

use Core\Application\Widgets\WidgetAppService;
use Illuminate\Support\Facades\Route;
use Incoder\DDD\Support\Routing\AppServiceRouteScanner;
use Incoder\DDD\Tests\TestCase;

class AppServiceRouteScannerTest extends TestCase
{
    public function test_it_registers_routes_using_the_configured_prefix(): void
    {
        require_once __DIR__.'/../Fixtures/Core/Application/Widgets/WidgetAppService.php';

        config()->set('incoder-ddd.routing.app_service_prefix', '/internal/api');

        $scanner = new AppServiceRouteScanner([
            WidgetAppService::class => __DIR__.'/../Fixtures/Core/Application/Widgets/WidgetAppService.php',
        ]);

        $scanner->register();

        $routeUris = collect(Route::getRoutes()->getRoutes())->map->uri()->all();

        $this->assertContains('internal/api/widget', $routeUris);
        $this->assertContains('internal/api/widget/{id}', $routeUris);
    }
}

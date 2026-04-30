<?php

namespace Incoder\DDD\Support;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Incoder\DDD\Support\Console\Commands\DddGenerateCrud;
use Incoder\DDD\Support\Console\Commands\GenerateApiSpec;
use Incoder\DDD\Support\Console\Commands\GenerateFlutterProxies;
use Incoder\DDD\Support\Console\Commands\GenerateTypeScriptProxies;
use Incoder\DDD\Support\Console\Commands\MakeDomainModel;
use Incoder\DDD\Support\Console\Commands\MigrateFreshSchema;
use Incoder\DDD\Support\Helper\DiAutoBinder;
use Incoder\DDD\Support\Helper\PackageConfig;
use Incoder\DDD\Support\OpenApi\SwaggerUiController;
use Incoder\DDD\Support\Reporting\ReportServiceProvider;
use Incoder\DDD\Support\Routing\AppServiceRouteScanner;
use Incoder\DDD\Support\Routing\RouteScanner;

class IncoderDDDServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Merge package config (can be overridden by app's published config)
        $this->mergeConfigFrom(
            __DIR__.'/../../config/incoder-ddd.php',
            'incoder-ddd'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../../config/api-docs.php',
            'api-docs'
        );

        // App::bind(IRepository::class, EloquentRepository::class);
        App::singleton(RouteScanner::class, function () {
            return new RouteScanner(PackageConfig::controllerPath());
        });

        $classMap = PackageConfig::loadClassMap();
        $diAutoBinder = new DiAutoBinder($classMap);

        // Register DI for interfaces and implementations based on naming conventions
        $diAutoBinder->bindByNamespace(
            PackageConfig::domainNamespace().'\\',
            'I',
            'Repository',
            PackageConfig::infrastructureRepositoryNamespace().'\\'
        );
        $diAutoBinder->bindByNamespace(
            PackageConfig::applicationNamespace().'\\',
            'I',
            'AppService',
            PackageConfig::applicationNamespace().'\\'
        );
        $diAutoBinder->bindByNamespace(
            PackageConfig::domainNamespace().'\\',
            'I',
            'DomainService',
            PackageConfig::domainServiceNamespace().'\\'
        );

        // Register report service provider
        $this->app->register(ReportServiceProvider::class);
    }

    public function boot()
    {
        $this->commands([
            MakeDomainModel::class,
            DddGenerateCrud::class,
            MigrateFreshSchema::class,
            GenerateApiSpec::class,
            GenerateTypeScriptProxies::class,
            GenerateFlutterProxies::class,
        ]);

        $this->app->make(RouteScanner::class)->register();

        // Register AppService routes
        $classMap = PackageConfig::loadClassMap();

        $appServiceScanner = new AppServiceRouteScanner($classMap);
        $appServiceScanner->register();

        // ── OpenAPI / Swagger UI routes ───────────────────────────────────────
        if (config('api-docs.enabled', true) && ! $this->app->environment('production')) {
            $this->registerApiDocRoutes();
        }

        // Publish the api-docs config stub
        $this->publishes([
            __DIR__.'/../../config/incoder-ddd.php' => config_path('incoder-ddd.php'),
        ], 'incoder-ddd-config');

        $this->publishes([
            __DIR__.'/../../config/api-docs.php' => config_path('api-docs.php'),
        ], 'api-docs');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function registerApiDocRoutes(): void
    {
        $middleware = config('api-docs.middleware', ['web']);
        $uiPath = config('api-docs.path', '/api/docs');
        $specPath = config('api-docs.spec_path', '/api/docs/spec');

        Route::middleware($middleware)->group(function () use ($uiPath, $specPath) {
            Route::get($uiPath, [SwaggerUiController::class, 'ui'])
                ->name('api-docs.ui');
            Route::get($specPath, [SwaggerUiController::class, 'spec'])
                ->name('api-docs.spec');
        });
    }
}

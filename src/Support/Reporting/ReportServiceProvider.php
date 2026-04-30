<?php

namespace Incoder\DDD\Support\Reporting;

use Illuminate\Support\ServiceProvider;
use Incoder\DDD\Support\Reporting\Contracts\IReportService;

class ReportServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__.'/../../../config/reporting.php',
            'reporting'
        );

        // Register the report service
        $this->app->singleton(IReportService::class, function ($app) {
            return new SsrsReportService;
        });

        // Register alias
        $this->app->alias(IReportService::class, 'reporting.ssrs');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../../../config/reporting.php' => config_path('reporting.php'),
        ], 'incoder-ddd-config');
    }
}

<?php

namespace Incoder\DDD\Support\Reporting\Facades;

use Illuminate\Support\Facades\Facade;
use Incoder\DDD\Support\Reporting\Contracts\IReportService;

/**
 * @method static string generatePdfReport(string $folderName, string $reportName, array $parameters = [])
 * @method static \Symfony\Component\HttpFoundation\Response streamPdfReport(string $folderName, string $reportName, array $parameters = [])
 * @method static array getReportInfo(string $folderName, string $reportName)
 * @method static bool testConnection()
 *
 * @see IReportService
 */
class Report extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'reporting.ssrs';
    }
}

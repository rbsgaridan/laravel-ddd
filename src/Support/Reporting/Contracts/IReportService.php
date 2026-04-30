<?php

namespace Incoder\DDD\Support\Reporting\Contracts;

use Symfony\Component\HttpFoundation\Response;

interface IReportService
{
    /**
     * Generate a report and return the PDF content
     *
     * @param  string  $folderName  The folder path in SSRS (e.g., '/Reports/HR')
     * @param  string  $reportName  The report file name (e.g., 'EmployeeReport')
     * @param  array  $parameters  Report parameters as key-value pairs
     * @return string Binary PDF content
     *
     * @throws \Exception
     */
    public function generatePdfReport(string $folderName, string $reportName, array $parameters = []): string;

    /**
     * Stream a report directly to the browser
     *
     * @param  string  $folderName  The folder path in SSRS
     * @param  string  $reportName  The report file name
     * @param  array  $parameters  Report parameters as key-value pairs
     *
     * @throws \Exception
     */
    public function streamPdfReport(string $folderName, string $reportName, array $parameters = []): Response;

    /**
     * Get report metadata/information
     *
     * @param  string  $folderName  The folder path in SSRS
     * @param  string  $reportName  The report file name
     *
     * @throws \Exception
     */
    public function getReportInfo(string $folderName, string $reportName): array;

    /**
     * Test connection to SSRS
     */
    public function testConnection(): bool;
}

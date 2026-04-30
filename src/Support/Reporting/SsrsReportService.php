<?php

namespace Incoder\DDD\Support\Reporting;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Incoder\DDD\Support\Reporting\Contracts\IReportService;
use Symfony\Component\HttpFoundation\Response;

class SsrsReportService implements IReportService
{
    protected string $baseUrl;

    protected string $username;

    protected string $password;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('reporting.ssrs.base_url'), '/');
        $this->username = config('reporting.ssrs.username');
        $this->password = config('reporting.ssrs.password');
        $this->timeout = config('reporting.ssrs.timeout', 120);

        $this->validateConfiguration();
    }

    /**
     * Validate SSRS configuration
     *
     * @throws \RuntimeException
     */
    protected function validateConfiguration(): void
    {
        if (empty($this->baseUrl)) {
            throw new \RuntimeException('SSRS base URL is not configured. Please set SSRS_BASE_URL in .env');
        }

        if (empty($this->username) || empty($this->password)) {
            throw new \RuntimeException('SSRS credentials are not configured. Please set SSRS_USERNAME and SSRS_PASSWORD in .env');
        }
    }

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
    public function generatePdfReport(string $folderName, string $reportName, array $parameters = []): string
    {
        try {
            $reportPath = $this->buildReportPath($folderName, $reportName);

            Log::info('SSRS SOAP Report Request', [
                'report_path' => $reportPath,
                'folder' => $folderName,
                'report' => $reportName,
                'parameters' => $parameters,
                'username' => $this->username,
            ]);

            // Use SOAP API for report rendering
            $soapUrl = $this->baseUrl.'/ReportExecution2005.asmx?wsdl';

            Log::info('Creating SOAP Client', ['wsdl_url' => $soapUrl]);

            // Create stream context with authentication headers
            $streamContext = stream_context_create([
                'http' => [
                    'header' => 'Authorization: Basic '.base64_encode($this->username.':'.$this->password),
                ],
            ]);

            $soapClient = new \SoapClient($soapUrl, [
                'login' => $this->username,
                'password' => $this->password,
                'authentication' => SOAP_AUTHENTICATION_BASIC,
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'connection_timeout' => $this->timeout,
                'stream_context' => $streamContext,
                'soap_version' => SOAP_1_1,
            ]);

            // Load the report
            Log::info('Loading report', ['path' => $reportPath]);

            $loadParams = [
                'Report' => $reportPath,
                'HistoryID' => null,
            ];
            $execInfo = $soapClient->LoadReport($loadParams);

            // Log the full response to debug
            Log::info('LoadReport response', [
                'response' => $execInfo,
                'response_type' => gettype($execInfo),
                'response_class' => is_object($execInfo) ? get_class($execInfo) : null,
            ]);

            // Try to get ExecutionID from different possible locations
            $executionId = null;
            if (is_object($execInfo)) {
                $executionId = $execInfo->ExecutionID
                    ?? $execInfo->executionInfo->ExecutionID
                    ?? $execInfo->ExecutionInfo->ExecutionID
                    ?? null;
            }

            Log::info('Report loaded', ['execution_id' => $executionId]);

            if (! $executionId) {
                // Log SOAP request/response for debugging
                Log::error('Failed to extract ExecutionID', [
                    'last_request' => $soapClient->__getLastRequest(),
                    'last_response' => $soapClient->__getLastResponse(),
                ]);
                throw new \Exception('Failed to get ExecutionID from LoadReport response');
            }

            // Create execution header for subsequent calls
            $executionHeader = new \SoapHeader(
                'http://schemas.microsoft.com/sqlserver/2005/06/30/reporting/reportingservices',
                'ExecutionHeader',
                ['ExecutionID' => $executionId]
            );

            // Set parameters if provided
            if (! empty($parameters)) {
                $paramValues = [];
                foreach ($parameters as $name => $value) {
                    $paramValues[] = [
                        'Name' => $name,
                        'Value' => $value,
                    ];
                }

                Log::info('Setting report parameters', ['parameters' => $paramValues]);

                $soapClient->__setSoapHeaders($executionHeader);
                $setParamsParams = [
                    'Parameters' => $paramValues,
                    'ParameterLanguage' => 'en-us',
                ];
                $soapClient->SetExecutionParameters($setParamsParams);
            }

            // Render the report as PDF
            Log::info('Rendering report to PDF');

            $soapClient->__setSoapHeaders($executionHeader);
            $renderParams = [
                'Format' => 'PDF',
                'DeviceInfo' => '<DeviceInfo><Toolbar>False</Toolbar></DeviceInfo>',
            ];
            $result = $soapClient->Render($renderParams);

            if (! isset($result->Result)) {
                throw new \Exception('SOAP response did not contain Result');
            }

            $pdfSize = strlen($result->Result);
            Log::info('SSRS SOAP Report Generated Successfully', ['pdf_size_bytes' => $pdfSize]);

            return $result->Result;
        } catch (\SoapFault $e) {
            Log::error('SSRS SOAP Fault', [
                'message' => $e->getMessage(),
                'faultcode' => $e->faultcode ?? null,
                'faultstring' => $e->faultstring ?? null,
                'detail' => $e->detail ?? null,
                'last_request' => isset($soapClient) ? $soapClient->__getLastRequest() : null,
                'last_response' => isset($soapClient) ? $soapClient->__getLastResponse() : null,
            ]);

            throw new \Exception("SOAP Error: {$e->getMessage()}");
        } catch (\Exception $e) {
            Log::error('SSRS Report Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Stream a report directly to the browser
     *
     * @param  string  $folderName  The folder path in SSRS
     * @param  string  $reportName  The report file name
     * @param  array  $parameters  Report parameters as key-value pairs
     *
     * @throws \Exception
     */
    public function streamPdfReport(string $folderName, string $reportName, array $parameters = []): Response
    {
        $pdfContent = $this->generatePdfReport($folderName, $reportName, $parameters);

        $filename = $this->sanitizeFilename($reportName).'_'.now()->format('YmdHis').'.pdf';

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"{$filename}\"")
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Get report metadata/information
     *
     * @param  string  $folderName  The folder path in SSRS
     * @param  string  $reportName  The report file name
     *
     * @throws \Exception
     */
    public function getReportInfo(string $folderName, string $reportName): array
    {
        try {
            $reportPath = $this->buildReportPath($folderName, $reportName);

            Log::info('SSRS SOAP GetReportInfo', ['report_path' => $reportPath]);

            // Use ReportService2010 SOAP API
            $soapUrl = $this->baseUrl.'/ReportService2010.asmx?wsdl';

            $soapClient = new \SoapClient($soapUrl, [
                'login' => $this->username,
                'password' => $this->password,
                'authentication' => SOAP_AUTHENTICATION_BASIC,
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ]);

            // Get report properties
            $params = ['ItemPath' => $reportPath];
            $properties = $soapClient->GetProperties($params);

            return [
                'success' => true,
                'report_path' => $reportPath,
                'folder_name' => $folderName,
                'report_name' => $reportName,
                'exists' => true,
                'metadata' => $properties,
            ];
        } catch (\SoapFault $e) {
            Log::warning('SSRS SOAP GetReportInfo Failed', [
                'message' => $e->getMessage(),
                'folder' => $folderName,
                'report' => $reportName,
            ]);

            // Return basic info if SOAP call fails
            return [
                'success' => true,
                'report_path' => $this->buildReportPath($folderName, $reportName),
                'folder_name' => $folderName,
                'report_name' => $reportName,
                'exists' => null,
                'metadata' => null,
                'note' => 'Could not retrieve metadata: '.$e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::error('SSRS Get Report Info Exception', [
                'message' => $e->getMessage(),
                'folder' => $folderName,
                'report' => $reportName,
            ]);

            throw $e;
        }
    }

    /**
     * Test connection to SSRS
     */
    public function testConnection(): bool
    {
        try {
            // First, try to access WSDL directly with cURL to diagnose auth issues
            $testUrl = $this->baseUrl.'/ReportService2010.asmx?wsdl';

            Log::info('Testing SSRS Connection with cURL', [
                'url' => $testUrl,
                'username' => $this->username,
            ]);

            // Test with cURL first
            $ch = curl_init($testUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC | CURLAUTH_NTLM);
            curl_setopt($ch, CURLOPT_USERPWD, $this->username.':'.$this->password);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            Log::info('cURL Test Result', [
                'http_code' => $httpCode,
                'response_length' => strlen($response),
                'has_error' => ! empty($error),
                'error' => $error,
            ]);

            if ($httpCode === 401) {
                Log::error('SSRS Authentication Failed', [
                    'http_code' => 401,
                    'message' => 'Check username/password and authentication method (Basic vs NTLM)',
                    'username_format' => 'Try: DOMAIN\username or user@domain.com or just username',
                ]);

                return false;
            }

            if ($httpCode !== 200) {
                Log::error('SSRS Connection Failed', [
                    'http_code' => $httpCode,
                    'error' => $error,
                ]);

                return false;
            }

            // If cURL succeeded, try SOAP client
            $soapUrl = $this->baseUrl.'/ReportService2010.asmx?wsdl';

            Log::info('Testing SSRS SOAP Connection', ['wsdl_url' => $soapUrl]);

            $streamContext = stream_context_create([
                'http' => [
                    'header' => 'Authorization: Basic '.base64_encode($this->username.':'.$this->password),
                ],
            ]);

            $soapClient = new \SoapClient($soapUrl, [
                'login' => $this->username,
                'password' => $this->password,
                'authentication' => SOAP_AUTHENTICATION_BASIC,
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'connection_timeout' => 10,
                'stream_context' => $streamContext,
            ]);

            // If SOAP client created successfully, connection works
            Log::info('SSRS SOAP Connection Successful', ['method' => 'WSDL loaded']);

            return true;
        } catch (\SoapFault $e) {
            Log::error('SSRS SOAP Connection Test Failed', [
                'message' => $e->getMessage(),
                'faultcode' => $e->faultcode ?? null,
                'faultstring' => $e->faultstring ?? null,
                'detail' => $e->detail ?? null,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('SSRS Connection Test Failed', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build the complete report URL with parameters
     */
    protected function buildReportUrl(string $folderName, string $reportName, array $parameters = []): string
    {
        $reportPath = $this->buildReportPath($folderName, $reportName);

        // Use ReportServer rendering endpoint (works with SSRS 2008+)
        // Format: http://server/ReportServer?/FolderPath/ReportName&rs:Format=PDF
        $url = $this->baseUrl.'?'.$reportPath.'&rs:Format=PDF&rs:Command=Render';

        if (! empty($parameters)) {
            foreach ($parameters as $key => $value) {
                $url .= '&'.urlencode($key).'='.urlencode($value);
            }
        }

        return $url;
    }

    /**
     * Build the report path
     */
    protected function buildReportPath(string $folderName, string $reportName): string
    {
        $folder = '/'.trim($folderName, '/');
        $report = trim($reportName, '/');

        return $folder.'/'.$report;
    }

    /**
     * Sanitize filename for safe download
     */
    protected function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);

        return substr($filename, 0, 200);
    }
}

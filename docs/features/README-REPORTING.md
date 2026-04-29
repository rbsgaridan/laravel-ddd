# SSRS Reporting Integration

This package provides an abstracted interface for connecting to SQL Server Reporting Services (SSRS) using basic authentication and generating PDF reports.

## Features

- ✅ Basic authentication support
- ✅ PDF report generation
- ✅ Stream reports directly to browser
- ✅ Report metadata retrieval
- ✅ Connection testing
- ✅ Configurable timeouts
- ✅ Comprehensive logging
- ✅ Laravel service provider integration

## Installation & Configuration

### 1. Publish Configuration

```bash
php artisan vendor:publish --tag=incoder-ddd-config
```

This will create `config/reporting.php`.

### 2. Environment Variables

Add these to your `.env` file:

```env
# SSRS Configuration
SSRS_BASE_URL=http://your-ssrs-server/ReportServer
SSRS_USERNAME=your_username
SSRS_PASSWORD=your_password
SSRS_TIMEOUT=120
```

### 3. Configuration File

Edit `config/reporting.php`:

```php
return [
    'ssrs' => [
        'base_url' => env('SSRS_BASE_URL', 'http://localhost/ReportServer'),
        'username' => env('SSRS_USERNAME', ''),
        'password' => env('SSRS_PASSWORD', ''),
        'timeout' => env('SSRS_TIMEOUT', 120),
        'cache_ttl' => env('SSRS_CACHE_TTL', 0),
    ],
];
```

## Usage

### Using Dependency Injection

```php
use Incoder\DDD\Support\Reporting\Contracts\IReportService;

class YourController extends Controller
{
    public function __construct(
        private readonly IReportService $reportService
    ) {}

    public function generateReport()
    {
        return $this->reportService->streamPdfReport(
            folderName: '/Reports/HR',
            reportName: 'EmployeeReport',
            parameters: [
                'EmployeeId' => 123,
                'StartDate' => '2024-01-01',
                'EndDate' => '2024-12-31',
            ]
        );
    }
}
```

### Using Facade

```php
use Incoder\DDD\Support\Reporting\Facades\Report;

// Generate PDF and stream to browser
return Report::streamPdfReport(
    '/Reports/HR',
    'EmployeeReport',
    ['EmployeeId' => 123]
);

// Get report metadata
$info = Report::getReportInfo('/Reports/HR', 'EmployeeReport');

// Test connection
$isConnected = Report::testConnection();
```

## API Endpoints

Both `web-admin` and `jobs-usm` projects have the following endpoints:

### 1. Generate Report (POST)

```
POST /api/reports/generate
```

**Request Body:**
```json
{
    "folder_name": "/Reports/HR",
    "report_name": "EmployeeReport",
    "parameters": {
        "EmployeeId": 123,
        "StartDate": "2024-01-01",
        "EndDate": "2024-12-31"
    }
}
```

**Response:** PDF file streamed directly to browser

### 2. Get Report Info (GET)

```
GET /api/reports/info?folder_name=/Reports/HR&report_name=EmployeeReport
```

**Response:**
```json
{
    "success": true,
    "data": {
        // Report metadata
    }
}
```

### 3. Test Connection (GET)

```
GET /api/reports/test-connection
```

**Response:**
```json
{
    "success": true,
    "message": "Successfully connected to SSRS"
}
```

## Frontend Integration Example

### Using Axios (Vue/TypeScript)

```typescript
import axios from 'axios';

// Generate and download report
async function downloadReport() {
    try {
        const response = await axios.post('/api/reports/generate', {
            folder_name: '/Reports/HR',
            report_name: 'EmployeeReport',
            parameters: {
                EmployeeId: 123,
                StartDate: '2024-01-01',
                EndDate: '2024-12-31'
            }
        }, {
            responseType: 'blob' // Important for PDF
        });

        // Create blob link to download
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', 'EmployeeReport.pdf');
        document.body.appendChild(link);
        link.click();
        link.remove();
    } catch (error) {
        console.error('Report generation failed:', error);
    }
}

// View report in new tab
function viewReport() {
    const params = new URLSearchParams({
        folder_name: '/Reports/HR',
        report_name: 'EmployeeReport',
        // Add parameters as needed
    });

    window.open(`/api/reports/generate?${params.toString()}`, '_blank');
}
```

### Using Inertia.js

```typescript
import { router } from '@inertiajs/vue3';

function downloadReport() {
    router.post('/api/reports/generate', {
        folder_name: '/Reports/HR',
        report_name: 'EmployeeReport',
        parameters: {
            EmployeeId: 123
        }
    });
}
```

## SSRS URL Formats

The service supports multiple SSRS URL formats:

### 1. Direct Rendering (Default)
```
http://server/ReportServer/Reports/HR/EmployeeReport?rs:Format=PDF&param1=value1
```

### 2. REST API v2.0
```
http://server/ReportServer/Reports/api/v2.0/PowerBIReports/Reports/HR/EmployeeReport/Export/PDF
```

### 3. SOAP Endpoint
```
http://server/ReportServer/ReportExecution2005.asmx
```

## Error Handling

The service provides comprehensive error handling and logging:

```php
try {
    $pdf = $reportService->generatePdfReport($folder, $report, $params);
} catch (\RuntimeException $e) {
    // Configuration error
    Log::error('SSRS Configuration Error: ' . $e->getMessage());
} catch (\Exception $e) {
    // Report generation error
    Log::error('SSRS Report Error: ' . $e->getMessage());
}
```

## Logging

All SSRS operations are logged:

```
[2024-01-15 10:30:45] SSRS Report Request
    URL: http://server/ReportServer/Reports/HR/EmployeeReport?rs:Format=PDF
    Folder: /Reports/HR
    Report: EmployeeReport
    Parameters: {"EmployeeId":123}

[2024-01-15 10:30:47] SSRS Report Generation Failed
    Status: 401
    Body: Unauthorized
```

## Security Considerations

1. **Environment Variables**: Always store credentials in `.env`, never commit them
2. **HTTPS**: Use HTTPS for SSRS connections in production
3. **Authentication**: Ensure API endpoints are protected with authentication middleware
4. **Validation**: Always validate folder names and report names to prevent path traversal
5. **Rate Limiting**: Consider adding rate limiting to report endpoints

## Troubleshooting

### Connection Fails

```bash
# Test connection
curl -u username:password http://your-server/ReportServer/api/v2.0/Session
```

### Reports Don't Render

1. Check SSRS permissions for the user
2. Verify report path (case-sensitive)
3. Check report parameters are correct
4. Review Laravel logs: `storage/logs/laravel.log`

### Timeout Issues

Increase timeout in `.env`:
```env
SSRS_TIMEOUT=300
```

## Advanced Usage

### Custom Report Service

Extend the base service:

```php
use Incoder\DDD\Support\Reporting\SsrsReportService;

class CustomReportService extends SsrsReportService
{
    public function generateEmployeeReport(int $employeeId)
    {
        return $this->streamPdfReport(
            '/Reports/HR',
            'EmployeeReport',
            ['EmployeeId' => $employeeId]
        );
    }
}
```

### Report Caching

```php
use Illuminate\Support\Facades\Cache;

$cacheKey = "report_{$folderName}_{$reportName}_" . md5(json_encode($parameters));

$pdf = Cache::remember($cacheKey, 3600, function() use ($folderName, $reportName, $parameters) {
    return $this->reportService->generatePdfReport($folderName, $reportName, $parameters);
});
```

## Testing

```bash
# Test connection
php artisan tinker
>>> app(Incoder\DDD\Support\Reporting\Contracts\IReportService::class)->testConnection()

# Generate test report
>>> $pdf = app(Incoder\DDD\Support\Reporting\Contracts\IReportService::class)->generatePdfReport('/Reports', 'TestReport', []);
>>> file_put_contents('test.pdf', $pdf);
```

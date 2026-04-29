<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SQL Server Reporting Services (SSRS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for connecting to SQL Server Reporting Services.
    | This uses SOAP web services for report execution and management.
    |
    | SOAP Endpoints Used:
    | - ReportExecution2005.asmx - For rendering reports
    | - ReportService2010.asmx - For report metadata and management
    |
    */

    'ssrs' => [
        /*
         * Base URL of your SSRS server
         * Example: http://your-ssrs-server/ReportServer
         * 
         * Note: This should point to ReportServer, not ReportServerWeb
         * The service will automatically append SOAP endpoint paths:
         * - /ReportExecution2005.asmx?wsdl (for rendering)
         * - /ReportService2010.asmx?wsdl (for metadata)
         */
        'base_url' => env('SSRS_BASE_URL', 'http://localhost/ReportServer'),

        /*
         * SSRS username for basic authentication
         */
        'username' => env('SSRS_USERNAME', ''),

        /*
         * SSRS password for basic authentication
         */
        'password' => env('SSRS_PASSWORD', ''),

        /*
         * Request timeout in seconds
         */
        'timeout' => env('SSRS_TIMEOUT', 120),

        /*
         * Enable caching of report results (in seconds)
         * Set to 0 to disable caching
         */
        'cache_ttl' => env('SSRS_CACHE_TTL', 0),

        /*
         * Default report parameters (global defaults)
         */
        'default_parameters' => [
            // 'CompanyId' => env('COMPANY_ID', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Definitions
    |--------------------------------------------------------------------------
    |
    | Define your reports here for easier reference throughout the application.
    | This is optional but recommended for better organization.
    |
    */

    'reports' => [
        // Example report definitions
        // 'employee_list' => [
        //     'folder' => '/Reports/HR',
        //     'name' => 'EmployeeList',
        //     'description' => 'List of all employees',
        //     'parameters' => ['DepartmentId', 'StatusId'],
        // ],
    ],
];

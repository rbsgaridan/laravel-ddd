<?php

/**
 * OpenAPI / Swagger documentation configuration for the incoder-ddd package.
 *
 * Publish this file to your application with:
 *   php artisan vendor:publish --tag=api-docs
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Enable / Disable Documentation
    |--------------------------------------------------------------------------
    | Set to false to completely disable the Swagger UI and the live spec
    | endpoint. Useful to disable in production behind a feature flag.
    */
    'enabled' => env('API_DOCS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | API Info
    |--------------------------------------------------------------------------
    */
    'title' => env('APP_NAME', 'Laravel').' API',
    'description' => '',
    'version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    | UI:   Swagger interactive docs browser
    | Spec: Raw OpenAPI 3.0 JSON spec (consumed by the UI and external tools)
    */
    'path' => '/api/docs',
    'spec_path' => '/api/docs/spec',

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    | Applied to both the UI and the spec route.  To lock down in production,
    | add 'auth' or a custom middleware: ['web', 'auth']
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Additional Servers
    |--------------------------------------------------------------------------
    | The primary server is always derived from APP_URL. Add extras here.
    |
    | Example:
    |   ['url' => 'https://staging.example.com', 'description' => 'Staging'],
    */
    'servers' => [],

    /*
    |--------------------------------------------------------------------------
    | Security Schemes
    |--------------------------------------------------------------------------
    | Keys here become the reusable security scheme names in #/components/
    | securitySchemes.  Routes with 'auth' middleware automatically reference
    | the 'sanctum' scheme.
    */
    'security_schemes' => [
        // Bearer token — for mobile apps / external API clients.
        // Obtain via POST /api/auth/login, then set: Authorization: Bearer {token}
        'sanctum' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'Sanctum',
            'description' => 'Bearer token — obtain via POST /api/auth/login',
        ],
        // Cookie / session — for browser-based SPA clients (Inertia.js).
        // Login at /login first; the browser sends the session cookie automatically.
        'sessionAuth' => [
            'type' => 'apiKey',
            'in' => 'cookie',
            'name' => 'laravel_session',
            'description' => 'Session cookie — for browser/Inertia SPA clients. Log in at /login first.',
        ],
    ],

];

<?php

return [
    'discovery' => [
        'composer_classmap' => 'vendor/composer/autoload_classmap.php',
        'controller_path' => 'app/Http/Controllers',
    ],

    'namespaces' => [
        'domain' => 'Core\\Domain',
        'application' => 'Core\\Application',
        'domain_service' => 'Core\\Domain',
        'infrastructure_repository' => 'Core\\Infrastructure\\Eloquent\\Repositories',
        'shared_permission' => 'Core\\Shared\\Permission',
    ],

    'paths' => [
        'domain' => 'core/Domain',
        'application' => 'core/Application',
        'infrastructure_repository' => 'core/Infrastructure/Eloquent/Repositories',
        'shared_permission' => 'core/Shared/Permission',
        'web_controller' => 'app/Http/Controllers/Web',
        'frontend_pages' => 'resources/js/pages',
        'typescript_output' => 'resources/js/proxies',
    ],

    'routing' => [
        'app_service_prefix' => '/app/api',
    ],

    'flutter' => [
        'root' => '../flutter',
        'output' => '../flutter/lib/src/generated/openapi',
        'config' => '../flutter/tool/openapi-generator-config.yaml',
        'jar' => '../flutter/.tooling/openapi-generator/openapi-generator-cli-7.21.0.jar',
        'generator' => 'dart-dio',
    ],
];

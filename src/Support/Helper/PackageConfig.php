<?php

namespace Incoder\DDD\Support\Helper;

class PackageConfig
{
    public static function loadClassMap(): array
    {
        $path = self::resolveBaseRelative((string) config('incoder-ddd.discovery.composer_classmap', 'vendor/composer/autoload_classmap.php'));

        if (! is_file($path)) {
            return [];
        }

        $classMap = require $path;

        return is_array($classMap) ? $classMap : [];
    }

    public static function controllerPath(): string
    {
        return self::resolveBaseRelative((string) config('incoder-ddd.discovery.controller_path', 'app/Http/Controllers'));
    }

    public static function appServiceRoutePrefix(): string
    {
        return '/'.trim((string) config('incoder-ddd.routing.app_service_prefix', '/app/api'), '/');
    }

    public static function domainNamespace(?string $suffix = null): string
    {
        return self::appendNamespace(
            (string) config('incoder-ddd.namespaces.domain', 'Core\\Domain'),
            $suffix
        );
    }

    public static function applicationNamespace(?string $suffix = null): string
    {
        return self::appendNamespace(
            (string) config('incoder-ddd.namespaces.application', 'Core\\Application'),
            $suffix
        );
    }

    public static function domainServiceNamespace(?string $suffix = null): string
    {
        return self::appendNamespace(
            (string) config('incoder-ddd.namespaces.domain_service', 'Core\\Domain'),
            $suffix
        );
    }

    public static function infrastructureRepositoryNamespace(?string $suffix = null): string
    {
        return self::appendNamespace(
            (string) config('incoder-ddd.namespaces.infrastructure_repository', 'Core\\Infrastructure\\Eloquent\\Repositories'),
            $suffix
        );
    }

    public static function sharedPermissionNamespace(?string $suffix = null): string
    {
        return self::appendNamespace(
            (string) config('incoder-ddd.namespaces.shared_permission', 'Core\\Shared\\Permission'),
            $suffix
        );
    }

    public static function domainPath(?string $suffix = null): string
    {
        return self::appendPath(
            (string) config('incoder-ddd.paths.domain', 'core/Domain'),
            $suffix
        );
    }

    public static function applicationPath(?string $suffix = null): string
    {
        return self::appendPath(
            (string) config('incoder-ddd.paths.application', 'core/Application'),
            $suffix
        );
    }

    public static function infrastructureRepositoryPath(?string $suffix = null): string
    {
        return self::appendPath(
            (string) config('incoder-ddd.paths.infrastructure_repository', 'core/Infrastructure/Eloquent/Repositories'),
            $suffix
        );
    }

    public static function sharedPermissionPath(?string $suffix = null): string
    {
        return self::appendPath(
            (string) config('incoder-ddd.paths.shared_permission', 'core/Shared/Permission'),
            $suffix
        );
    }

    public static function typeScriptOutputPath(): string
    {
        return (string) config('incoder-ddd.paths.typescript_output', 'resources/js/proxies');
    }

    public static function webControllerPath(?string $suffix = null): string
    {
        return self::appendPath(
            (string) config('incoder-ddd.paths.web_controller', 'app/Http/Controllers/Web'),
            $suffix
        );
    }

    public static function frontendPagesPath(?string $suffix = null): string
    {
        return self::appendPath(
            (string) config('incoder-ddd.paths.frontend_pages', 'resources/js/pages'),
            $suffix
        );
    }

    public static function flutterRoot(): string
    {
        return (string) config('incoder-ddd.flutter.root', '../flutter');
    }

    public static function flutterOutputPath(): string
    {
        return (string) config('incoder-ddd.flutter.output', '../flutter/lib/src/generated/openapi');
    }

    public static function flutterConfigPath(): string
    {
        return (string) config('incoder-ddd.flutter.config', '../flutter/tool/openapi-generator-config.yaml');
    }

    public static function flutterJarPath(): string
    {
        return (string) config('incoder-ddd.flutter.jar', '../flutter/.tooling/openapi-generator/openapi-generator-cli-7.21.0.jar');
    }

    public static function flutterGenerator(): string
    {
        return (string) config('incoder-ddd.flutter.generator', 'dart-dio');
    }

    private static function resolveBaseRelative(string $path): string
    {
        if (self::isAbsolutePath($path)) {
            return $path;
        }

        return base_path(str_replace('\\', '/', trim($path, '/\\')));
    }

    private static function appendNamespace(string $base, ?string $suffix = null): string
    {
        $base = trim($base, '\\');

        if ($suffix === null || $suffix === '') {
            return $base;
        }

        return $base.'\\'.trim($suffix, '\\');
    }

    private static function appendPath(string $base, ?string $suffix = null): string
    {
        $base = str_replace('\\', '/', trim($base, '/\\'));

        if ($suffix === null || $suffix === '') {
            return $base;
        }

        return $base.'/'.str_replace('\\', '/', trim($suffix, '/\\'));
    }

    private static function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || str_starts_with($path, '\\\\')
            || str_starts_with($path, '/');
    }
}

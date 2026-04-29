<?php

namespace Incoder\DDD\Support\Routing;

use Incoder\DDD\Support\Routing\RouteRegistrar;
use ReflectionClass;
use Incoder\DDD\Application\Services\AppServiceBase;

class AppServiceRouteScanner
{
    private array $_classMap;

    /**
     * @param array $classMap Composer's autoload_classmap.php contents
     */
    public function __construct(array $classMap)
    {
        $this->_classMap = $classMap;
    }

    public function register(): void
    {
        foreach ($this->findAppServiceClasses() as $class) {
            $baseUri = $this->getBaseUri($class);
            $registrar = new RouteRegistrar($baseUri, $class);
            
            $registrar->registerCustomRoutes();
            $registrar->registerCrudRoutes();
        }
    }

    private function getBaseUri(string $class): string
    {
        $reflection = new ReflectionClass($class);
        $serviceName = strtolower(str_replace('AppService', '', $reflection->getShortName()));
        return "/app/api/{$serviceName}";
    }

    private function findAppServiceClasses(): array
    {
        return array_filter(
            array_keys($this->_classMap),
            function ($class) {
                // Filter classes that:
                // 1. Are in Core\Application namespace
                // 2. End with AppService
                // 3. Are not interfaces (don't start with I)
                // 4. Exist and are subclasses of AppServiceBase
                return str_starts_with($class, 'Core\\Application\\') &&
                    str_ends_with($class, 'AppService') &&
                    !str_starts_with(basename($class), 'I') &&
                    class_exists($class) &&
                    is_subclass_of($class, AppServiceBase::class);
            }
        );
    }
    
}

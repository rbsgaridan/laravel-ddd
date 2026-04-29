<?php

namespace Incoder\DDD\Support\Routing;

use ReflectionClass;
use ReflectionMethod;
use Incoder\DDD\Application\Services\AppServiceBase;
use Symfony\Component\Finder\Finder;
use Illuminate\Support\Facades\Route;
use Incoder\DDD\Support\Attributes\RouteAttribute;

/**
 * Summary of RouteScanner
 * This class is responsible for scanning and registering routes for controllers and application services.
 */
class RouteScanner
{   
    /**
     * Summary of __construct
     * This constructor initializes the RouteScanner with the path to the controller files.
     * @param string $controllerPath
     */
    public function __construct(
        private string $controllerPath
    ) {}

    protected $excludeMethodsFromPHP = [
            '__construct',
            '__destruct',
            '__call',
            '__callStatic',
            '__get',
            '__set',
            '__isset',
            '__unset',
            '__sleep',
            '__wakeup',
            '__serialize',
            '__unserialize',
            '__toString',
            '__debugInfo',
            '__clone'
        ];
    
    /**
     * Summary of register
     * This method is responsible for registering routes for the found controller and application service classes.
     * @return void
     */
    public function register(): void
    {
        foreach ($this->findControllerClasses() as $class)
        {
            $this->registerRoutesFromController($class);
        }
        
    }

    /**
     * Summary of findControllerClasses
     * This method is responsible for finding all controller classes in the specified directory.
     * @return string[]
     */
    private function findControllerClasses(): array
    {
        $classes = [];
        $finder = new Finder();
        $finder->files()->in($this->controllerPath)->name('*Controller.php');

        foreach ($finder as $file)
        {
            $fqcn = $this->getClassFromFile($file->getRealPath());
            if ($fqcn && class_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }

    /**
     * Summary of findAppServiceClasses
     * This method is responsible for finding all application service classes in the specified directory.
     * @return string[]
     */
    private function findAppServiceClasses(): array
    {
        $classes = [];
        $finder = new Finder();

        $finder
            ->files()
            ->in(base_path('core/Application'))
            ->name('*AppService.php')
            ->notName('I*AppService.php');

        foreach ($finder as $file)
        {
            $fqcn = $this->getClassFromFile($file->getRealPath());
            if ($fqcn && class_exists($fqcn)) {
                $reflection = new ReflectionClass($fqcn);
                if ($reflection->isSubclassOf(AppServiceBase::class)) {
                    $classes[] = $fqcn;
                }
            }
        }

        return $classes;
    }


    /**
     * Summary of getClassFromFile
     * This method extracts the fully qualified class name from a PHP file.
     * @param string $path
     * @return string|null
     */
    private function getClassFromFile(string $path): ?string
    {
        $content = file_get_contents($path);

        if (preg_match('/namespace\s+(.+?);/s', $content, $nsMatch) &&
            preg_match('/\bclass\s+([^\s]+)/', $content, $classMatch)) {
            return $nsMatch[1] . '\\' . $classMatch[1];
        }

        return null;
    }

    private function registerRoutesFromController(string $class): void
    {
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || $reflection->getShortName() === 'Controller.php') {
            return;
        }

        $existingRoutes = collect(Route::getRoutes())->map(fn($r) => $r->getActionName())->all();

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (in_array($method->getName(), $this->excludeMethodsFromPHP)) {
                continue;
            }

            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $fqcnAction = $class . '@' . $method->getName();
            $invokeAction = $method->getName() === '__invoke' ? $class : null;
            if (
                in_array($fqcnAction, $existingRoutes, true) ||
                ($invokeAction !== null && in_array($invokeAction, $existingRoutes, true))
            ) {
                continue;
            }

            $attributes = $method->getAttributes(RouteAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);

            foreach ($attributes as $attr) {
                /** @var RouteAttribute $route */
                $route = $attr->newInstance();

                \Route::match($route->methods, $route->uri, [$class, $method->getName()])
                        ->name($route->name ?? null)
                        ->middleware($route->middleware);
                
            }
        }
    }


    /**
     * Summary of registerRoutesFromAppService
     * @param string $class
     * @return void
     */
    // private function registerRoutesFromAppService(string $class): void
    // {
    //     $reflection = new ReflectionClass($class);

    //     if (!$reflection->isSubclassOf(AppServiceBase::class)) {
    //         return;
    //     }

    //     $serviceName = strtolower(str_replace('AppService', '', $reflection->getShortName()));
    //     $baseUri = "/$serviceName";


    //     foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    //         $action = $method->getName();

    //         if (in_array($action, $this->excludeMethodsFromPHP, true)) {
    //             continue;
    //         }

    //         $attributes = $method->getAttributes(\Incoder\DDD\Support\Attributes\HttpMethod::class);
    //         $httpMethods = $attributes
    //             ? $attributes[0]->newInstance()->methods
    //             : ['GET'];

    //         $uri = $attributes
    //             ? $attributes[0]->newInstance()->uri
    //             : $baseUri . '/' . $action;
    //         $name = $attributes
    //             ? $attributes[0]->newInstance()->name
    //             : $serviceName . '.' . $action;
    //         $middleware = $attributes
    //             ? $attributes[0]->newInstance()->middleware
    //             : ['api'];

    //         $params = [];
    //         foreach ($method->getParameters() as $param) {
    //             $params[] = '{' . $param->getName() . '}';
    //         }
    //         if ($params) {
    //             $uri .= '/' . implode('/', $params);
    //         }

    //         $route = new RouteAttribute(
    //             methods: $httpMethods,
    //             uri: $uri,
    //             name: $serviceName . '.' . $action,
    //             middleware: ['api']
    //         );

    //         $this->registerRouteInGroups($route, [$class, $action]);
    //     }
    // }


    /**
     * Registers CRUD routes automatically
     */
    private function registerCrudRoutes(RouteAttribute $route, string $class): void
    {
        $baseUri = rtrim($route->uri, '/');

        $crudMap = [
            ['GET',    $baseUri,          'index'],
            ['POST',   $baseUri,          'store'],
            ['GET',    "$baseUri/{id}",   'show'],
            ['PUT',    "$baseUri/{id}",   'update'],
            ['DELETE', "$baseUri/{id}",   'destroy'],
        ];

        foreach ($crudMap as [$method, $uri, $action]) {
            if (method_exists($class, $action)) {
                $crudRoute = new RouteAttribute($method, $uri, $route->name ? $route->name . '.' . $action : null, $route->middleware);
                $this->registerRouteInGroups($crudRoute, [$class, $action]);
            }
        }
    }

    /**
     * Registers the route in each middleware group,
     * with API auto-prefixing.
     */
    private function registerRouteInGroups(RouteAttribute $route, array $action): void
    {
        foreach ($route->middleware as $group) {
            Route::middleware($group)->group(function () use ($route, $action, $group) {
                $uri = $group === 'api' && !str_starts_with($route->uri, '/api')
                    ? '/api' . $route->uri
                    : $route->uri;

                Route::match($route->methods, $uri, $action)
                    ->name($route->name ? ($group . '.' . $route->name) : null)
                    ->middleware(array_values(array_diff($route->middleware, [$group])));
            });
        }
    }
}

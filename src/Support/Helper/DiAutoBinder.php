<?php
namespace Incoder\DDD\Support\Helper;

use Illuminate\Support\Facades\App;
use ReflectionClass;


/**
 * Class DiAutoBinder
 *
 * A helper class to automatically bind interfaces to their implementations
 * based on naming conventions and namespaces.
 * 
 * Usage Example:
 * DiAutoBinder::bindByNamespace('Core\\Domain\\', 'I', 'Repository', 'Core\\Infrastructure');\
 * 
 * This class can be used in service providers or test setups to streamline
 * the registration of dependencies in the Laravel service container.
 * 
 * Note: This implementation assumes that the class map is available via Composer's
 * autoload_classmap.php for more reliable discovery of classes and interfaces.
 * 
 * Incoder\DDD\Support\Helper
 * 
 */
class DiAutoBinder
{
    private $_classMap;
    public function __construct(array $classMap)
    {
        $this->_classMap = $classMap;
    }
    /**
     * This function automatically binds interfaces to their concrete implementations in the Laravel container, based on namespace and naming conventions, ensuring only direct and concrete relationships are registered.
     */
    public function bindByNamespace(
        // Application $app,
        string $interfaceNamespace,     // e.g. 'Core\\Application\\'
        string $interfacePrefix,        // e.g. 'I'
        string $interfaceSuffix,        // e.g. 'AppService'
        string $implementationNamespace // e.g. 'Core\\Application\\'
    ): void {

        $implementationClasses = array_filter(
            $this->_classMap,
            fn($class) => str_starts_with($class, $implementationNamespace),
            ARRAY_FILTER_USE_KEY
        );

        foreach ($implementationClasses as $class => $path) {
            if (
                str_starts_with($class, $implementationNamespace) &&
                class_exists($class)
            ) {
                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                // Get only directly implemented interfaces
                $directInterfaces = $reflection->getInterfaceNames();
                if ($parent = $reflection->getParentClass()) {
                    $parentInterfaces = $parent->getInterfaceNames();
                    $directInterfaces = array_diff($directInterfaces, $parentInterfaces);
                }

                foreach ($directInterfaces as $interface) {
                    $interfaceReflection = new ReflectionClass($interface);
                    if (
                        $interfaceReflection->isInterface() &&
                        str_starts_with($interface, $interfaceNamespace) &&
                        str_starts_with($interfaceReflection->getShortName(), $interfacePrefix) &&
                        str_ends_with($interfaceReflection->getShortName(), $interfaceSuffix)
                    ) {
                        App::bind($interface, $class);
                        // Log::info("DI registered: {$interface} => {$class}");
                    }
                }
            }
        }
    }
}
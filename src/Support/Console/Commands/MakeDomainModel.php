<?php

namespace Incoder\DDD\Support\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;

class MakeDomainModel extends Command
{
    protected $signature = 'make:domain-model {name} {--type=entity} {--format=format} {--incrementing=incrementing} {--schema=schema}{--only-model : Generate only the Domain Model file}';
    protected $description = 'Create a new Domain model, repository interface, implementation, migration, and DI binding';

    public function handle()
    {
        $name = $this->argument('name');
        $type = $this->option('type');
        $format = $this->option('format');
        $incrementing = $this->option('incrementing');
        $schema = $this->option('schema');

        switch ($type) {
            case 'aggregate':
            case 'entity':
                break;
            default:
                $this->error('The --type flag should be a string either aggregate or entity value.');
                return 1;
        }


        switch ($format) {
            case 'string':
            case 'int':
                break;
            default:
                $this->error('The --format flag should be a string either string or int value.');
                return 1;
        }

        switch ($incrementing) {
            case 'true':
            case 'false':
                break;
            default:
                $this->error('The --incrementing flag should be a boolean value.');
                return 1;
        }

        if ($this->option('only-model')) {
            $name = $this->argument('name');
            $type = $this->option('type');
            $format = $this->option('format');
            $incrementing = $this->option('incrementing');

            $plural = Str::pluralStudly($name);
            $filesystem = new Filesystem();
            $domainFolder = $this->createDomainFolder($filesystem, $plural);

            $this->createModelClass($filesystem, $domainFolder, $name, $type, $plural, $format, $incrementing);

            $this->info("Model-only creation complete: {$domainFolder}/{$name}.php");
            return 0;
        }

        if ($format == 'string' && $incrementing == 'true') {
            $this->error('String key type should be --incrementing=false.');
            return 1;
        }

        if ($format == 'int' && $incrementing == 'false') {
            $this->error('Integer key type should be --incrementing=true.');
            return 1;
        }



        $plural = Str::pluralStudly($name);

        $filesystem = new Filesystem();

        $domainFolder = $this->createDomainFolder($filesystem, $plural);
        $applicationFolder = $this->createApplicationFolder($filesystem, $plural);

        $modelClass = $this->createModelClass($filesystem, $domainFolder, $name, $type, $plural, $format, $incrementing);

        $repository = $this->createRepositoryInterface($filesystem, $domainFolder, $name, $plural);

        $infraFolder = $this->createInfraFolder($filesystem);
        $this->createRepositoryImplementation($filesystem, $infraFolder, $name, $plural);
        $this->createMigration($filesystem, $name, $plural, $format, $schema);


        // App Service
        $applicationFolder = $this->createApplicationFolder($filesystem, $plural);
        $contractFolder = $this->createContractFolder($filesystem, $applicationFolder);
        $dtoClass = $this->createDTO($filesystem, $contractFolder, $name, $plural);
        $dtoPaginatedClass = $this->createPaginatedDTO($filesystem, $applicationFolder, $name, $plural);
        $dtoClassList = $this->createListDTO($filesystem, $applicationFolder, $name, $plural);
        $interfaceName = $this->createInterfaceAppService($filesystem, $applicationFolder, $name, $plural);
        $this->createAppServiceClass($filesystem, $applicationFolder, $name, $plural, $repository, $dtoClass, $dtoClassList, $dtoPaginatedClass, $modelClass, $interfaceName);

        $this->info("O ayan, dagdagan mo na lang ng properties and methods sa {$domainFolder}/{$name}.php tapos migrate mo na.");
    }

    public function createApplicationFolder(Filesystem $filesystem, string $plural): string
    {
        $relativePath = "core/Application/{$plural}";
        $filesystem->ensureDirectoryExists(base_path($relativePath));
        return $relativePath;
    }

    public function createContractFolder(Filesystem $filesystem, string $applicationFolder): string
    {
        $relativePath = $applicationFolder . '/Contracts';
        $filesystem->ensureDirectoryExists(base_path($relativePath));
        return $relativePath;
    }


    private function createDTO(Filesystem $filesystem, string $contractFolder, string $name, string $plural): string
    {
        $className = "{$name}DTO";
        $dtoClass = "<?php

namespace Core\\Application\\{$plural}\\Contracts;

use Incoder\\DDD\\Application\\DTOs\\DTOBase;

class {$className} extends DTOBase
{
    // Add properties and methods for the DTO here
}
";
        $filesystem->put("{$contractFolder}/{$name}DTO.php", $dtoClass);
        $this->info("Created DTO for {$name}.");

        return $className;
    }

    private function createPaginatedDTO(Filesystem $filesystem, string $applicationFolder, string $name, string $plural): string
    {
        $className = "{$name}PaginatedDTO";
        $dtoClass = "<?php

namespace Core\\Application\\{$plural}\\Contracts;

use Incoder\\DDD\\Application\\DTOs\\PaginatedDTOBase;
use Spatie\\LaravelData\\DataCollection;

class {$name}PaginatedDTO extends PaginatedDTOBase
{
    public function __construct(
        public DataCollection \$data, 
        public array \$meta
    ){
        parent::__construct(\$data, \$meta);
    }
    // Add properties and methods for the Paginated DTO here
}
";
        $filesystem->put("{$applicationFolder}/Contracts/{$name}PaginatedDTO.php", $dtoClass);
        $this->info("Created Paginated DTO for {$name}.");
        return $className;
    }

    private function createListDTO(Filesystem $filesystem, string $applicationFolder, string $name, string $plural): string
    {
        $className = "{$name}ListDTO";
        $dtoListClass = "<?php

namespace Core\\Application\\{$plural}\\Contracts;

use Incoder\\DDD\\Application\\DTOs\\DTOBase;

class {$className} extends DTOBase
{
    // Add properties and methods for the Paginated DTO here
}
";
        $filesystem->put("{$applicationFolder}/Contracts/{$name}ListDTO.php", $dtoListClass);
        $this->info("Created List DTO for {$name}.");
        return $className;
    }


    private function createInterfaceAppService($filesystem, string $applicationFolder, string $name, string $plural): string
    {
        $interfaceName = "I{$name}AppService";
        $interfaceContent = "<?php
namespace Core\\Application\\{$plural};

use Incoder\\DDD\\Application\\Contracts\\IAppService;

interface {$interfaceName} extends IAppService
{
    // Define service methods for {$name}-related operations
}
";

        $filesystem->put("{$applicationFolder}/I{$name}AppService.php", $interfaceContent);
        $this->info("Created {$applicationFolder}/Contracts/I{$name}AppService.php");
        return $interfaceName;
    }

    private function createAppServiceClass(Filesystem $filesystem, string $applicationFolder, string $name, string $plural, string $repository, string $dtoClass, string $dtoListClass, string $dtoPaginatedClass, string $modelClass, string $interfaceName)
    {
        $className = "{$name}AppService";
        $appServiceClass = "<?php

namespace Core\\Application\\{$plural};

use Core\\Application\\{$plural}\\Contracts\\{$dtoClass};
use Core\\Application\\{$plural}\\Contracts\\{$dtoListClass};
use Core\\Application\\{$plural}\\Contracts\\{$dtoPaginatedClass};
use Incoder\\DDD\\Application\\Services\\AppServiceBase;
use Core\\Domain\\{$plural}\\{$repository};
use Core\\Domain\\{$plural}\\{$modelClass};
use Psr\Log\LoggerInterface;
use Illuminate\Contracts\Auth\Guard;

class {$className} extends AppServiceBase implements {$interfaceName}
{

    public function __construct(
        {$repository} \$repository,
        Guard \$auth,
        ?LoggerInterface \$logger = null,
    ) {
        parent::__construct(
            \$repository,
            {$modelClass}::class,
            {$dtoClass}::class,
            {$dtoListClass}::class,
            {$dtoPaginatedClass}::class,
            \$auth,
            \$logger,
        );
    }
}        
        ";

        $filesystem->put("{$applicationFolder}/{$name}AppService.php", $appServiceClass);
        $this->info("Created {$applicationFolder}/{$name}AppService.php");
    }

    private function createDomainFolder(Filesystem $filesystem, string $plural): string
    {
        $domainFolder = base_path("core/Domain/{$plural}");
        $filesystem->ensureDirectoryExists(base_path('core/Domain'));
        $filesystem->ensureDirectoryExists($domainFolder);
        return $domainFolder;
    }

    private function createModelClass(Filesystem $filesystem, string $domainFolder, string $name, string $type, string $plural, string $format, string $incrementing): string
    {
        $className = "{$name}";
        $modelClass = "<?php

namespace Core\\Domain\\{$plural};

use Incoder\\DDD\\Domain\\Entities\\" . ($type === 'aggregate' ? 'AggregateRoot' : 'Entity') . ";

class {$name} extends " . ($type === 'aggregate' ? 'AggregateRoot' : 'Entity') . "
{
    " . ($format === 'string' ? "protected \$keyType = '$format';" : "") . "

    " . ($incrementing === 'true' ? "public \$incrementing = $incrementing;" : "") . "
    // Add properties as fillables and methods here
}
";
        $filesystem->put("{$domainFolder}/{$name}.php", $modelClass);
        $this->info("Created Domain Model for {$name}.");

        return $className;
    }

    private function createRepositoryInterface(Filesystem $filesystem, string $domainFolder, string $name, string $plural): string
    {
        $interfaceClass = "<?php

namespace Core\\Domain\\{$plural};

use Incoder\\DDD\\Domain\\Repositories\\IRepository;

/**
 * @extends IRepository<{$name}>
 */
interface I{$name}Repository extends IRepository
{
    // Add custom repository methods here
}
";
        $filesystem->put("{$domainFolder}/I{$name}Repository.php", $interfaceClass);
        $this->info("Created {$domainFolder}/I{$name}Repository.php");

        return "I{$name}Repository";
    }

    private function createInfraFolder(Filesystem $filesystem): string
    {
        $infraFolder = base_path("core/Infrastructure/Eloquent/Repositories");
        $filesystem->ensureDirectoryExists(base_path('core/Infrastructure/Eloquent'));
        $filesystem->ensureDirectoryExists($infraFolder);
        return $infraFolder;
    }

    private function createRepositoryImplementation(Filesystem $filesystem, string $infraFolder, string $name, string $plural): void
    {
        $repositoryClass = "<?php

namespace Core\\Infrastructure\\Eloquent\\Repositories;

use Core\\Domain\\{$plural}\\{$name};
use Core\\Domain\\{$plural}\\I{$name}Repository;
use Incoder\\DDD\\Infrastructure\\Repositories\\EloquentRepositoryBase;

class {$name}Repository extends EloquentRepositoryBase implements I{$name}Repository
{
    public function __construct()
    {
        parent::__construct({$name}::class);
    }
}
";
        $filesystem->put("{$infraFolder}/{$name}Repository.php", $repositoryClass);
        $this->info("Created {$infraFolder}/{$name}Repository.php");
    }

    private function createMigration(Filesystem $filesystem, string $name, string $plural, string $format, string $schema): void
    {
        $migrationName = 'create_' . Str::snake($plural) . '_table';
        $migrationClassName = 'Create' . $plural . 'Table';
        $migrationFolder = base_path('database/migrations');
        $filesystem->ensureDirectoryExists($migrationFolder);

        $migrationFile = date('Y_m_d_His') . "_{$migrationName}.php";
        $migrationContent = "<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

class {$migrationClassName} extends Migration
{
    public function up(): void
    {
        Schema::create('{$schema}." . Str::snake($plural) . "', function (Blueprint \$table) {
            " . ($format === "int" ? '$table->id();' : '$table->uuid("id")->primary();') . "
            \$table->softDeletes();
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$schema}." . Str::snake($plural) . "');
    }
}
";
        $filesystem->put("{$migrationFolder}/{$migrationFile}", $migrationContent);
        $this->info("Created {$migrationFolder}/{$migrationFile}");
    }
}

<?php

namespace Incoder\DDD\Support\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;

class DddGenerateCrud extends Command
{
    protected $signature = 'make:domain-crud
        {name : The name of the existing domain model (e.g. CivilStatus)}
        {--schema=admin : The database schema prefix (e.g. admin, applicant)}
        {--format=int : The primary key format (int or string)}
        {--incrementing=true : Whether the primary key is auto-incrementing}';

    protected $description = 'Generate CRUD scaffold (repos, migration, app service, DTOs, controller, Vue pages) for an existing domain model';

    public function handle(): int
    {
        $name        = $this->argument('name');
        $schema      = $this->option('schema') ?? 'admin';
        $format      = $this->option('format') ?? 'int';
        $incrementing = $this->option('incrementing') ?? 'true';

        $plural = Str::pluralStudly($name);
        $filesystem = new Filesystem();

        // Verify the domain model already exists
        $modelPath = base_path("core/Domain/{$plural}/{$name}.php");
        if (!$filesystem->exists($modelPath)) {
            $this->error("Domain model not found: core/Domain/{$plural}/{$name}.php");
            $this->line("Run 'php artisan make:domain-model' to create a new model first.");
            return 1;
        }

        $this->info("Generating CRUD scaffold for existing model: {$name}");

        // 1. Repository interface + implementation
        $domainFolder = base_path("core/Domain/{$plural}");
        $repositoryInterface = $this->createRepositoryInterface($filesystem, $domainFolder, $name, $plural);

        $infraFolder = base_path("core/Infrastructure/Eloquent/Repositories");
        $filesystem->ensureDirectoryExists(base_path('core/Infrastructure/Eloquent'));
        $filesystem->ensureDirectoryExists($infraFolder);
        $this->createRepositoryImplementation($filesystem, $infraFolder, $name, $plural);

        // 2. Migration
        $this->createMigration($filesystem, $name, $plural, $format, $schema);

        // 3. DTOs + App Service
        $appFolder     = "core/Application/{$plural}";
        $contractFolder = "{$appFolder}/Contracts";
        $filesystem->ensureDirectoryExists(base_path($appFolder));
        $filesystem->ensureDirectoryExists(base_path($contractFolder));

        $dtoClass         = $this->createDTO($filesystem, $contractFolder, $name, $plural);
        $dtoListClass     = $this->createListDTO($filesystem, $contractFolder, $name, $plural);
        $dtoPaginatedClass = $this->createPaginatedDTO($filesystem, $contractFolder, $name, $plural);
        $interfaceName    = $this->createInterfaceAppService($filesystem, $appFolder, $name, $plural);
        $this->createAppServiceClass(
            $filesystem,
            $appFolder,
            $name,
            $plural,
            $repositoryInterface,
            $dtoClass,
            $dtoListClass,
            $dtoPaginatedClass,
            $interfaceName
        );

        // 4. Permissions constants class
        $this->createPermissionsClass($filesystem, $name);

        // 5. Web controller (with permission middleware)
        $this->createController($filesystem, $name, $plural);

        // 6. Vue pages (with permission gates)
        $this->createVuePages($filesystem, $name, $plural);

        $this->newLine();
        $this->info("CRUD scaffold for {$name} generated successfully.");
        $this->newLine();
        $this->line("  Next steps:");
        $this->line("    1. Add properties to DTOs in core/Application/{$plural}/Contracts/");
        $this->line("    2. Register permissions in database/seeders/RolePermissionSeeder.php");
        $this->line("       Import: Core\\Shared\\Permission\\{$name}Permissions");
        $this->line("       Add {$name}Permissions::all() to the appropriate role group(s)");
        $this->line("    3. Run: composer dump-autoload");
        $this->line("    4. Run: php artisan migrate");
        $this->line("    5. Run: php artisan db:seed --class=RolePermissionSeeder");
        $this->line("    6. Run: php artisan proxy:generate");
        $this->line("    7. Customize Vue pages in resources/js/pages/" . Str::camel($plural) . "/");

        return 0;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Permissions constants class
    // ────────────────────────────────────────────────────────────────────────

    private function createPermissionsClass(Filesystem $filesystem, string $name): void
    {
        $sharedFolder = base_path('core/Shared/Permission');
        $filesystem->ensureDirectoryExists($sharedFolder);

        $filePath = "{$sharedFolder}/{$name}Permissions.php";
        if ($filesystem->exists($filePath)) {
            $this->warn("Skipped (exists): core/Shared/Permission/{$name}Permissions.php");
            return;
        }

        $prefix = $name;
        $content = "<?php

namespace Core\\Shared\\Permission;

class {$name}Permissions
{
    private const PREFIX = '{$prefix}';

    public const VIEW   = self::PREFIX . '.view';
    public const CREATE = self::PREFIX . '.create';
    public const EDIT   = self::PREFIX . '.edit';
    public const DELETE = self::PREFIX . '.delete';

    public static function all(): array
    {
        return [
            self::VIEW,
            self::CREATE,
            self::EDIT,
            self::DELETE,
        ];
    }
}
";
        $filesystem->put($filePath, $content);
        $this->info("Created: core/Shared/Permission/{$name}Permissions.php");
    }

    // ────────────────────────────────────────────────────────────────────────
    // Domain layer
    // ────────────────────────────────────────────────────────────────────────

    private function createRepositoryInterface(Filesystem $filesystem, string $domainFolder, string $name, string $plural): string
    {
        $filePath = "{$domainFolder}/I{$name}Repository.php";
        if ($filesystem->exists($filePath)) {
            $this->warn("Skipped (exists): core/Domain/{$plural}/I{$name}Repository.php");
            return "I{$name}Repository";
        }

        $content = "<?php

namespace Core\\Domain\\{$plural};

use Incoder\\DDD\\Domain\\Repositories\\IRepository;

/**
 * @extends IRepository<{$name}>
 */
interface I{$name}Repository extends IRepository
{
    // Define custom repository methods here
}
";
        $filesystem->put($filePath, $content);
        $this->info("Created: core/Domain/{$plural}/I{$name}Repository.php");
        return "I{$name}Repository";
    }

    // ────────────────────────────────────────────────────────────────────────
    // Infrastructure layer
    // ────────────────────────────────────────────────────────────────────────

    private function createRepositoryImplementation(Filesystem $filesystem, string $infraFolder, string $name, string $plural): void
    {
        $filePath = "{$infraFolder}/{$name}Repository.php";
        if ($filesystem->exists($filePath)) {
            $this->warn("Skipped (exists): core/Infrastructure/Eloquent/Repositories/{$name}Repository.php");
            return;
        }

        $content = "<?php

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
        $filesystem->put($filePath, $content);
        $this->info("Created: core/Infrastructure/Eloquent/Repositories/{$name}Repository.php");
    }

    // ────────────────────────────────────────────────────────────────────────
    // Migration
    // ────────────────────────────────────────────────────────────────────────

    private function createMigration(Filesystem $filesystem, string $name, string $plural, string $format, string $schema): void
    {
        $tableName  = Str::snake($plural);
        $migrationFile = date('Y_m_d_His') . "_create_{$tableName}_table.php";
        $migrationFolder = base_path('database/migrations');
        $filesystem->ensureDirectoryExists($migrationFolder);

        $idColumn = $format === 'int'
            ? '$table->id();'
            : '$table->uuid(\'id\')->primary();';

        $content = "<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$schema}.{$tableName}', function (Blueprint \$table) {
            {$idColumn}
            \$table->softDeletes();
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$schema}.{$tableName}');
    }
};
";
        $filesystem->put("{$migrationFolder}/{$migrationFile}", $content);
        $this->info("Created: database/migrations/{$migrationFile}");
    }

    // ────────────────────────────────────────────────────────────────────────
    // Application layer – DTOs
    // ────────────────────────────────────────────────────────────────────────

    private function createDTO(Filesystem $filesystem, string $contractFolder, string $name, string $plural): string
    {
        $className = "{$name}DTO";
        $filePath  = base_path("{$contractFolder}/{$className}.php");
        if ($filesystem->exists($filePath)) {
            $this->warn("Skipped (exists): {$contractFolder}/{$className}.php");
            return $className;
        }

        $content = "<?php

namespace Core\\Application\\{$plural}\\Contracts;

use Incoder\\DDD\\Application\\DTOs\\DTOBase;

class {$className} extends DTOBase
{
    public function __construct(
        public readonly ?int \$id = null,
        // TODO: Add your DTO properties here
    ) {}

    public static function rules(): array
    {
        return [
            'id' => 'nullable|integer',
            // TODO: Add validation rules matching your properties
        ];
    }
}
";
        $filesystem->put($filePath, $content);
        $this->info("Created: {$contractFolder}/{$className}.php");
        return $className;
    }

    private function createListDTO(Filesystem $filesystem, string $contractFolder, string $name, string $plural): string
    {
        $className = "{$name}ListDTO";
        $filePath  = base_path("{$contractFolder}/{$className}.php");
        if ($filesystem->exists($filePath)) {
            $this->warn("Skipped (exists): {$contractFolder}/{$className}.php");
            return $className;
        }

        $content = "<?php

namespace Core\\Application\\{$plural}\\Contracts;

use Incoder\\DDD\\Application\\DTOs\\DTOBase;

class {$className} extends DTOBase
{
    public function __construct(
        public readonly ?int \$id = null,
        // TODO: Add list-view properties here
    ) {}
}
";
        $filesystem->put($filePath, $content);
        $this->info("Created: {$contractFolder}/{$className}.php");
        return $className;
    }

    private function createPaginatedDTO(Filesystem $filesystem, string $contractFolder, string $name, string $plural): string
    {
        $className = "{$name}PaginatedDTO";
        $filePath  = base_path("{$contractFolder}/{$className}.php");
        if ($filesystem->exists($filePath)) {
            $this->warn("Skipped (exists): {$contractFolder}/{$className}.php");
            return $className;
        }

        $content = "<?php

namespace Core\\Application\\{$plural}\\Contracts;

use Incoder\\DDD\\Application\\DTOs\\PaginatedDTOBase;
use Spatie\\LaravelData\\DataCollection;

class {$className} extends PaginatedDTOBase
{
    public function __construct(
        public DataCollection \$data,
        public array \$meta,
    ) {
        parent::__construct(\$data, \$meta);
    }
}
";
        $filesystem->put($filePath, $content);
        $this->info("Created: {$contractFolder}/{$className}.php");
        return $className;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Application layer – App Service
    // ────────────────────────────────────────────────────────────────────────

    private function createInterfaceAppService(Filesystem $filesystem, string $appFolder, string $name, string $plural): string
    {
        $interfaceName = "I{$name}AppService";
        $filePath      = base_path("{$appFolder}/{$interfaceName}.php");
        if ($filesystem->exists($filePath)) {
            $this->warn("Skipped (exists): {$appFolder}/{$interfaceName}.php");
            return $interfaceName;
        }

        $content = "<?php

namespace Core\\Application\\{$plural};

use Incoder\\DDD\\Application\\Contracts\\IAppService;

interface {$interfaceName} extends IAppService
{
    // Define additional service methods for {$name}-related operations
}
";
        $filesystem->put($filePath, $content);
        $this->info("Created: {$appFolder}/{$interfaceName}.php");
        return $interfaceName;
    }

    private function createAppServiceClass(
        Filesystem $filesystem,
        string $appFolder,
        string $name,
        string $plural,
        string $repositoryInterface,
        string $dtoClass,
        string $dtoListClass,
        string $dtoPaginatedClass,
        string $interfaceName
    ): void {
        $className = "{$name}AppService";
        $filePath  = base_path("{$appFolder}/{$className}.php");
        if ($filesystem->exists($filePath)) {
            $this->warn("Skipped (exists): {$appFolder}/{$className}.php");
            return;
        }

        $content = "<?php

namespace Core\\Application\\{$plural};

use Core\\Application\\{$plural}\\Contracts\\{$dtoClass};
use Core\\Application\\{$plural}\\Contracts\\{$dtoListClass};
use Core\\Application\\{$plural}\\Contracts\\{$dtoPaginatedClass};
use Core\\Domain\\{$plural}\\{$repositoryInterface};
use Core\\Domain\\{$plural}\\{$name};
use Illuminate\\Contracts\\Auth\\Guard;
use Incoder\\DDD\\Application\\Services\\AppServiceBase;
use Psr\\Log\\LoggerInterface;

class {$className} extends AppServiceBase implements {$interfaceName}
{
    public function __construct(
        {$repositoryInterface} \$repository,
        Guard \$auth,
        ?LoggerInterface \$logger = null,
    ) {
        parent::__construct(
            \$repository,
            {$name}::class,
            {$dtoClass}::class,
            {$dtoListClass}::class,
            {$dtoPaginatedClass}::class,
            \$auth,
            \$logger,
        );
    }
}
";
        $filesystem->put($filePath, $content);
        $this->info("Created: {$appFolder}/{$className}.php");
    }

    // ────────────────────────────────────────────────────────────────────────
    // HTTP layer – Controller
    // ────────────────────────────────────────────────────────────────────────

    private function createController(Filesystem $filesystem, string $name, string $plural): void
    {
        $controllerFolder = app_path('Http/Controllers/Web');
        $filesystem->ensureDirectoryExists($controllerFolder);

        $className   = "{$name}Controller";
        $filePath    = "{$controllerFolder}/{$className}.php";
        if ($filesystem->exists($filePath)) {
            $this->warn("Skipped (exists): app/Http/Controllers/Web/{$className}.php");
            return;
        }

        $routePrefix = Str::kebab($plural);
        $routeName   = Str::snake($plural, '-');
        $vuePage     = Str::camel($plural) . '/Index';
        $serviceVar  = Str::camel($name) . 'Service';

        // Permission strings as plain literals (attributes require constant expressions)
        $permView   = "{$name}.view";
        $permCreate = "{$name}.create";
        $permEdit   = "{$name}.edit";
        $permDelete = "{$name}.delete";

        $content = "<?php

namespace App\\Http\\Controllers\\Web;

use App\\Http\\Controllers\\Controller;
use Core\\Application\\{$plural}\\Contracts\\{$name}DTO;
use Core\\Application\\{$plural}\\I{$name}AppService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Validation\\ValidationException;
use Incoder\\DDD\\Support\\Attributes\\RouteAttribute;
use Inertia\\Inertia;
use Inertia\\Response;

class {$className} extends Controller
{
    public function __construct(
        private readonly I{$name}AppService \${$serviceVar},
    ) {}

    #[RouteAttribute('GET', '{$routePrefix}', '{$routeName}.index', ['auth', 'permission:{$permView}'])]
    public function index(): Response
    {
        \$items = \$this->{$serviceVar}->getAll();

        return Inertia::render('{$vuePage}', [
            'items' => \$items,
        ]);
    }

    #[RouteAttribute('POST', '{$routePrefix}', '{$routeName}.store', ['auth', 'permission:{$permCreate}'])]
    public function store(Request \$request): JsonResponse
    {
        try {
            \$validated = \$request->validate({$name}DTO::rules());
            \$data = {$name}DTO::from(\$validated);
            \$result = \$this->{$serviceVar}->create(\$data->toArray());

            return response()->json([
                'message' => '{$name} created successfully.',
                'id'      => \$result->entity->id,
            ]);
        } catch (ValidationException \$e) {
            return response()->json(['errors' => \$e->errors()], 422);
        }
    }

    #[RouteAttribute('PUT', '{$routePrefix}/{id}/update', '{$routeName}.update', ['auth', 'permission:{$permEdit}'])]
    public function update(string \$id, Request \$request): JsonResponse
    {
        try {
            \$validated = \$request->validate({$name}DTO::rules());
            \$data = {$name}DTO::from(\$validated);
            \$result = \$this->{$serviceVar}->update(\$id, \$data->toArray());

            return response()->json([
                'message' => '{$name} updated successfully.',
                'data'    => \$result->dto,
            ]);
        } catch (ValidationException \$e) {
            return response()->json(['errors' => \$e->errors()], 422);
        }
    }

    #[RouteAttribute('DELETE', '{$routePrefix}/{id}/archive', '{$routeName}.archive', ['auth', 'permission:{$permDelete}'])]
    public function archive(string \$id): JsonResponse
    {
        \$this->{$serviceVar}->delete(\$id);

        return response()->json([
            'message' => '{$name} archived successfully.',
        ]);
    }
}
";
        $filesystem->put($filePath, $content);
        $this->info("Created: app/Http/Controllers/Web/{$className}.php");
    }

    // ────────────────────────────────────────────────────────────────────────
    // Vue pages
    // ────────────────────────────────────────────────────────────────────────

    private function createVuePages(Filesystem $filesystem, string $name, string $plural): void
    {
        $pageFolder = resource_path('js/pages/' . Str::camel($plural));
        $filesystem->ensureDirectoryExists($pageFolder);

        $this->createVueIndexPage($filesystem, $pageFolder, $name, $plural);
        $this->createVueFormComponent($filesystem, $pageFolder, $name, $plural);
    }

    private function createVueIndexPage(Filesystem $filesystem, string $pageFolder, string $name, string $plural): void
    {
        $filePath = "{$pageFolder}/Index.vue";
        if ($filesystem->exists($filePath)) {
            $this->warn("Skipped (exists): resources/js/pages/" . Str::camel($plural) . "/Index.vue");
            return;
        }

        $proxyClass    = "{$name}AppServiceProxy";
        $dtoType       = "{$name}DTO";
        $listDtoType   = "{$name}ListDTO";
        $title         = Str::headline($plural);
        $entityLabel   = Str::headline($name);
        $formComponent = "{$name}Form";
        $permView      = "{$name}.view";
        $permCreate    = "{$name}.create";
        $permEdit      = "{$name}.edit";
        $permDelete    = "{$name}.delete";

        $content = <<<VUE
<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import {$formComponent} from './{$formComponent}.vue';
import type { {$dtoType}, {$listDtoType} } from '@/proxiees/models';
import { {$proxyClass} } from '@/proxiees/services/{$name}AppService';
import { can } from '@/composables/useAuth';

const canView   = can('{$permView}');
const canCreate = can('{$permCreate}');
const canEdit   = can('{$permEdit}');
const canDelete = can('{$permDelete}');

const props = defineProps<{
    items: {$listDtoType}[];
}>();

const breadcrumbs = [
    { title: 'Dashboard', href: '/' },
    { title: '{$title}', href: '#' },
];

const proxy = new {$proxyClass}();

const open          = ref(false);
const selected      = ref<{$listDtoType} | null>(null);
const isSubmitting  = ref(false);
const search        = ref('');

const filteredItems = computed(() => {
    if (!search.value) return props.items;
    const q = search.value.toLowerCase();
    return props.items.filter(item =>
        Object.values(item as object).some(v => String(v).toLowerCase().includes(q)),
    );
});

function openCreate(): void {
    selected.value = null;
    open.value = true;
}

function openEdit(item: {$listDtoType}): void {
    selected.value = item;
    open.value = true;
}

async function handleSubmit(data: Partial<{$dtoType}>): Promise<void> {
    isSubmitting.value = true;
    try {
        if (selected.value?.id) {
            await proxy.update(String(selected.value.id), data as {$dtoType});
            toast.success('{$entityLabel} updated successfully.');
        } else {
            await proxy.create(data as {$dtoType});
            toast.success('{$entityLabel} created successfully.');
        }
        open.value = false;
        router.reload();
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        toast.error(err?.response?.data?.message ?? 'An error occurred.');
    } finally {
        isSubmitting.value = false;
    }
}

async function handleDelete(item: {$listDtoType}): Promise<void> {
    if (!confirm('Archive this {$entityLabel}?')) return;
    try {
        await proxy.delete(String(item.id));
        toast.success('{$entityLabel} archived successfully.');
        router.reload();
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        toast.error(err?.response?.data?.message ?? 'Failed to archive.');
    }
}

function handleCancel(): void {
    open.value = false;
}
</script>

<template>
    <Head title="{$title}" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4 overflow-x-auto">
            <div
                class="relative min-h-[100vh] flex-1 rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border p-5 bg-sidebar"
            >
                <div class="w-full">
                    <!-- Header -->
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h2 class="text-2xl font-bold tracking-tight text-primary">
                                Manage {$title}
                            </h2>
                            <p class="text-sm text-muted-foreground mt-1">
                                Create and manage {$title} records.
                            </p>
                        </div>

                        <Dialog v-model:open="open">
                            <DialogTrigger v-if="canCreate" as-child>
                                <Button
                                    class="shadow-sm hover:shadow-md transition flex items-center gap-2"
                                    @click="openCreate"
                                >
                                    <Plus class="h-4 w-4" />
                                    Create {$entityLabel}
                                </Button>
                            </DialogTrigger>
                            <DialogContent class="max-w-2xl">
                                <{$formComponent}
                                    :mode="selected ? 'edit' : 'create'"
                                    :initial-data="selected ?? undefined"
                                    :is-submitting="isSubmitting"
                                    @submit="handleSubmit"
                                    @cancel="handleCancel"
                                />
                            </DialogContent>
                        </Dialog>
                    </div>

                    <!-- Search -->
                    <div class="flex gap-2 items-center py-4">
                        <div class="relative flex-1 max-w-sm">
                            <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                v-model="search"
                                class="pl-9"
                                placeholder="Search {$title}..."
                            />
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="rounded-md border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <!-- TODO: Add column headers for your entity's fields -->
                                    <TableHead class="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <template v-if="filteredItems.length">
                                    <TableRow
                                        v-for="item in filteredItems"
                                        :key="item.id"
                                    >
                                        <TableCell>{{ item.id }}</TableCell>
                                        <!-- TODO: Add cells matching your entity's fields -->
                                        <TableCell class="text-right">
                                            <div class="flex items-center justify-end gap-2">
                                                <Button
                                                    v-if="canEdit"
                                                    variant="ghost"
                                                    size="icon"
                                                    title="Edit"
                                                    @click="openEdit(item)"
                                                >
                                                    <Pencil class="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    v-if="canDelete"
                                                    variant="ghost"
                                                    size="icon"
                                                    class="text-destructive hover:text-destructive"
                                                    title="Archive"
                                                    @click="handleDelete(item)"
                                                >
                                                    <Trash2 class="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                </template>

                                <TableRow v-else>
                                    <TableCell colspan="3" class="h-24 text-center">
                                        <div
                                            class="flex flex-col items-center justify-center py-6 text-muted-foreground"
                                        >
                                            <Search class="h-10 w-10 mb-2 opacity-30" />
                                            <p class="text-sm">No {$title} found.</p>
                                            <p class="text-xs mt-1">
                                                Try adjusting your search or add a new record.
                                            </p>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
VUE;

        $filesystem->put($filePath, $content);
        $this->info("Created: resources/js/pages/" . Str::camel($plural) . "/Index.vue");
    }

    private function createVueFormComponent(Filesystem $filesystem, string $pageFolder, string $name, string $plural): void
    {
        $filePath = "{$pageFolder}/{$name}Form.vue";
        if ($filesystem->exists($filePath)) {
            $this->warn("Skipped (exists): resources/js/pages/" . Str::camel($plural) . "/{$name}Form.vue");
            return;
        }

        $dtoType     = "{$name}DTO";
        $entityLabel = Str::headline($name);

        $content = <<<VUE
<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { {$dtoType} } from '@/proxiees/models';

const props = defineProps<{
    mode: 'create' | 'edit';
    initialData?: Partial<{$dtoType}>;
    isSubmitting?: boolean;
}>();

const emit = defineEmits<{
    (e: 'submit', data: Partial<{$dtoType}>): void;
    (e: 'cancel'): void;
}>();

const form = reactive<Partial<{$dtoType}>>({
    // TODO: Add form fields matching your DTO properties
    // Example: name: undefined,
});

watch(
    () => props.initialData,
    (val) => {
        if (val) {
            Object.assign(form, val);
        } else {
            (Object.keys(form) as Array<keyof typeof form>).forEach(k => delete form[k]);
        }
    },
    { immediate: true },
);

function onSubmit(e: Event): void {
    e.preventDefault();
    emit('submit', { ...form });
}
</script>

<template>
    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
        <div class="relative flex-1 rounded-xl bg-background">

            <!-- Heading -->
            <div class="mb-6 pb-2 border-b">
                <h2 class="text-2xl font-bold tracking-tight text-primary">
                    {{ mode === 'create' ? 'Create {$entityLabel}' : 'Edit {$entityLabel}' }}
                </h2>
                <p class="text-sm text-muted-foreground mt-1">
                    {{
                        mode === 'create'
                            ? 'Fill out the form below to create a new {$entityLabel}.'
                            : 'Update the fields below to edit this {$entityLabel}.'
                    }}
                </p>
            </div>

            <!-- Form -->
            <form class="mt-2 space-y-6" @submit="onSubmit">

                <!--
                    TODO: Replace this example block with your actual fields.
                    Example field:

                    <div class="w-full space-y-2">
                        <Label for="name">
                            {$entityLabel} Name
                            <span class="text-destructive">*</span>
                        </Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            type="text"
                            placeholder="Enter {$entityLabel} name"
                        />
                    </div>
                -->

                <!-- Actions -->
                <div class="flex gap-3 mt-8 pt-4 border-t">
                    <Button
                        type="submit"
                        :disabled="isSubmitting"
                        class="min-w-32"
                    >
                        <span v-if="isSubmitting" class="flex items-center gap-2">
                            <svg
                                class="animate-spin h-4 w-4 text-white"
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <circle
                                    class="opacity-25"
                                    cx="12" cy="12" r="10"
                                    stroke="currentColor"
                                    stroke-width="4"
                                />
                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                />
                            </svg>
                            Processing...
                        </span>
                        <span v-else>
                            {{ mode === 'create' ? 'Create {$entityLabel}' : 'Update {$entityLabel}' }}
                        </span>
                    </Button>

                    <Button
                        type="button"
                        variant="outline"
                        :disabled="isSubmitting"
                        @click="emit('cancel')"
                    >
                        Cancel
                    </Button>
                </div>

            </form>
        </div>
    </div>
</template>
VUE;

        $filesystem->put($filePath, $content);
        $this->info("Created: resources/js/pages/" . Str::camel($plural) . "/{$name}Form.vue");
    }
}

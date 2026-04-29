# Incoder DDD - Domain-Driven Design Library for Laravel

A comprehensive Domain-Driven Design (DDD) framework for Laravel, inspired by the ABP Framework. This package provides a structured approach to building maintainable, scalable enterprise applications using DDD principles.

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3-blue)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E12.0-red)](https://laravel.com)

---

## Table of Contents

- [Features](#features)
- [Architecture Overview](#architecture-overview)
- [Installation](#installation)
- [Core Concepts](#core-concepts)
- [Usage Guide](#usage-guide)
- [Artisan Commands](#artisan-commands)
- [Advanced Features](#advanced-features)
- [Best Practices](#best-practices)
- [API Reference](#api-reference)
- [Contributing](#contributing)

---

## Features

✅ **Domain-Driven Design Architecture**
- Clear separation of concerns (Domain, Application, Infrastructure)
- Auto-discovery of repositories and application services
- Aggregate roots and entities with built-in best practices

✅ **Auto-Wiring & Dependency Injection**
- Automatic binding of repository interfaces to implementations
- Convention-based service registration
- Zero-config dependency injection

✅ **Attribute-Based Routing**
- `#[RouteAttribute]` for declarative route definitions
- Auto-discovery of routes from controllers
- No need to manually register routes

✅ **Automatic Fillable Detection**
- `#[FillableAttribute]` for property-level mass assignment
- Reflection-based fillable property detection
- Type-safe property declarations

✅ **Built-in Patterns**
- Repository pattern with base implementations
- Application service layer
- DTO (Data Transfer Objects) with Spatie Laravel Data
- Value Objects support

✅ **Activity Logging**
- Integrated Spatie Activity Log
- Automatic audit trails
- Customizable event descriptions

✅ **Code Generation**
- Artisan command to scaffold complete domain entities
- Generates models, repositories, services, DTOs, and migrations
- Supports both integer and UUID primary keys

✅ **Multi-Schema Support**
- Schema-specific migrations
- Schema-aware table naming
- Perfect for multi-tenant or microservice architectures

---

## Architecture Overview

The package enforces a clean DDD architecture with three main layers:

```
core/
├── Domain/                    # Business logic and domain models
│   └── {Entity}/
│       ├── {Entity}.php                  # Domain model (Entity/AggregateRoot)
│       └── I{Entity}Repository.php       # Repository interface
│
├── Application/               # Application services (orchestration)
│   └── {Entity}/
│       ├── I{Entity}AppService.php       # Application service interface
│       ├── {Entity}AppService.php        # Application service implementation
│       └── Contracts/
│           ├── {Entity}DTO.php           # Main DTO
│           ├── {Entity}ListDTO.php       # Collection item DTO
│           └── {Entity}PaginatedDTO.php  # Pagination wrapper
│
└── Infrastructure/            # Data access and external services
    └── Eloquent/Repositories/
        └── {Entity}Repository.php        # Eloquent implementation
```

**Key Principles:**
- **Domain Layer**: Pure business logic, no framework dependencies
- **Application Layer**: Orchestrates domain objects, handles transactions
- **Infrastructure Layer**: Technical implementations (database, external APIs)

---

## Installation

### 1. Add to Your Laravel Project

Add the following to your `composer.json` file:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../packages/incoder-ddd",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

### 2. Install the Package

```bash
composer require incoder/ddd:@dev
```

The package will automatically register via Laravel's package auto-discovery.

### 3. Publish Configuration (Optional)

```bash
php artisan vendor:publish --provider="Incoder\DDD\Support\IncoderDDDServiceProvider"
```

---

## Core Concepts

### 1. Entities

Base class for domain entities. Supports both integer and UUID primary keys.

```php
use Incoder\DDD\Domain\Entities\Entity;

class Department extends Entity
{
    protected $table = 'admin.departments';
    
    public $incrementing = true;
    protected $keyType = 'int';
    
    #[FillableAttribute]
    protected string $name;
    
    #[FillableAttribute]
    protected ?string $description;
}
```

**Features:**
- Soft deletes enabled by default
- Activity logging integration
- Automatic fillable detection via attributes
- Timestamps (created_at, updated_at, deleted_at)

### 2. Aggregate Roots

Special entities that act as consistency boundaries.

```php
use Incoder\DDD\Domain\Entities\AggregateRoot;

class User extends AggregateRoot
{
    protected $table = 'admin.users';
    
    public $incrementing = false;
    protected $keyType = 'string';
    
    #[FillableAttribute]
    protected string $email;
    
    #[FillableAttribute]
    protected string $first_name;
}
```

### 3. Authenticable Aggregate Roots

For user authentication entities.

```php
use Incoder\DDD\Domain\Entities\AuthenticableAggregateRoot;

class User extends AuthenticableAggregateRoot
{
    // Implements Laravel's Authenticatable interface
    // Includes password hashing, remember token, etc.
}
```

### 4. Repositories

Interface-based data access layer.

```php
// Domain/Users/IUserRepository.php
interface IUserRepository extends IRepository
{
    public function findByEmail(string $email): ?User;
    public function listUsersWithRoles(array $data): array;
}

// Infrastructure/Eloquent/Repositories/UserRepository.php
class UserRepository extends EloquentRepositoryBase implements IUserRepository
{
    public function findByEmail(string $email): ?User
    {
        return $this->model->where('email', $email)->first();
    }
}
```

**Auto-binding:** Interface `I{Entity}Repository` is automatically bound to `{Entity}Repository`.

### 5. Application Services

Orchestrate domain logic and handle use cases.

```php
class UserAppService extends AppServiceBase implements IUserAppService
{
    public function __construct(
        IUserRepository $repository,
        ?LoggerInterface $logger = null,
        Guard $auth,
    ) {
        parent::__construct(
            $repository,
            User::class,
            UserDTO::class,
            UserListDTO::class,
            UserPaginatedDTO::class,
            $logger,
            $auth
        );
    }
    
    public function createUser(UserDTO $data): ResultData
    {
        // Business logic here
    }
}
```

**Auto-binding:** Interface `I{Entity}AppService` is automatically bound to `{Entity}AppService`.

### 6. Data Transfer Objects (DTOs)

Type-safe data containers using Spatie Laravel Data.

```php
use Incoder\DDD\Application\DTOs\DTOBase;

class UserDTO extends DTOBase
{
    public function __construct(
        #[Required, StringType]
        public string $email,
        
        #[Required, StringType]
        public string $first_name,
        
        #[Nullable, StringType]
        public ?string $middle_name = null,
    ) {}
    
    public static function rules(): array
    {
        return [
            'email' => ['required', 'email', 'unique:admin.users'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

### 7. Route Attributes

Declarative routing using PHP attributes.

```php
use Incoder\DDD\Support\Attributes\RouteAttribute;

class UserController extends Controller
{
    #[RouteAttribute(
        methods: ['GET'],
        uri: '/users',
        name: 'users.index',
        middleware: ['auth']
    )]
    public function index() { }
    
    #[RouteAttribute(
        methods: ['POST'],
        uri: '/users',
        name: 'users.store',
        middleware: ['auth', 'can:create-users']
    )]
    public function store(Request $request) { }
}
```

Routes are automatically discovered and registered. No need to add to `routes/web.php`.

---

## Usage Guide

### Creating a Complete Domain Entity

Use the artisan command to scaffold everything you need:

```bash
php artisan make:domain-model User --type=aggregate --format=string --incrementing=false --schema=admin
```

**What gets generated:**
1. ✅ Domain Model: `core/Domain/Users/User.php`
2. ✅ Repository Interface: `core/Domain/Users/IUserRepository.php`
3. ✅ Repository Implementation: `core/Infrastructure/Eloquent/Repositories/UserRepository.php`
4. ✅ App Service Interface: `core/Application/Users/IUserAppService.php`
5. ✅ App Service Implementation: `core/Application/Users/UserAppService.php`
6. ✅ Main DTO: `core/Application/Users/Contracts/UserDTO.php`
7. ✅ List DTO: `core/Application/Users/Contracts/UserListDTO.php`
8. ✅ Paginated DTO: `core/Application/Users/Contracts/UserPaginatedDTO.php`
9. ✅ Migration: `database/migrations/{timestamp}_create_users_table.php`

**After generation:**
```bash
composer dump-autoload
php artisan migrate
```

### Creating Model Only

```bash
php artisan make:domain-model Employee --type=entity --format=int --incrementing=true --schema=admin --only-model
```

### Controller Usage

```php
use App\Core\Application\Users\IUserAppService;

class UserController extends Controller
{
    public function __construct(
        private readonly IUserAppService $userService
    ) {}
    
    #[RouteAttribute(methods: ['POST'], uri: '/users', name: 'users.store')]
    public function store(Request $request)
    {
        $validated = $request->validate(UserDTO::rules());
        $dto = UserDTO::from($validated);
        
        $result = $this->userService->create($dto);
        
        return redirect()->route('users.index')
            ->with('success', 'User created successfully');
    }
}
```

### Custom Repository Methods

```php
// Interface
interface IDepartmentRepository extends IRepository
{
    public function findActiveByName(string $name): ?Department;
    public function listWithEmployeeCount(): Collection;
}

// Implementation
class DepartmentRepository extends EloquentRepositoryBase implements IDepartmentRepository
{
    public function findActiveByName(string $name): ?Department
    {
        return $this->model
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->first();
    }
    
    public function listWithEmployeeCount(): Collection
    {
        return $this->model
            ->withCount('employees')
            ->orderBy('name')
            ->get();
    }
}
```

---

## Artisan Commands

### make:domain-model

Generate a complete domain entity with all layers.

```bash
php artisan make:domain-model {name} 
    --type={entity|aggregate} 
    --format={int|string} 
    --incrementing={true|false} 
    --schema={schema}
    [--only-model]
```

**Options:**
- `--type`: Entity type (`entity` or `aggregate`)
- `--format`: Primary key format (`int` or `string`)
- `--incrementing`: Auto-incrementing key (`true` or `false`)
- `--schema`: Database schema name (e.g., `admin`, `applicant`)
- `--only-model`: Generate only the domain model file

**Examples:**

```bash
# Auto-incrementing integer ID entity
php artisan make:domain-model Department \
    --type=entity \
    --format=int \
    --incrementing=true \
    --schema=admin

# UUID aggregate root
php artisan make:domain-model Order \
    --type=aggregate \
    --format=string \
    --incrementing=false \
    --schema=applicant

# Model only
php artisan make:domain-model Product \
    --type=entity \
    --format=int \
    --incrementing=true \
    --schema=admin \
    --only-model
```

### migrate:fresh-schema

Drop and recreate tables in a specific schema.

```bash
php artisan migrate:fresh-schema {schema} [--seed] [--seeder=DatabaseSeeder]
```

**Examples:**

```bash
# Reset admin schema
php artisan migrate:fresh-schema admin

# Reset and seed
php artisan migrate:fresh-schema admin --seed

# Reset with custom seeder
php artisan migrate:fresh-schema applicant --seed --seeder=ApplicantSeeder
```

---

## Advanced Features

### 1. Activity Logging

All entities automatically log activities using Spatie Activity Log.

**Configure logging in your domain model:**

```php
class User extends AuthenticableAggregateRoot
{
    protected static $logAttributes = ['first_name', 'last_name', 'email'];
    protected static $logName = 'user';
    protected static $recordEvents = ['created', 'updated', 'deleted'];
    protected static $logOnlyDirty = true;
    
    public function getDescriptionForEvent(string $eventName): string
    {
        $fullName = trim($this->first_name . ' ' . $this->last_name);
        
        return match ($eventName) {
            'created' => "User '{$fullName}' was created",
            'updated' => "User '{$fullName}' was updated",
            'deleted' => "User '{$fullName}' was deleted",
            default => "User '{$fullName}' {$eventName}",
        };
    }
}
```

**Retrieve activities:**

```php
$activities = Activity::forSubject($user)->get();
```

### 2. Soft Deletes

All entities have soft deletes enabled by default.

```php
// Soft delete
$user->delete();

// Restore
$user->restore();

// Force delete
$user->forceDelete();

// Query with trashed
$users = User::withTrashed()->get();
$users = User::onlyTrashed()->get();
```

### 3. UUID Support

For string-based primary keys:

```php
class Order extends AggregateRoot
{
    protected $keyType = 'string';
    public $incrementing = false;
    
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Uuid::uuid4();
            }
        });
    }
}
```

### 4. Schema-Aware Models

Always specify the schema in your table name:

```php
class Employee extends Entity
{
    protected $table = 'admin.employees';  // Schema included
}
```

### 5. Custom Validation Rules in DTOs

**Approach 1: Static rules() method**

```php
class CreateUserDTO extends DTOBase
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}
    
    public static function rules(): array
    {
        return [
            'email' => ['required', 'email', 'unique:admin.users,email'],
            'password' => ['required', 'min:8', 'confirmed'],
        ];
    }
}
```

**Approach 2: Spatie Data Attributes**

```php
use Spatie\LaravelData\Attributes\Validation\{Required, Email, Min};

class CreateUserDTO extends DTOBase
{
    public function __construct(
        #[Required, Email]
        public string $email,
        
        #[Required, Min(8)]
        public string $password,
    ) {}
}
```

### 6. Value Objects

Create immutable value objects for domain concepts:

```php
use Incoder\DDD\Domain\ValueObjects\ValueObject;

class Money extends ValueObject
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency
    ) {}
    
    public function add(Money $other): Money
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Currency mismatch');
        }
        
        return new Money(
            $this->amount + $other->amount,
            $this->currency
        );
    }
}
```

### 7. Domain Events

Dispatch events from your domain models:

```php
class Order extends AggregateRoot
{
    public function place(): void
    {
        $this->status = 'placed';
        $this->save();
        
        event(new OrderPlaced($this));
    }
}
```

---

## Best Practices

### 1. Naming Conventions

**Folder Names:** Use **plural StudlyCase** (e.g., `Users/`, `Employees/`)

**Entity Names:** Use **singular** (e.g., `User.php`, `Employee.php`)

**Repository Interfaces:** Prefix with `I` (e.g., `IUserRepository.php`)

**Application Services:** Suffix with `AppService` (e.g., `UserAppService.php`)

**DTOs:** Suffix with `DTO` (e.g., `UserDTO.php`)

### 2. Property Declaration

Use `#[FillableAttribute]` instead of `$fillable` array:

```php
// ✅ Good
#[FillableAttribute]
protected string $first_name;

#[FillableAttribute]
protected ?string $middle_name;

// ❌ Avoid
protected $fillable = ['first_name', 'middle_name'];
```

### 3. Controller Design

- **Inject Application Services**, not repositories
- Use **DTOs** for data validation and transfer
- Keep controllers thin (orchestration only)

```php
// ✅ Good
public function __construct(
    private readonly IUserAppService $userService
) {}

// ❌ Avoid
public function __construct(
    private readonly IUserRepository $userRepository
) {}
```

### 4. Repository Pattern

- Define **custom methods** in repository interfaces
- Keep repositories **focused** on data access
- Use **Eloquent features** (eager loading, scopes) in implementations

### 5. Application Services

- Handle **business workflows**
- Coordinate **multiple repositories**
- Wrap operations in **database transactions**
- Return **ResultData** objects for consistent responses

### 6. Schema Management

- Always include **schema in table names**
- Use **schema-specific migrations**
- Never run `migrate:fresh` on production schemas

### 7. Testing

- Test **domain logic** in isolation
- Mock **repositories** in application service tests
- Use **factories** for test data

---

## API Reference

### Base Classes

#### Entity
```php
abstract class Entity extends Model
{
    use LogsActivity, SoftDeletes;
    
    public $incrementing = true;
    public $timestamps = true;
    protected $keyType = 'int';
}
```

#### AggregateRoot
```php
abstract class AggregateRoot extends Entity
{
    // Same as Entity, but conceptually different
    // Use for entities that are consistency boundaries
}
```

#### AuthenticableAggregateRoot
```php
abstract class AuthenticableAggregateRoot extends AggregateRoot implements Authenticatable
{
    // Includes authentication functionality
    // password hashing, remember tokens, etc.
}
```

#### EloquentRepositoryBase
```php
abstract class EloquentRepositoryBase implements IRepository
{
    public function find(string $id): ?Entity;
    public function findOrFail(string $id): Entity;
    public function create(array $data): Entity;
    public function update(string $id, array $data): Entity;
    public function delete(string $id): bool;
    public function list(array $filters = []): Collection;
    public function paginate(int $perPage = 15): LengthAwarePaginator;
}
```

#### AppServiceBase
```php
abstract class AppServiceBase implements IAppService
{
    public function create(DTOBase $data): ResultData;
    public function update(string $id, DTOBase $data): ResultData;
    public function delete(string $id): ResultData;
    public function find(string $id): ?Data;
    public function list(array $filters = []): DataCollection;
    public function paginate(int $perPage = 15): PaginatedDTOBase;
}
```

### Attributes

#### RouteAttribute
```php
#[RouteAttribute(
    methods: array|string,      // HTTP methods
    uri: string,                 // Route URI
    name: ?string,              // Route name
    middleware: array|string    // Middleware
)]
```

#### FillableAttribute
```php
#[FillableAttribute]
protected string $property;
```

### DTOs

#### DTOBase
```php
abstract class DTOBase extends Data
{
    public static function rules(): array;
    public static function messages(): array;
}
```

#### PaginatedDTOBase
```php
class PaginatedDTOBase extends DTOBase
{
    public DataCollection $data;
    public int $total;
    public int $perPage;
    public int $currentPage;
    public int $lastPage;
}
```

---

## Contributing

Contributions are welcome! Please follow these guidelines:

1. **Fork the repository**
2. **Create a feature branch** (`git checkout -b feature/amazing-feature`)
3. **Commit your changes** (`git commit -m 'Add amazing feature'`)
4. **Push to the branch** (`git push origin feature/amazing-feature`)
5. **Open a Pull Request**

### Development Setup

```bash
# Clone the repository
git clone <repository-url>
cd incoder-ddd

# Install dependencies
composer install

# Run tests
./vendor/bin/pest
```

---

## Authors

- **Incoder** - [rbsgaridan@usm.edu.ph](mailto:rbsgaridan@usm.edu.ph)
- **Kian Naquines** - [kjnaquines@usm.edu.ph](mailto:kjnaquines@usm.edu.ph)

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

## Acknowledgments

- Inspired by [ABP Framework](https://abp.io/)
- Built on [Laravel Framework](https://laravel.com/)
- Uses [Spatie Laravel Data](https://spatie.be/docs/laravel-data)
- Activity logging via [Spatie Activity Log](https://spatie.be/docs/laravel-activitylog)

---

## Support

For issues, questions, or contributions:
- **Email**: rbsgaridan@usm.edu.ph
- **GitHub Issues**: [Create an issue](../../issues)

---

**Made with ❤️ at the University of Southern Mindanao**



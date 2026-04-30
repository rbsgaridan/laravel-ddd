<?php

namespace Incoder\DDD\Domain\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Incoder\DDD\Support\Attributes\FillableAttribute;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use ReflectionProperty;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Class Entity
 *
 * Base class for domain entities that use UUIDs and follow Domain-Driven Design principles.
 * Inherits from Laravel's Eloquent Model.
 *
 * Automatically:
 * - Uses UUID as primary key.
 * - Disables auto-incrementing.
 * - Enables timestamps.
 * - Detects public properties as `$fillable` (excluding accessors).
 *
 *
 * @property string $id UUID primary key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
abstract class Entity extends Model
{
    use LogsActivity;
    use SoftDeletes;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Summary of currentUser
     */
    protected $currentUser;

    /**
     * Fillable attributes dynamically determined from public properties.
     *
     * @var array
     */
    protected $fillable = [];

    protected $primaryKey = 'id';

    /**
     * Entity constructor.
     *
     * Automatically populates $fillable with all public non-accessor properties.
     */
    public function __construct(array $attributes = [])
    {
        $this->fillable = $this->detectFillableProperties();

        // Set default values for fillable properties that have defaults
        foreach ($this->fillable as $property) {
            if ($this->isPublicProperty($property) && ! array_key_exists($property, $attributes)) {
                $reflectionProperty = $this->reflection()->getProperty($property);
                if ($reflectionProperty->hasDefaultValue()) {
                    $attributes[$property] = $reflectionProperty->getDefaultValue();
                }
            }
        }

        // Set property values for fillable public properties
        foreach ($this->fillable as $property) {
            if ($this->isPublicProperty($property) && array_key_exists($property, $attributes)) {
                $this->$property = $attributes[$property];
            }
        }

        parent::__construct($attributes);
    }

    /**
     * Boot the model and assign a UUID when creating if not set.
     */
    protected static function booted(): void
    {
        static::creating(function ($model) {
            /**
             * Check the key type string and set the incrementing to false
             *
             * @return void
             */
            if ($model->getKeyType() === 'string') {
                $model->incrementing = false;
                $model->setAttribute($model->getKeyName(), strtolower(Uuid::uuid4()->toString()));
            }
        });

        // Global scope to filter by campus_id using CampusFilter permissions.
        // Delegates to UserVisibilityScope — the single source of truth for
        // permission-based campus filtering across all domain entities.
        // static::addGlobalScope('campus', function (Builder $builder) {
        //     $model = $builder->getModel();

        //     // Only apply to models that actually have a campus_id column.
        //     if (
        //         !in_array('campus_id', $model->getFillable()) &&
        //         !$model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'campus_id')
        //     ) {
        //         return;
        //     }

        //     (new UserVisibilityScope())->apply($builder, $model);
        // });

        // static::addGlobalScope('latest', function (Builder $builder) {
        //     $builder->orderBy('created_at', 'desc');
        // });
    }

    /**
     * Detect all protected properties (excluding accessors) to set as fillable fields from the model.
     */
    protected function detectFillableProperties(): array
    {
        $class = $this->reflection();
        $properties = [];

        // Check both protected and public properties
        $propertyTypes = [ReflectionProperty::IS_PROTECTED, ReflectionProperty::IS_PUBLIC];

        foreach ($propertyTypes as $type) {
            foreach ($class->getProperties($type) as $property) {
                if ($property->getAttributes(FillableAttribute::class)) {
                    $name = $property->getName();

                    // Skip accessor-style computed properties
                    if (method_exists($this, 'get'.Str::studly($name).'Attribute')) {
                        continue;
                    }

                    $properties[] = $name;
                }
            }
        }

        return $properties;
    }

    /**
     * Get the data type of the primary key.
     */
    public function getKeyType(): string
    {
        return $this->keyType;
    }

    public function reflection(): ReflectionClass
    {
        return new ReflectionClass($this);
    }

    /**
     * Check if the class has the given property.
     */
    public function checkHasProperty(string $property): bool
    {
        return $this->reflection()->hasProperty($property);
    }

    /**
     * Check if a property is private.
     */
    public function isPrivateProperty(string $property): bool
    {
        return $this->checkHasProperty($property)
            && $this->reflection()->getProperty($property)->isPrivate();
    }

    /**
     * Check if a property is protected.
     */
    public function isProtectedProperty(string $property): bool
    {
        return $this->checkHasProperty($property)
            && $this->reflection()->getProperty($property)->isProtected();
    }

    /**
     * Check if a property is public.
     */
    public function isPublicProperty(string $property): bool
    {
        return $this->checkHasProperty($property)
            && $this->reflection()->getProperty($property)->isPublic();
    }

    protected $appends = [
        'created_at_formatted',
        'updated_at_formatted',
        'deleted_at_formatted',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Accessor for created_at formatted.
     */
    public function getCreatedAtFormattedAttribute(): ?string
    {
        return $this->formatDateTime($this->created_at);
    }

    /**
     * Accessor for updated_at formatted.
     */
    public function getUpdatedAtFormattedAttribute(): ?string
    {
        return $this->formatDateTime($this->updated_at);
    }

    /**
     * Accessor for deleted_at formatted.
     */
    public function getDeletedAtFormattedAttribute(): ?string
    {
        return $this->formatDateTime($this->deleted_at);
    }

    /**
     * Centralized datetime formatter.
     */
    protected function formatDateTime(?Carbon $date): ?string
    {
        return $date?->timezone(config('app.timezone'))
            ->format('M d, Y \a\t h:i:s A');
    }

    public function toArray(): array
    {
        $array = parent::toArray();

        // Include fillable properties that are not in the database
        foreach ($this->fillable as $property) {
            if (! array_key_exists($property, $array) && $this->isPublicProperty($property)) {
                $array[$property] = $this->$property;
            }
        }

        return $array;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName(class_basename(static::class))
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity, string $eventName)
    {
        $user = $this->currentUser ?? auth('sanctum')->user() ?? auth()->user();

        if ($user) {
            $activity->causer_id = $user->id;
            $activity->causer_type = get_class($user);
            $activity->properties = $activity->properties->merge([
                'user_id' => $user->id,
            ]);
        }
    }

    public function setCurrentUser($user): self
    {
        $this->currentUser = $user;

        return $this;
    }

    public function __toString(): string
    {
        return json_encode($this->toArray());
    }

    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    public function fromArray(array $data): self
    {
        foreach ($data as $key => $value) {
            if ($this->isProtectedProperty($key)) {
                $this->$key = $value;
            }
        }

        return $this;
    }

    public function getClassName(): string
    {
        return class_basename(static::class);
    }

    public function getFQCN(): string
    {
        return static::class;
    }
}

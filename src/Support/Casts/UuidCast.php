<?php
namespace Incoder\DDD\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Incoder\DDD\Domain\ValueObjects\Uuid;

/**
 * Casts a UUID value object to and from a string in the database.
 * This class implements the CastsAttributes interface for Eloquent models.
 * sample usage:
 * protected $casts = ['customer_id' => \Incoder\DDD\Support\Casts\UuidCast::class];
 */
class UuidCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?Uuid
    {
        return $value ? new Uuid($value) : null;
    }

    public function set($model, string $key, $value, array $attributes): array
    {
        if ($value instanceof Uuid) {
            return [$key => (string) $value];
        }

        return [$key => $value];
    }
}

<?php

namespace Incoder\DDD\Domain\Entities;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;

/**
 * Class AuthenticableAggregateRoot
 *
 * @template T of string
 *
 * @extends AggregateRoot<T>
 *
 * This class serves as a base for aggregate roots that require authentication.
 * Base aggregate root for domain entities that are authenticatable.
 * Inherits from AggregateRoot and implements Laravel's Authenticatable interface.
 *
 * @property string|null $remember_token
 * @property string $password
 */
abstract class AuthenticableAggregateRoot extends AggregateRoot implements Authenticatable
{
    /**
     * User's model table name.
     */
    // protected $table = 'users';

    /**
     * The remember token for "remember me" functionality.
     */
    protected ?string $remember_token = null;

    /**
     * The user's password.
     */
    protected ?string $password = null;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Whether to automatically hash passwords when setting them.
     */
    protected bool $autoHashPasswords = true;

    /**
     * Additional properties to exclude from fillable detection
     * (in addition to parent exclusions).
     *
     * @var array<string>
     */
    protected $excludeFromFillable = [
        'remember_token',
    ];

    /**
     * Boot the authenticatable entity.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::saving(function ($model) {
            if ($model->autoHashPasswords && $model->isDirty('password') && $model->password) {
                if (! Hash::needsRehash($model->password)) {
                    return;
                }
                $model->password = Hash::make($model->password);
            }
        });
    }

    /**
     * Get the name of the unique identifier for the user.
     */
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    /**
     * Get the unique identifier for the user.
     *
     * @return mixed
     */
    public function getAuthIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Get the name of the password field.
     */
    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    /**
     * Get the password for the user.
     */
    public function getAuthPassword(): ?string
    {
        return $this->getAttribute($this->getAuthPasswordName());
    }

    /**
     * Get the "remember me" token value.
     */
    public function getRememberToken(): ?string
    {
        return $this->getAttribute($this->getRememberTokenName());
    }

    /**
     * Set the "remember me" token value.
     *
     * @param  string|null  $value
     */
    public function setRememberToken($value): void
    {
        $this->setAttribute($this->getRememberTokenName(), $value);
    }

    /**
     * Get the name of the "remember me" token.
     */
    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    /**
     * Verify the given password against the user's password.
     */
    public function verifyPassword(string $password): bool
    {
        return Hash::check($password, $this->getAuthPassword());
    }

    /**
     * Set the user's password (will be automatically hashed if auto-hashing is enabled).
     */
    public function setPassword(string $password): void
    {
        $this->setAttribute('password', $password);
    }

    /**
     * Set the user's password without auto-hashing (for already hashed passwords).
     */
    public function setHashedPassword(string $hashedPassword): void
    {
        $originalAutoHash = $this->autoHashPasswords;
        $this->autoHashPasswords = false;
        $this->setAttribute('password', $hashedPassword);
        $this->autoHashPasswords = $originalAutoHash;
    }

    /**
     * Enable or disable automatic password hashing.
     */
    public function setAutoHashPasswords(bool $enabled): void
    {
        $this->autoHashPasswords = $enabled;
    }

    /**
     * Check if auto password hashing is enabled.
     */
    public function isAutoHashPasswordsEnabled(): bool
    {
        return $this->autoHashPasswords;
    }
}

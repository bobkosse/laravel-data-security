<?php

declare(strict_types=1);

namespace BobKosse\DataSecurity\Traits;

use BobKosse\DataSecurity\Attributes\Protect;
use BobKosse\DataSecurity\Builders\PrivacyEloquentBuilder;
use BobKosse\DataSecurity\Exceptions\PrivacyDecryptionException;
use BobKosse\DataSecurity\Helpers\IsEncryptedHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

trait HasPrivacy
{
    /**
     * Retrieves the IsEncryptedHelper instance.
     */
    protected function getIsEncryptedHelper(): IsEncryptedHelper
    {
        return app(IsEncryptedHelper::class);
    }

    /**
     * Indicates whether privacy is revealed for the model.
     */
    protected bool $revealed = false;

    /**
     * Use the privacy-aware Eloquent builder.
     *
     * @param  Builder|mixed  $query  The query builder instance.
     * @return Builder The privacy-aware Eloquent builder instance.
     */
    public function newEloquentBuilder(mixed $query): Builder
    {
        return new PrivacyEloquentBuilder($query, $this->getIsEncryptedHelper());
    }

    /**
     * Checks if privacy is active for the model.
     *
     * @return bool True if privacy is active, false otherwise.
     */
    protected function isPrivacyActive(): bool
    {
        $privacyActive = $this instanceof Model && get_class($this) !== 'User';

        if (! $privacyActive && config('app.debug', false)) {
            Log::alert('Privacy is not active for this model: '.get_class($this));
        }

        return $privacyActive;
    }

    /**
     * Reveals or hides privacy fields for the model.
     *
     * @param  bool  $reveal  True to reveal privacy fields, false to hide them.
     * @return self The current instance for method chaining.
     */
    public function revealPrivacy(bool $reveal = false): self
    {
        $this->revealed = $reveal;

        return $this;
    }

    /**
     * Retrieves the privacy fields for the model.
     *
     * @return array An array of privacy fields.
     */
    public function getPrivacyFields(): array
    {
        $reflection = new \ReflectionClass($this);
        $attributes = $reflection->getAttributes(Protect::class);

        if (empty($attributes)) {
            return [];
        }

        return $attributes[0]->newInstance()->fields;
    }

    /**
     * Retrieves the attribute value for the given key.
     *
     * @param  mixed  $key  The attribute key to retrieve.
     * @return mixed The attribute value or null if not found.
     */
    public function getAttribute(mixed $key): mixed
    {
        if ($this->isPrivacyActive() && in_array($key, $this->getPrivacyFields(), true)) {
            $value = parent::getAttribute($key);

            if ($value === null) {
                return null;
            }

            if (! $this->revealed) {
                return '[ENCRYPTED]';
            }

            try {
                return Crypt::decryptString((string) $value);
            } catch (\Throwable $e) {
                throw PrivacyDecryptionException::forAttribute($key, static::class, $e);
            }
        }

        return parent::getAttribute($key);
    }

    /**
     * Sets the attribute value for the given key.
     *
     * @param  mixed  $key  The attribute key to set.
     * @param  mixed  $value  The attribute value to set.
     * @return mixed The attribute value.
     */
    public function setAttribute(mixed $key, mixed $value): mixed
    {
        if ($this->isPrivacyActive() && in_array($key, $this->getPrivacyFields(), true)) {
            if ($value !== null && ! $this->getIsEncryptedHelper()->isAlreadyEncrypted($value)) {
                $value = Crypt::encryptString((string) $value);
            }
        }

        return parent::setAttribute($key, $value);
    }
}

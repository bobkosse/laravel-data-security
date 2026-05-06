<?php

declare(strict_types=1);

namespace BobKosse\DataSecurity\Helpers;

use BobKosse\DataSecurity\Builders\PrivacyEloquentBuilder;
use Illuminate\Support\Facades\Crypt;

class IsEncryptedHelper
{
    /**
     * Check if the value is already encrypted.
     */
    public function isAlreadyEncrypted(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Encrypt privacy fields in the given values array.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function encryptPrivacyPayload(PrivacyEloquentBuilder $builder, array $values): array
    {
        $model = $builder->getModel();

        foreach ($model->getPrivacyFields() as $field) {
            if (! array_key_exists($field, $values) || $values[$field] === null || $this->isAlreadyEncrypted($values[$field])) {
                continue;
            }

            $values[$field] = Crypt::encryptString((string) $values[$field]);
        }

        return $values;
    }

    /**
     * Encrypt privacy fields in the given values array.
     *
     * @param  array<int, array<string, mixed>>  $values
     * @return array<int, array<string, mixed>>
     */
    public function encryptPrivacyPayloads(PrivacyEloquentBuilder $builder, array $values): array
    {
        return array_map(function (array $row) use ($builder): array {
            return $this->encryptPrivacyPayload($builder, $row);
        }, $values);
    }
}

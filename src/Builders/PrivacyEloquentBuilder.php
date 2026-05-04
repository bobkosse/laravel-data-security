<?php

declare(strict_types=1);

namespace BobKosse\DataSecurity\Builders;

use BobKosse\DataSecurity\Helpers\IsEncryptedHelper;
use Illuminate\Database\Eloquent\Builder;

/**
 * Custom Eloquent builder with privacy encryption support.
 */
class PrivacyEloquentBuilder extends Builder
{
    private IsEncryptedHelper $isEncrypted;

    public function __construct($query, IsEncryptedHelper $isEncrypted)
    {
        $this->isEncrypted = $isEncrypted;
        $this->query = $query;
    }

    /**
     * Encrypts privacy payloads before inserting into the database.
     */
    public function insert(array $values): bool
    {
        return parent::insert($this->isEncrypted->encryptPrivacyPayloads($this, $values));
    }

    /**
     * Inserts or ignores privacy payloads into the database.
     */
    public function insertOrIgnore(array $values): int
    {
        return parent::insertOrIgnore($this->isEncrypted->encryptPrivacyPayloads($this, $values));
    }

    /**
     * Upserts privacy payloads into the database.
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        return parent::upsert($this->isEncrypted->encryptPrivacyPayloads($this, $values), $uniqueBy, $update);
    }

    /**
     * Updates privacy payloads in the database.
     */
    public function update(array $values): int
    {
        return parent::update($this->isEncrypted->encryptPrivacyPayload($this, $values));
    }
}

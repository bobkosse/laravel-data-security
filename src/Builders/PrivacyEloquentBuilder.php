<?php

declare(strict_types=1);

namespace BobKosse\DataSecurity\Builders;

use BobKosse\DataSecurity\Helpers\IsEncryptedHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Custom Eloquent builder with privacy encryption support.
 */
class PrivacyEloquentBuilder extends Builder
{
    /**
     * Creates a new PrivacyEloquentBuilder instance.
     *
     * @param  Builder  $query  The query builder instance.
     * @param  IsEncryptedHelper  $isEncrypted  The privacy encryption helper instance.
     */
    public function __construct(
        QueryBuilder $query,
        protected IsEncryptedHelper $isEncrypted)
    {
        parent::__construct($query);
    }

    /**
     * Encrypts privacy payloads before inserting into the database.
     *
     * @param  array  $values  The values to insert.
     * @return bool True if the insert was successful, false otherwise.
     */
    public function insert(array $values): bool
    {
        return parent::insert($this->isEncrypted->encryptPrivacyPayloads($this, $values));
    }

    /**
     * Inserts or ignores privacy payloads into the database.
     *
     * @param  array  $values  The values to insert.
     * @return int The number of affected rows.
     */
    public function insertOrIgnore(array $values): int
    {
        return parent::insertOrIgnore($this->isEncrypted->encryptPrivacyPayloads($this, $values));
    }

    /**
     * Upserts privacy payloads into the database.
     *
     * @param  array  $values  The values to upsert.
     * @param  string|array  $uniqueBy  The column(s) to uniquely identify the rows.
     * @param  array|string|null  $update  The columns to update.
     * @return int The number of affected rows.
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        return parent::upsert($this->isEncrypted->encryptPrivacyPayloads($this, $values), $uniqueBy, $update);
    }

    /**
     * Updates privacy payloads in the database.
     *
     * @param  array  $values  The values to update.
     * @return int The number of affected rows.
     */
    public function update(array $values): int
    {
        return parent::update($this->isEncrypted->encryptPrivacyPayload($this, $values));
    }
}

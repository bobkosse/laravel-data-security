<?php

namespace Tests\Feature\TmpEncryptModels;

use BobKosse\DataSecurity\Traits\HasPrivacy;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

#[HasPrivacy(fields: [])]
class FailingEncryptModel extends Model
{
    use HasPrivacy;

    protected $table = 'encrypt_failing_models';

    protected $fillable = [
        'email',
    ];

    protected $privacyFields = [];

    public static bool $failOnSave = false;

    protected static function booted(): void
    {
        static::saving(function () {
            if (static::$failOnSave) {
                throw new RuntimeException('Simulated save failure');
            }
        });
    }
}

<?php

namespace Tests\Feature\TmpEncryptModels;

use BobKosse\DataSecurity\Traits\HasPrivacy;
use Illuminate\Database\Eloquent\Model;

#[HasPrivacy(fields: ['full_name'])]
class EncryptPatient extends Model
{
    use HasPrivacy;

    protected $table = 'encrypt_patients';

    protected $fillable = [
        'full_name',
        'ssn',
    ];
}

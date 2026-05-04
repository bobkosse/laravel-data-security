<?php

namespace Tests\Feature\TmpEncryptModels;

use BobKosse\DataSecurity\Attributes\Protect;
use BobKosse\DataSecurity\Traits\HasPrivacy;
use Illuminate\Database\Eloquent\Model;

#[Protect(fields: ['name'])]
class EncryptCustomer extends Model
{
    use HasPrivacy;

    protected $table = 'encrypt_customers';

    protected $fillable = [
        'name',
        'email',
        'phone',
    ];
}

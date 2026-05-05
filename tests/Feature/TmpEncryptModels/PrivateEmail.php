<?php

namespace Tests\Feature\TmpEncryptModels;

use BobKosse\DataSecurity\Traits\HasPrivacy;
use Illuminate\Database\Eloquent\Model;

class PrivateEmail extends Model
{
    use HasPrivacy;

    protected $table = 'private_emails';

    protected $fillable = [
        'email',
    ];
}

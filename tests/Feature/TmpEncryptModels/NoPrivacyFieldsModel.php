<?php

namespace Tests\Feature\TmpEncryptModels;

use Illuminate\Database\Eloquent\Model;

class NoPrivacyFieldsModel extends Model
{
    protected $table = 'encrypt_empty_table';

    protected $fillable = [
        'email',
    ];
}

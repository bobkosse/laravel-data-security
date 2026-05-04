<?php

namespace Tests\Feature\TmpEncryptModels;

use Illuminate\Database\Eloquent\Model;

class EmptyFieldsModel extends Model
{
    protected $table = 'encrypt_empty_table';

    protected $fillable = [];
}

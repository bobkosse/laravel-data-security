<?php

declare(strict_types=1);

namespace Tests\MockModels;

use BobKosse\DataSecurity\Attributes\Protect;
use BobKosse\DataSecurity\Traits\HasPrivacy;
use Illuminate\Database\Eloquent\Model;

#[Protect(fields: ['email', 'phone'])]
class ProtectedModel extends Model
{
    use HasPrivacy;
}

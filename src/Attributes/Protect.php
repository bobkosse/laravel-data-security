<?php

declare(strict_types=1);

namespace BobKosse\DataSecurity\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Protect
{
    /**
     * Create a new Protect attribute instance.
     *
     * @param  array  $fields  The fields to protect.
     * @return void
     */
    public function __construct(
        public array $fields = []
    ) {}
}

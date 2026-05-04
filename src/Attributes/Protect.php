<?php

namespace BobKosse\DataSecurity\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Protect
{
    public function __construct(
        public array $fields = []
    ) {}
}

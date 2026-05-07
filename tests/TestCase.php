<?php

declare(strict_types=1);

namespace Tests;

use BobKosse\DataSecurity\DataSecurityServiceProvider;
use Orchestra\Testbench\TestCase as OTestCase;

abstract class TestCase extends OTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            DataSecurityServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => base_path('tests/testing.sqlite'),
            'prefix' => '',
        ]);
    }
}

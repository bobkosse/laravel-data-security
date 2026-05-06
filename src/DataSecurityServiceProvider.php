<?php

declare(strict_types=1);

namespace BobKosse\DataSecurity;

use BobKosse\DataSecurity\Commands\PrivacyAuditCommand;
use BobKosse\DataSecurity\Commands\PrivacyEncryptFieldCommand;
use Illuminate\Support\ServiceProvider;

class DataSecurityServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->commands([
            PrivacyAuditCommand::class,
        ]);
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PrivacyAuditCommand::class,
                PrivacyEncryptFieldCommand::class,
            ]);
        }

        $this->app->singleton(IsEncryptedHelper::class);
        $this->app->singleton(ModelHandlingHelper::class);

        $this->mergeConfigFrom(__DIR__.'/../config/data-security.php', 'data-security');
    }
}

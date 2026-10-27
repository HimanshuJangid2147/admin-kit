<?php

namespace AdminKit;

use AdminKit\Console\DiffCommand;
use AdminKit\Console\InstallCommand;
use Illuminate\Support\ServiceProvider;

class AdminKitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                DiffCommand::class,
            ]);
        }
    }
}

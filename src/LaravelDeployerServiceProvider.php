<?php

namespace Phantomshift\LaravelDeployer;

use Illuminate\Support\ServiceProvider;
use Phantomshift\LaravelDeployer\Commands\BlueGreenDeploy;
use Phantomshift\LaravelDeployer\Commands\BlueGreenRollback;

class LaravelDeployerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BlueGreenDeploy::class,
                BlueGreenRollback::class,
            ]);
        }
    }

    public function register(): void
    {
        //
    }
}
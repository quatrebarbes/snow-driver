<?php

namespace Quatrebarbes\SnowDriver;

use Illuminate\Support\ServiceProvider;

class ServiceNowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/servicenow.php', 'servicenow');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/servicenow.php' => config_path('servicenow.php'),
            ], 'servicenow-config');
        }
    }
}

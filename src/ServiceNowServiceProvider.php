<?php

namespace Quatrebarbes\SnowDriver;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;

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

        $this->registerConnections();

        Connection::resolverFor('servicenow', function ($connection, $database, $prefix, $config) {
            return new ServiceNowConnection($database, $prefix, $config);
        });
    }

    /**
     * EX-101 : les connexions déclarées dans config/servicenow.php (SNOW_*)
     * alimentent config/database.php, sans écraser une connexion de même nom
     * déjà déclarée explicitement par l'application hôte.
     */
    private function registerConnections(): void
    {
        $config = $this->app->make('config');

        $connections = $config->get('database.connections', []);

        foreach ($config->get('servicenow.connections', []) as $name => $defaults) {
            // Merge clé par clé : une connexion de même nom déjà déclarée par
            // l'application (même partiellement) complète ces valeurs par
            // défaut plutôt que de les remplacer en bloc.
            $connections[$name] = array_merge($defaults, $connections[$name] ?? []);
        }

        $config->set('database.connections', $connections);
    }
}

<?php

namespace Quatrebarbes\SnowDriver;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;
use Quatrebarbes\SnowDriver\Generator\ModelFileGenerator;

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

        $this->generateModels();
    }

    /**
     * EX-202 : génère, à chaque démarrage de l'application hôte, les modèles
     * manquants pour les tables déclarées via servicenow.models.tables.
     * Retour immédiat si ce tableau est vide (EX-201, limite SFD) : aucun
     * appel réseau, aucune connexion établie (cohérent avec la connexion
     * paresseuse d'EX-121).
     */
    private function generateModels(): void
    {
        $config = $this->app->make('config');

        $tables = (array) $config->get('servicenow.models.tables', []);

        if ($tables === []) {
            return;
        }

        $namespace = (string) $config->get('servicenow.models.namespace', 'App\\Models');
        $connectionName = (string) $config->get('servicenow.default', 'servicenow');

        $connection = $this->app->make('db')->connection($connectionName);

        if (! $connection instanceof ServiceNowConnection) {
            Log::warning("snow-driver: la connexion \"{$connectionName}\" configurée pour la génération de modèles n'est pas une connexion ServiceNow ; génération ignorée.");

            return;
        }

        (new ModelFileGenerator($connection))->generate($tables, $namespace);
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

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
        $this->warmSchemaCache();
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
        $tables = (array) $this->app->make('config')->get('servicenow.models.tables', []);

        if ($tables === []) {
            return;
        }

        $namespace = (string) $this->app->make('config')->get('servicenow.models.namespace', 'App\\Models');
        $connection = $this->resolveConfiguredConnection('la génération de modèles');

        if ($connection === null) {
            return;
        }

        (new ModelFileGenerator($connection))->generate($tables, $namespace);
    }

    /**
     * EX-338 : vérifie, au démarrage de l'application hôte, la fraîcheur du
     * cache de schéma (EX-337) des tables configurées et de la liste des
     * tables de l'instance (EX-322), et programme au besoin leur
     * rafraîchissement différé (EX-340) — sans jamais interroger le
     * dictionnaire directement à ce stade (EX-324) : TableSchemaCache::warm()
     * ne lit que les métadonnées déjà en cache.
     *
     * EX-322 ne dépendant pas de servicenow.models.tables (une seule liste
     * sert toute l'instance), la connexion est résolue dès que le cache est
     * actif (ttl > 0), même si aucune table n'est configurée — à la
     * différence du volet par table.
     */
    private function warmSchemaCache(): void
    {
        if ((int) $this->app->make('config')->get('servicenow.cache.ttl', 0) <= 0) {
            return;
        }

        $tables = (array) $this->app->make('config')->get('servicenow.models.tables', []);

        $connection = $this->resolveConfiguredConnection('le cache de schéma');

        $connection?->schemaCache()->warm($tables);
    }

    /**
     * Connexion ServiceNow désignée par servicenow.default, partagée par la
     * génération de modèles et le cache de schéma. `null` si la connexion
     * configurée n'est pas une connexion ServiceNow, journalisé avec le
     * contexte de l'appelant pour distinguer les deux usages.
     */
    private function resolveConfiguredConnection(string $usage): ?ServiceNowConnection
    {
        $config = $this->app->make('config');
        $connectionName = (string) $config->get('servicenow.default', 'servicenow');

        $connection = $this->app->make('db')->connection($connectionName);

        if (! $connection instanceof ServiceNowConnection) {
            Log::warning("snow-driver: la connexion \"{$connectionName}\" configurée pour {$usage} n'est pas une connexion ServiceNow ; ignoré.");

            return null;
        }

        return $connection;
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

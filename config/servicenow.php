<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connexions ServiceNow
    |--------------------------------------------------------------------------
    |
    | Déclaration des connexions au sens Laravel (config/database.php), chacune
    | pointant vers une instance ServiceNow. La connexion par défaut est
    | sélectionnée via SNOW_CONNECTION.
    |
    */

    'default' => env('SNOW_CONNECTION', 'servicenow'),

    'connections' => [

        'servicenow' => [
            'driver' => 'servicenow',
            // Sans objet pour ServiceNow (pas de base SQL) ; requis par
            // Illuminate\Database\Connectors\ConnectionFactory.
            'database' => '',
            'base_url' => env('SNOW_BASE_URL'),
            'timeout' => env('SNOW_TIMEOUT', 30),

            'auth' => [
                'mode' => env('SNOW_AUTH_MODE', 'basic'),
                'username' => env('SNOW_USERNAME'),
                'password' => env('SNOW_PASSWORD'),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | EX-122 : nombre d'enregistrements demandés par appel à l'API Table lors
    | de l'enchaînement automatique de pages (all()/get() sans limite
    | explicite). Sans effet sur un appel avec limite explicite (take/limit,
    | paginate), qui correspond toujours à un seul appel.
    |
    | 10 000 correspond à la limite par défaut de l'API Table ServiceNow
    | (propriété système glide.rest.query.limit.max) : au-delà, ServiceNow
    | tronque silencieusement la réponse sans erreur. À abaisser via
    | SNOW_PAGE_SIZE si l'instance configure une limite plus restrictive.
    |
    */

    'pagination' => [
        'page_size' => env('SNOW_PAGE_SIZE', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Génération automatique de modèles
    |--------------------------------------------------------------------------
    |
    | EX-201 : noms techniques des tables ServiceNow pour lesquelles un modèle
    | Eloquent doit être généré automatiquement dans le code source de
    | l'application hôte, au démarrage, s'il n'existe pas déjà. Tableau vide
    | par défaut : aucune génération, aucun effet (limite SFD).
    |
    | EX-205 : namespace PHP appliqué à l'ensemble des modèles générés,
    | App\Models par défaut. Doit être enraciné sous App\ (limite SFD).
    |
    */

    'models' => [
        'tables' => [],
        'namespace' => env('SNOW_MODELS_NAMESPACE', 'App\\Models'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache applicatif du schéma et du comptage
    |--------------------------------------------------------------------------
    |
    | EX-337 : pour chacune des tables déclarées dans servicenow.models.tables
    | ci-dessus, un cache mémorise le schéma de la table (colonnes, types, clés
    | étrangères) et son nombre d'enregistrements.
    |
    | EX-322 : la liste des tables de l'instance est mise en cache selon ce
    | même mécanisme, sans être limitée aux tables déclarées ci-dessus.
    |
    | EX-323 : durée de validité (en secondes) de ces caches ; une durée nulle
    | les désactive entièrement (aucune mémorisation, chaque lecture interroge
    | l'instance).
    |
    */

    'cache' => [
        'ttl' => env('SNOW_SCHEMA_CACHE_TTL', 3600),
    ],

];

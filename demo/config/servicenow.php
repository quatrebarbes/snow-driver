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
    | Introspection du schéma
    |--------------------------------------------------------------------------
    |
    | EX-321 à EX-323 : durée de validité, en secondes, du cache du schéma lu
    | dans le dictionnaire de l'instance (liste des tables, colonnes, clés
    | étrangères). Le dictionnaire d'une instance ne change qu'à l'occasion
    | d'une modification de modèle de données : une durée de quelques minutes
    | suffit à éviter de le réinterroger à chaque écran, sans imposer de vidage
    | de cache explicite.
    |
    | Une durée nulle désactive le cache applicatif ; les lectures restent
    | alors mémorisées le temps de la requête HTTP en cours.
    |
    */

    'schema' => [
        'cache_ttl' => env('SNOW_SCHEMA_CACHE_TTL', 300),
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

];

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

];

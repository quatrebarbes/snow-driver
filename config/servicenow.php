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
            'base_url' => env('SNOW_BASE_URL'),
            'timeout' => env('SNOW_TIMEOUT', 30),

            'auth' => [
                'mode' => env('SNOW_AUTH_MODE', 'basic'),
                'username' => env('SNOW_USERNAME'),
                'password' => env('SNOW_PASSWORD'),
            ],
        ],

    ],

];

# snow-driver

Plug-in Laravel fournissant un driver de base de données pour accéder aux objets d'une plateforme ServiceNow au travers de modèles Eloquent (API Table ServiceNow).

## Fonctionnalités

- Connexion et authentification Basic Auth vers une instance ServiceNow, avec abstraction `Credentials` pour permettre l'ajout futur d'autres modes (OAuth2 client credentials)
- Client HTTP interne (`TableApiClient`) et hiérarchie d'exceptions dédiées (`ServiceNowConnectionException`, `ServiceNowAuthenticationException`, `ServiceNowApiException`, `ServiceNowMalformedResponseException`)
- Modèle Eloquent de base (`ServiceNowModel`) mappant `sys_id` (clé primaire string) et `sys_created_on`/`sys_updated_on` sur les timestamps natifs Eloquent
- Query builder traduisant `where`, `whereIn`, `whereNull`, `whereBetween`, `orderBy`, `limit`/`offset` en `sysparm_query` et paramètres `sysparm_*` de l'API Table, avec pagination automatique transparente pour `all()`/`get()`
- `ServiceNowUnsupportedQueryException` pour toute clause du query builder sans équivalent ServiceNow (join, groupBy, agrégats, sous-requêtes, etc.)

Écriture (create/update/delete) et relations via champs de référence sont en cours de développement — voir [docs/roadmap.md](docs/roadmap.md).

## Prérequis

- PHP ^8.2
- Laravel 11, 12 ou 13 (`illuminate/database`, `illuminate/http`, `illuminate/support`)

## Installation

```bash
composer require quatrebarbes/snow-driver
```

Le service provider `Quatrebarbes\SnowDriver\ServiceNowServiceProvider` est auto-découvert par Laravel.

Publier la configuration :

```bash
php artisan vendor:publish --tag=servicenow-config
```

## Configuration

La connexion ServiceNow se déclare comme une connexion Laravel classique dans `config/servicenow.php` (ou directement dans `config/database.php`) :

```php
'servicenow' => [
    'driver' => 'servicenow',
    'database' => '',
    'base_url' => env('SNOW_BASE_URL'),
    'timeout' => env('SNOW_TIMEOUT', 30),
    'auth' => [
        'mode' => env('SNOW_AUTH_MODE', 'basic'),
        'username' => env('SNOW_USERNAME'),
        'password' => env('SNOW_PASSWORD'),
    ],
],
```

Variables d'environnement correspondantes :

| Variable | Description | Défaut |
| --- | --- | --- |
| `SNOW_CONNECTION` | Nom de la connexion par défaut | `servicenow` |
| `SNOW_BASE_URL` | URL de base de l'instance ServiceNow | — |
| `SNOW_TIMEOUT` | Timeout HTTP en secondes | `30` |
| `SNOW_AUTH_MODE` | Mode d'authentification | `basic` |
| `SNOW_USERNAME` / `SNOW_PASSWORD` | Identifiants Basic Auth | — |
| `SNOW_PAGE_SIZE` | Taille de page pour la pagination automatique (`all()`/`get()` sans limite explicite) | `10000` |

La connexion est paresseuse : aucune requête n'est effectuée au boot de l'application, seulement à la première interrogation.

## Utilisation

```php
use Quatrebarbes\SnowDriver\Eloquent\ServiceNowModel;

class Incident extends ServiceNowModel
{
    protected $connection = 'servicenow';
    protected $table = 'incident';
}

Incident::where('active', true)
    ->orderBy('sys_created_on', 'desc')
    ->limit(20)
    ->get();
```

## Application de démonstration

Le dossier [demo/](demo/) contient une application Laravel de démonstration (Blade, sans front JS) illustrant l'usage du driver : menu des tables ServiceNow configurées, liste paginée, détail d'un enregistrement, page d'erreur illustrant la hiérarchie d'exceptions.

Lancement via Docker :

```bash
docker compose up --build
```

L'application est alors disponible sur `http://localhost:8000`. Voir [demo/README.md](demo/README.md) pour la configuration détaillée.

## Tests

```bash
composer install
vendor/bin/phpunit
```

Les tests sont organisés en `tests/Unit` (une fonction = un test) et `tests/Feature` (un test par comportement/endpoint), via Orchestra Testbench.

## Documentation

- Spécifications fonctionnelles détaillées : [docs/sfd/](docs/sfd/)
- Plan de développement et avancement : [docs/roadmap.md](docs/roadmap.md)

## Licence

MIT

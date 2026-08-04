# snow-driver

Plug-in Laravel fournissant un driver de base de données pour accéder aux objets d'une plateforme ServiceNow au travers de modèles Eloquent (API Table ServiceNow).

## Fonctionnalités

- Connexion et authentification Basic Auth vers une instance ServiceNow, avec abstraction `Credentials` pour permettre l'ajout futur d'autres modes (OAuth2 client credentials)
- Client HTTP interne (`TableApiClient`) et hiérarchie d'exceptions dédiées (`ServiceNowConnectionException`, `ServiceNowAuthenticationException`, `ServiceNowApiException`, `ServiceNowMalformedResponseException`)
- Modèle Eloquent de base (`ServiceNowModel`) mappant `sys_id` (clé primaire string) et `sys_created_on`/`sys_updated_on` sur les timestamps natifs Eloquent
- Query builder traduisant `where`, `whereIn`, `whereNull`, `whereBetween`, `orderBy`, `limit`/`offset` en `sysparm_query` et paramètres `sysparm_*` de l'API Table, avec pagination automatique transparente pour `all()`/`get()`
- Comptage (`count()`, `paginate()`) via la fonction d'agrégation de l'API ServiceNow, sans rapatrier les enregistrements, et test d'existence (`exists()`) borné à un enregistrement
- Introspection du schéma via `Schema::connection()` (liste des tables, colonnes typées, clés étrangères déduites des champs de référence) lue dans le dictionnaire de l'instance, avec mise en cache configurable
- `ServiceNowUnsupportedQueryException` pour toute clause du query builder sans équivalent ServiceNow (join, groupBy, agrégats autres que le comptage, sous-requêtes, etc.)

La génération automatique de modèles pour les tables configurées reste à implémenter — voir [docs/roadmap.md](docs/roadmap.md).

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
| `SNOW_SCHEMA_CACHE_TTL` | Durée de cache (secondes) du schéma lu dans le dictionnaire ; `0` désactive le cache applicatif | `300` |

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

## Introspection du schéma

Une connexion ServiceNow répond aux mêmes interrogations de schéma qu'une connexion SQL, lues dans le dictionnaire de l'instance (`sys_db_object`, `sys_dictionary`) : un outil Laravel générique d'exploration de données fonctionne donc sur une connexion ServiceNow sans rien connaître du driver.

```php
use Illuminate\Support\Facades\Schema;

$schema = Schema::connection('servicenow');

$schema->getTableListing();              // noms techniques des tables de l'instance
$schema->hasTable('incident');
$schema->getColumns('incident');         // champs hérités des tables parentes compris
$schema->getColumnListing('incident');
$schema->hasColumn('incident', 'number');
$schema->getForeignKeys('incident');     // déduites des champs de type reference

Incident::count();                       // fonction d'agrégation de l'API, sans rapatriement
Incident::paginate(20);                  // total et nombre de pages inclus
Incident::where('active', true)->exists();
```

Les types internes ServiceNow sont exposés sous les noms de types que Laravel reconnaît (`boolean`, `integer`, `decimal`, `date`, `datetime`, `time`, `json`, `text`, `varchar`) ; un type inconnu est exposé comme chaîne plutôt que de faire échouer l'introspection. Un champ de type `reference` est exposé comme clé étrangère vers `sys_id` de la table référencée — mais ServiceNow n'appliquant aucune contrainte d'intégrité référentielle, cette clé est descriptive.

La structure d'une table ServiceNow se modifie côté instance : les opérations de modification de schéma (`Schema::create()`, `drop()`, `table()`...) lèvent `ServiceNowUnsupportedQueryException`.

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

- Spécifications fonctionnelles détaillées : [docs/sfd/](docs/sfd/) — [driver](docs/sfd/1.%20Driver%20ServiceNow.md), [génération de modèles](docs/sfd/2.%20G%C3%A9n%C3%A9ration%20de%20mod%C3%A8les%20ServiceNow.md), [introspection du schéma](docs/sfd/3.%20Introspection%20du%20sch%C3%A9ma%20ServiceNow.md)
- Plan de développement et avancement : [docs/roadmap.md](docs/roadmap.md)

## Licence

MIT

# Démo — quatrebarbes/snow-driver

Application Laravel (Blade, sans front JS) illustrant l'usage du driver
`quatrebarbes/snow-driver` : menu des tables ServiceNow configurées, liste
paginée des enregistrements, détail d'un enregistrement, et gestion des
erreurs dédiées du driver (connexion, authentification, réponse malformée).

Le package est intégré via un repository composer `path` pointant sur la
racine du dépôt (`../`), pas via Packagist.

## Configuration

1. Copier `.env.example` en `.env` (déjà fait si vous partez du dépôt tel
   quel) et renseigner les variables `SNOW_*` avec les identifiants d'une
   instance ServiceNow (dev ou perso) :

   ```
   SNOW_BASE_URL=https://xxxxx.service-now.com
   SNOW_USERNAME=...
   SNOW_PASSWORD=...
   ```

2. Générer une clé applicative si nécessaire : `php artisan key:generate`.

Les tables affichées dans le menu sont définies dans
[`config/servicenow_demo.php`](config/servicenow_demo.php) — ajoutez-y une
entrée pour exposer une table supplémentaire.

## Lancer en local (sans Docker)

```bash
composer install
php artisan serve
```

L'app est alors disponible sur http://127.0.0.1:8000.

## Lancer avec Docker

Depuis la racine du dépôt (le contexte de build inclut le package en plus
de `demo/`) :

```bash
docker compose up --build
```

L'app est alors disponible sur http://localhost:8000.

## Tests

```bash
php artisan test
```

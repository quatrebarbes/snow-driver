#!/bin/sh
# Le manifeste des packages (package:discover) et tout ce qui en découle
# (boot des service providers, dont ServiceNowServiceProvider) dépend de
# variables d'environnement (.env) qui ne sont montées qu'au démarrage du
# conteneur, pas pendant `composer install` en phase de build (cf.
# Dockerfile : --no-scripts). On le rejoue donc ici, une fois l'environnement
# réel disponible.
set -e

php artisan package:discover --ansi

exec "$@"

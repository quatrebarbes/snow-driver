# Construit depuis la racine du dépôt (contexte = racine) car ce Dockerfile
# copie à la fois le package quatrebarbes/snow-driver et l'application de
# démo, pour que le repository composer "path" (../ vu depuis demo/) résolve
# vers le même arrangement de dossiers qu'en développement local.

FROM php:8.3-cli-alpine

RUN apk add --no-cache git unzip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
COPY src ./src
COPY config ./config
COPY demo ./demo

WORKDIR /app/demo
# --no-scripts : package:discover (post-autoload-dump) boote l'application,
# donc ServiceNowServiceProvider, qui lit le cache applicatif dès le boot
# (servicenow.cache.ttl > 0 par défaut). demo/.env n'est pas copié dans
# l'image (cf. .dockerignore, pas de secrets figés en build) : sans lui,
# Laravel retombe sur le cache "database"/sqlite par défaut, jamais migré,
# et le boot plante. On reporte donc package:discover à docker-entrypoint.sh,
# une fois l'environnement réel monté au démarrage du conteneur.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

RUN chmod +x docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["./docker-entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

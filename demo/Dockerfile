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
RUN composer install --no-dev --optimize-autoloader --no-interaction

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

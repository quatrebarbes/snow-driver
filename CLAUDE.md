# Contexte projet

Plug-in Laravel fournissant un driver de base de données, pour accéder aux objets d'une plateforme Service Now au travers de modèles Eloquent.

## Stack

- Laravel 11, 12, 13
- Blade (app de démo, pas de front JS)
- docker & docker-compose

## Conventions de développement

- Nommage des tables : snake_case, pluriel
- Migrations avant models, models avant controllers
- Tests Feature pour chaque endpoint API
- Tests Unit pour chaque fonction

## Documentation

### Spécifications

Les Spécifications Fonctionnelles Détaillées, au format MarkDown sont placées dans le dossier docs/sfd/.

Les exigences sont numérotées, leur identifiant `EX-...` contient un premier chiffre indiquant le module ou l'application concernées, puis 2 chiffres chrono. Les identifiants d'exigences devront être mis en commentaire dans le code l'implémentant.

Les spécifications fonctionnelles détaillées présentes dans la documentation contient des exigences que les développements réalisées doivent suivre. Une exigence se doit d'être courte, univoque et testable.

### Plan de développement

Le plan et l'avancement sont dans docs/roadmap.md. Consulte-le au début de chaque session et mets-le à jour au fur et à mesure.


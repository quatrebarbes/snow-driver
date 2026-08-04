# Roadmap

Plan de développement et suivi d'avancement du plug-in Laravel snow-driver.

État au 2026-08-04 : Phases 0, 1, 2 et 3 terminées (squelette de package, connexion et authentification, client HTTP interne et gestion des erreurs API, mapping modèle Eloquent ↔ table ServiceNow). La seule SFD existante est [1. Driver ServiceNow.md](sfd/1.%20Driver%20ServiceNow.md) (30 exigences, EX-101 à EX-130). La roadmap ci-dessous découpe ces exigences en phases techniques ordonnées par dépendance : la connexion doit exister avant le mapping des modèles, le mapping avant les requêtes, les requêtes avant les relations. Chaque exigence n'apparaît que dans une seule phase.

Convention de suivi : `[ ]` à faire, `[~]` en cours, `[x]` fait.

## Phase 0 — Socle technique du package

Prérequis non couvert par une exigence SFD mais nécessaire avant toute implémentation.

- [x] Squelette de package Laravel (`composer.json`, PSR-4 `src/`, `tests/`)
- [x] `ServiceNowServiceProvider` (publication de config ; l'enregistrement effectif du driver de connexion est fait en Phase 1 avec `ServiceNowConnection`)
- [x] Config publiable `config/servicenow.php` (connexions, credentials, timeout)
- [x] Setup PHPUnit / Orchestra Testbench (tests Unit + Feature sur un package Laravel isolé)
- [x] Supprimer tout fichier par défaut qui est inutile au repo courant (aucun généré)

## Phase 1 — Connexion et authentification

SFD : EX-101, EX-102, EX-103, EX-104, EX-121, EX-126

- [x] `ServiceNowConnection` : configuration via `config/database.php` (baseUrl, timeout)
- [x] Authentification Basic Auth (MVP) — EX-102
- [x] Abstraction `Credentials` (interface/abstract) pour permettre l'ajout futur d'OAuth2 client credentials sans casser l'API publique — EX-103
- [x] Injection des identifiants sur chaque requête, sans fuite en clair dans les logs — EX-104
- [x] Connexion paresseuse : aucune validation au boot, exception seulement à la première requête — EX-121
- [x] `ServiceNowConnectionException` dédiée en cas d'instance injoignable ou timeout dépassé — EX-126
- [x] Tests Unit : construction de la connexion, sélection des credentials, lazy-init
- [x] Tests Feature : échec de connexion (timeout/injoignable) déclenche la bonne exception

## Phase 2 — Client HTTP et gestion des erreurs API (fondation transverse)

SFD : EX-119, EX-120, EX-130

À construire avant les phases 4/5/6 car toutes les opérations CRUD et relations s'appuient sur ce client.

- [x] Client HTTP interne encapsulant les appels à l'API Table ServiceNow (`TableApiClient`, exposé via `ServiceNowConnection::tableApi()`)
- [x] Hiérarchie d'exceptions dédiées : `ServiceNowApiException` (code + message ServiceNow) pour tout 4xx/5xx — EX-119
- [x] `ServiceNowAuthenticationException` distincte pour 401/403 — EX-120
- [x] `ServiceNowMalformedResponseException` pour réponse vide/malformée (coupure réseau, timeout partiel) — EX-130
- [x] Tests Unit : mapping code HTTP → exception
- [x] Tests Feature : appels simulés (HTTP fake) couvrant 401, 403, 4xx générique, 5xx, réponse vide

## Phase 3 — Mapping modèle Eloquent ↔ table ServiceNow

SFD : EX-105, EX-106, EX-107, EX-127

- [x] `ServiceNowModel` (classe de base abstraite) : résolution du nom de table (convention Eloquent ou `$table` explicite) — EX-105
- [x] `sys_id` comme clé primaire string, non auto-incrémentée — EX-106
- [x] Mapping `sys_created_on`/`sys_updated_on` → `created_at`/`updated_at` natifs Eloquent (constantes `CREATED_AT`/`UPDATED_AT`) — EX-107
- [x] Exception explicite si table inexistante ou droits d'accès insuffisants (pas de résultat vide silencieux) — EX-127 : satisfait par composition de la résolution modèle→table (`getTable()`/`getConnectionName()`) avec la hiérarchie d'exceptions du `TableApiClient` (Phase 2), qui ne renvoie jamais un résultat vide sur 4xx/5xx
- [x] Tests Unit : résolution du nom de table, config clé primaire, mapping des timestamps
- [x] Tests Feature : accès à une table inexistante / non autorisée → exception

## Phase 4 — Lecture des enregistrements (query builder)

SFD : EX-108, EX-109, EX-110, EX-111, EX-122, EX-128

- [ ] Grammar/Builder ServiceNow : `all()`, `get()`, `find()`, `first()` via GET sur l'API Table — EX-108
- [ ] Traduction des `where()` en `sysparm_query` (syntaxe encodée ServiceNow) — EX-109
- [ ] Traduction `take/limit`, `skip/offset`, `paginate` → `sysparm_limit`/`sysparm_offset` — EX-110
- [ ] Traduction `orderBy()` → `sysparm_order_by` / `sysparm_order_by_desc` — EX-111
- [ ] Pagination automatique transparente pour `all()`/`get()` sans limite explicite, dépassant la limite max de l'API — EX-122
- [ ] Exception explicite pour toute clause du query builder sans équivalent ServiceNow (pas de traduction silencieuse incorrecte) — EX-128
- Tests Unit : traduction de chaque clause (where, limit/offset, orderBy) vers les paramètres `sysparm_*`
- Tests Feature : lecture paginée automatique, clause non supportée → exception

## Phase 5 — Application de démonstration

Non couvert par une exigence SFD : application d'exemple destinée à illustrer l'usage du driver, hébergée dans le dépôt (ex. `demo/`). Placée ici plutôt qu'en fin de roadmap car la lecture (Phase 4) est le premier point où l'IHM peut afficher des données ServiceNow réelles ; elle pourra être enrichie dans les phases suivantes (écriture, relations).

- [ ] Application de démo consommant le package via des modèles `ServiceNowModel` réels
- [ ] IHM (front) permettant de visualiser/manipuler des enregistrements ServiceNow au travers du driver
- [ ] Dockerisation de l'app de démo (Dockerfile + service dans `docker-compose.yml`) pour un lancement en une commande
- Tests : à définir selon la stack retenue pour l'IHM (Nuxt 3 pressenti, cf. stack projet)

## Phase 6 — Écriture : création, modification, suppression

SFD : EX-112, EX-113, EX-114, EX-115, EX-123, EX-124

- [ ] Création via `save()` sur nouvelle instance → POST — EX-112
- [ ] Modification via `save()`/`update()` → PUT/PATCH — EX-113
- [ ] Suppression via `delete()` → DELETE — EX-114
- [ ] Rafraîchissement automatique du modèle avec les valeurs retournées par ServiceNow après create/update — EX-115
- [ ] Traitement best-effort des opérations groupées (ex. `saveMany`) : chaque enregistrement indépendant, retour détaillé succès/échecs, sans rollback applicatif — EX-123
- [ ] Aucune détection/résolution de conflit concurrentiel : comportement natif ServiceNow (dernier écrivain gagnant) — EX-124 (documentation + non-régression, pas de mécanisme à construire)
- Tests Unit : construction des payloads POST/PUT/PATCH/DELETE
- Tests Feature : cycle create/update/delete complet, `saveMany` avec échec partiel

## Phase 7 — Relations via champs de référence

SFD : EX-116, EX-117, EX-118, EX-129, EX-125

- [ ] Exposition d'un champ `reference` comme relation `belongsTo` Eloquent standard — EX-116
- [ ] Déclaration via syntaxe Eloquent standard (méthode de relation sur le modèle), pas de DSL propriétaire — EX-117
- [ ] Support lazy loading et eager loading (`with()`) — EX-118
- [ ] Référence à `sys_id` vide → relation résolue à `null` — EX-129
- [ ] Référence vers `sys_id` supprimé → `null` (404) ; droits insuffisants → exception dédiée (403), distincte du cas absence de donnée — EX-125
- Tests Unit : résolution de la relation (cas nominal, valeur vide)
- Tests Feature : eager/lazy loading, 404 → null, 403 → exception

## Notes de suivi

- Convention d'implémentation : chaque exigence `EX-...` doit être référencée en commentaire dans le code qui l'implémente.
- Si une nouvelle SFD est ajoutée (autre module, premier chiffre de l'identifiant différent de `1`), lui ajouter une section dédiée dans cette roadmap plutôt que de mélanger les phases.
- Prochaine étape : démarrer la Phase 4 (lecture des enregistrements, query builder).

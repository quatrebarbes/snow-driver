# Roadmap

Plan de développement et suivi d'avancement du plug-in Laravel snow-driver.

État au 2026-08-04 : Phases 0 à 7 terminées (squelette de package, connexion et authentification, client HTTP interne et gestion des erreurs API, mapping modèle Eloquent ↔ table ServiceNow, lecture des enregistrements via query builder, application de démo Blade dans `demo/`, écriture create/update/delete, relations belongsTo via champs de référence). Toutes les exigences de la SFD [1. Driver ServiceNow.md](sfd/1.%20Driver%20ServiceNow.md) (30 exigences, EX-101 à EX-130) sont couvertes. La roadmap ci-dessous découpe ces exigences en phases techniques ordonnées par dépendance : la connexion doit exister avant le mapping des modèles, le mapping avant les requêtes, les requêtes avant les relations. Chaque exigence n'apparaît que dans une seule phase.

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

- [x] Grammar/Builder ServiceNow : `all()`, `get()`, `find()`, `first()` via GET sur l'API Table — EX-108 : `ServiceNowGrammar::compileSelect()` compile le query builder Eloquent vers un tableau (table, sysparm_query, fields, limit, offset) sérialisé en JSON ; `ServiceNowConnection::select()` le décode et exécute via `TableApiClient`, sans passer par `Connection::run()` (qui envelopperait les exceptions dédiées dans une `QueryException` générique)
- [x] Traduction des `where()` en `sysparm_query` (syntaxe encodée ServiceNow) — EX-109 : opérateurs `=`, `!=`/`<>`, `>`, `>=`, `<`, `<=`, `like`/`not like` (CONTAINS), `whereIn`/`whereNotIn`, `whereNull`/`whereNotNull` (ISEMPTY/ISNOTEMPTY), `whereBetween` ; combinaison and/or à plat via `^`/`^OR`
- [x] Traduction `take/limit`, `skip/offset` → `sysparm_limit`/`sysparm_offset` — EX-110 (limite explicite = un seul appel ; `paginate()` non supporté car il nécessite un COUNT préalable que l'API Table n'expose pas nativement sans lecture d'en-tête HTTP dédiée — limitation connue, hors périmètre de cette phase)
- [x] Traduction `orderBy()` → directives `ORDERBY`/`ORDERBYDESC` ajoutées à `sysparm_query` — EX-111 (l'API Table ServiceNow n'expose pas de paramètre `sysparm_order_by` séparé ; le tri s'exprime dans la requête encodée elle-même)
- [x] Pagination automatique transparente pour `all()`/`get()` sans limite explicite, dépassant la limite max de l'API — EX-122 : `ServiceNowConnection::selectAllPages()`, taille de page configurable (`servicenow.pagination.page_size`)
- [x] Exception explicite (`ServiceNowUnsupportedQueryException`) pour toute clause du query builder sans équivalent ServiceNow (join, groupBy, having, union, lock, distinct, agrégats, wheres imbriqués/sous-requêtes/comparaison de colonnes, opérateurs non mappés, `whereNotBetween`) — EX-128
- [x] Tests Unit : traduction de chaque clause (where, limit/offset, orderBy) vers les paramètres `sysparm_*` (`tests/Unit/Query/ServiceNowGrammarTest.php`)
- [x] Tests Feature : lecture paginée automatique, clause non supportée → exception (`tests/Feature/ServiceNowReadTest.php`)

## Phase 5 — Application de démonstration

Non couvert par une exigence SFD : application d'exemple destinée à illustrer l'usage du driver, hébergée dans le dépôt (`demo/`). Placée ici plutôt qu'en fin de roadmap car la lecture (Phase 4) est le premier point où l'IHM peut afficher des données ServiceNow réelles ; elle pourra être enrichie dans les phases suivantes (écriture, relations).

- [x] Application de démo Laravel (`demo/`) consommant le package via un repository composer `path` (`quatrebarbes/snow-driver`), sans base SQL locale (connexion par défaut = `servicenow`)
- [x] IHM en Blade seul (pas de front JS/Nuxt) : menu des tables ServiceNow configurées (`demo/config/servicenow_demo.php`), liste paginée des enregistrements, détail d'un enregistrement, page d'erreur illustrant la hiérarchie d'exceptions du driver (EX-119, EX-120, EX-126, EX-130) — modèle générique `App\Models\ServiceNowRecord::forTable()` pour parcourir n'importe quelle table sans classe dédiée
- [x] Dockerisation de l'app de démo (`demo/Dockerfile` + service `demo` dans `docker-compose.yml` à la racine) pour un lancement en une commande (`docker compose up --build`)
- [x] Tests Feature (`demo/tests/Feature/ServiceNowTableMenuTest.php`) : menu accessible sans connexion ServiceNow (EX-121), table inconnue → 404

## Phase 6 — Écriture : création, modification, suppression

SFD : EX-112, EX-113, EX-114, EX-115, EX-123, EX-124

- [x] Création via `save()` sur nouvelle instance → POST — EX-112 : `ServiceNowModel::performInsert()` appelle directement `tableApi()->post()` (contourne le cycle `Builder::insert()`/`Connection::insert()`, sans équivalent côté API Table, à l'image du contournement déjà pratiqué pour `select()` en Phase 4)
- [x] Modification via `save()`/`update()` → PUT/PATCH — EX-113 : `ServiceNowModel::performUpdate()` envoie un PATCH (mise à jour partielle) des seuls attributs modifiés (`getDirtyForUpdate()`) ; aucun appel si le modèle n'est pas dirty
- [x] Suppression via `delete()` → DELETE — EX-114 : `ServiceNowModel::performDeleteOnModel()`
- [x] Rafraîchissement automatique du modèle avec les valeurs retournées par ServiceNow après create/update — EX-115 : `setRawAttributes()` avec le corps de la réponse ServiceNow (sys_id généré, timestamps serveur...) ; pas d'appel à `updateTimestamps()` côté client, ces champs étant calculés par ServiceNow
- [x] Traitement best-effort des opérations groupées (`ServiceNowModel::saveMany()`) : chaque enregistrement indépendant (une exception `RuntimeException` sur l'un n'interrompt pas les suivants), retour détaillé via `SaveManyResult`/`SaveManyFailure` (successes/failures), sans rollback applicatif — EX-123
- [x] Aucune détection/résolution de conflit concurrentiel : comportement natif ServiceNow (dernier écrivain gagnant) — EX-124 (documentation dans `ServiceNowModel::performUpdate()` + test de non-régression vérifiant l'absence d'en-tête conditionnel, pas de mécanisme construit)
- Tests Feature (`tests/Feature/ServiceNowWriteTest.php`) : construction des payloads POST/PATCH/DELETE, cycle create/update/delete complet, `saveMany` avec échec partiel, non-régression EX-124

## Phase 7 — Relations via champs de référence

SFD : EX-116, EX-117, EX-118, EX-129, EX-125

- [x] Exposition d'un champ `reference` comme relation `belongsTo` Eloquent standard — EX-116 : les modèles applicatifs déclarent la relation avec `$this->belongsTo(TargetModel::class, 'champ_reference', 'sys_id')`, exactement comme pour tout autre driver Eloquent
- [x] Déclaration via syntaxe Eloquent standard (méthode de relation sur le modèle), pas de DSL propriétaire — EX-117 : aucune méthode ni attribut propriétaire ; `ServiceNowModel::newBelongsTo()` (point d'extension standard d'Eloquent) est la seule surcharge, uniquement pour retourner `ServiceNowBelongsTo` au lieu de `BelongsTo`
- [x] Support lazy loading et eager loading (`with()`) — EX-118 : comportement standard d'Eloquent, non modifié ; la requête de la relation (lazy `first()` ou eager `whereIn()`) passe par `ServiceNowConnection::select()` comme toute autre requête (Phase 4)
- [x] Référence à `sys_id` vide → relation résolue à `null` — EX-129 : `ServiceNowBelongsTo` (`src/Eloquent/Relations/ServiceNowBelongsTo.php`) normalise la chaîne vide en `null` dans `getForeignKeyFrom()`, car ce champ ServiceNow est une chaîne et non un `null` natif ; `BelongsTo::getResults()` ne teste que `is_null()`, ce qui court-circuite alors la relation sans appel à l'API Table
- [x] Référence vers `sys_id` supprimé → `null` (404) ; droits insuffisants → exception dédiée (403), distincte du cas absence de donnée — EX-125 : résolution via une requête filtrée (`sys_id=...`) sur l'API Table plutôt qu'un GET par identifiant ; un `sys_id` introuvable donne un résultat vide (200), déjà résolu à `null` par `BelongsTo::getResults()` ; un 403 lève déjà `ServiceNowAuthenticationException` via `TableApiClient::assertSuccessful()` (Phase 2), propagée sans capture
- [x] Tests Unit : résolution de la relation (cas nominal, valeur vide) (`tests/Unit/Eloquent/Relations/ServiceNowBelongsToTest.php`)
- [x] Tests Feature : eager/lazy loading, 404 → null, 403 → exception (`tests/Feature/ServiceNowRelationsTest.php`)

## Notes de suivi

- Convention d'implémentation : chaque exigence `EX-...` doit être référencée en commentaire dans le code qui l'implémente.
- Si une nouvelle SFD est ajoutée (autre module, premier chiffre de l'identifiant différent de `1`), lui ajouter une section dédiée dans cette roadmap plutôt que de mélanger les phases.
- Toutes les phases planifiées sont terminées ; prochaine étape à définir avec le métier (nouvelle SFD ou évolution, par ex. OAuth2 client credentials en complément de l'abstraction `Credentials` posée en Phase 1 — EX-103).

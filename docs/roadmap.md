# Roadmap

Plan de développement et suivi d'avancement du plug-in Laravel snow-driver.

État au 2026-08-19 : **Phases 0 à 9 terminées.** Les trois SFD sont intégralement couvertes et testées :

- [1. Driver ServiceNow.md](sfd/1.%20Driver%20ServiceNow.md) — EX-101 à EX-133 (EX-132 supprimée)
- [2. Génération de modèles ServiceNow.md](sfd/2.%20G%C3%A9n%C3%A9ration%20de%20mod%C3%A8les%20ServiceNow.md) — EX-201 à EX-211
- [3. Introspection du schéma ServiceNow.md](sfd/3.%20Introspection%20du%20sch%C3%A9ma%20ServiceNow.md) — EX-301 à EX-341 (EX-321, EX-324 supprimées ; EX-322/EX-323 réintroduites le 2026-08-18)

Suite de tests complète au vert : 208 tests / 425 assertions (dernière vérification 2026-08-18).

Convention de suivi : `[ ]` à faire, `[~]` en cours, `[x]` fait. Chaque exigence `EX-...` est référencée en commentaire dans le code qui l'implémente.

L'historique détaillé (diagnostics de bugs, décisions intermédiaires, décomptes de tests au fil de l'eau) a été retiré de ce document le 2026-08-19 pour en réduire le volume ; il reste consultable via `git log` sur `docs/roadmap.md` et les commits associés.

## Phase 0 — Socle technique du package

Prérequis non couvert par une exigence SFD.

- [x] Squelette de package Laravel (`composer.json`, PSR-4 `src/`, `tests/`)
- [x] `ServiceNowServiceProvider` (publication de config, enregistrement du driver)
- [x] Config publiable `config/servicenow.php` (connexions, credentials, timeout)
- [x] Setup PHPUnit / Orchestra Testbench

## Phase 1 — Connexion et authentification

SFD : EX-101, EX-102, EX-103, EX-104, EX-121, EX-126

- [x] `ServiceNowConnection` : configuration via `config/database.php` (baseUrl, timeout)
- [x] Authentification Basic Auth (MVP) — EX-102
- [x] Abstraction `Credentials` (interface/abstract) pour l'ajout futur d'OAuth2 client credentials — EX-103
- [x] Injection des identifiants sur chaque requête, sans fuite en clair dans les logs — EX-104
- [x] Connexion paresseuse : aucune validation au boot — EX-121
- [x] `ServiceNowConnectionException` dédiée (instance injoignable, timeout) — EX-126

## Phase 2 — Client HTTP et gestion des erreurs API

SFD : EX-119, EX-120, EX-130

- [x] `TableApiClient` (exposé via `ServiceNowConnection::tableApi()`)
- [x] `ServiceNowApiException` (code + message ServiceNow) pour tout 4xx/5xx — EX-119
- [x] `ServiceNowAuthenticationException` distincte pour 401/403 — EX-120
- [x] `ServiceNowMalformedResponseException` pour réponse vide/malformée — EX-130

## Phase 3 — Mapping modèle Eloquent ↔ table ServiceNow

SFD : EX-105, EX-106, EX-107, EX-127

- [x] `ServiceNowModel` : résolution du nom de table (convention Eloquent ou `$table`) — EX-105
- [x] `sys_id` comme clé primaire string, non auto-incrémentée — EX-106
- [x] Mapping `sys_created_on`/`sys_updated_on` → `created_at`/`updated_at` — EX-107
- [x] Exception explicite si table inexistante/droits insuffisants (pas de résultat vide silencieux) — EX-127

## Phase 4 — Lecture des enregistrements (query builder)

SFD : EX-108, EX-109, EX-110, EX-111, EX-122, EX-128, EX-133, ~~EX-132~~ *(supprimée)*

- [x] `ServiceNowGrammar`/`ServiceNowConnection::select()` : `all()`, `get()`, `find()`, `first()` via GET — EX-108
- [x] Traduction des `where()` en `sysparm_query` (`=`, `!=`, comparaisons, `like`, `whereIn/NotIn`, `whereNull/NotNull`, `whereBetween`, and/or) — EX-109
- [x] `take/limit`, `skip/offset` → `sysparm_limit`/`sysparm_offset` ; `paginate()` fonctionnel de bout en bout — EX-110
- [x] `orderBy()` → `ORDERBY`/`ORDERBYDESC` dans `sysparm_query` — EX-111
- [x] Pagination automatique transparente pour `all()`/`get()` sans limite explicite (`selectAllPages()`, `servicenow.pagination.page_size`) — EX-122 ; critère d'arrêt basé sur `X-Total-Count` en priorité (repli sur page pleine si absent)
- [x] `ServiceNowUnsupportedQueryException` pour toute clause sans équivalent ServiceNow (join, groupBy, having, union, lock, distinct, agrégats, sous-requêtes, opérateurs non mappés, `whereNotBetween`) — EX-128
- [x] ~~EX-132~~ *(supprimée le 2026-08-11, code retiré)* : conversion de type déléguée aux casts des modèles générés plutôt qu'à une conversion générique au niveau du query builder
- [x] `sysparm_exclude_reference_link=true` par défaut sur tout GET (l'appelant garde la main s'il fixe lui-même le paramètre) — EX-133

## Phase 5 — Application de démonstration

Non couvert par une exigence SFD.

- [x] Application de démo Laravel (`demo/`) consommant le package via repository composer `path`, sans base SQL locale
- [x] IHM Blade seul : menu des tables configurées, liste paginée, détail, page d'erreur illustrant la hiérarchie d'exceptions — `App\Models\ServiceNowRecord::forTable()`
- [x] Dockerisation (`demo/Dockerfile`, service `demo` dans `docker-compose.yml`)

## Phase 6 — Écriture : création, modification, suppression

SFD : EX-112, EX-113, EX-114, EX-115, EX-123, EX-124, EX-131

- [x] Création via `save()` → POST (`ServiceNowModel::performInsert()`) — EX-112
- [x] Modification via `save()`/`update()` → PATCH partiel (`getDirtyForUpdate()`) — EX-113
- [x] Suppression via `delete()` → DELETE (`performDeleteOnModel()`) — EX-114
- [x] Rafraîchissement automatique du modèle après create/update avec la réponse ServiceNow — EX-115
- [x] `saveMany()` best-effort (chaque échec indépendant, retour `SaveManyResult`/`SaveManyFailure`, pas de rollback) — EX-123
- [x] Aucune détection de conflit concurrentiel (dernier écrivain gagnant, comportement natif ServiceNow) — EX-124
- [x] `ServiceNowUnsupportedQueryException` pour toute mise à jour/suppression de masse sans instance chargée (`Model::where(...)->update()/delete()`) — EX-131

## Phase 7 — Relations via champs de référence

SFD : EX-116, EX-117, EX-118, EX-129, EX-125

- [x] Champ `reference` exposé comme `belongsTo` Eloquent standard — EX-116
- [x] Syntaxe Eloquent standard, pas de DSL propriétaire (`ServiceNowModel::newBelongsTo()`) — EX-117
- [x] Lazy et eager loading (`with()`) standard, non modifié — EX-118
- [x] `sys_id` vide → relation résolue à `null` (`ServiceNowBelongsTo::getForeignKeyFrom()`) — EX-129
- [x] Cible supprimée → `null` (404) ; droits insuffisants → exception dédiée (403) — EX-125

## Phase 8 — Introspection du schéma et cache applicatif

SFD : [3. Introspection du schéma ServiceNow.md](sfd/3.%20Introspection%20du%20sch%C3%A9ma%20ServiceNow.md) — EX-301 à EX-341, ~~EX-321, EX-324~~ *(supprimées)*

- [x] Constructeur de schéma dédié (`ServiceNowSchemaBuilder`) : tables, existence, colonnes, clés étrangères — EX-301
- [x] Liste des tables depuis `sys_db_object` (`DictionaryReader::tableCatalog()`, un seul rapatriement partagé par connexion) — EX-302 ; existence sans lecture des enregistrements — EX-303
- [x] Colonnes depuis `sys_dictionary`, chaîne d'héritage `super_class` remontée et dédoublonnée (définition la plus spécifique retenue) — EX-304
- [x] Table inexistante → absence signalée sans exception ; dictionnaire inaccessible → exception explicite — EX-305
- [x] Types internes ServiceNow → vocabulaire Laravel (`ColumnTypeMap`) — EX-306 ; repli chaîne pour type inconnu — EX-307 ; texte long distingué — EX-308 ; contrat complet (nullable, défaut, auto-incrément, commentaire) — EX-309
- [x] Champs `reference` exposés en clé étrangère vers `sys_id` — EX-310, table cible lue depuis le dictionnaire — EX-311, cible non résolvable → colonne ordinaire — EX-313
- [x] Clé étrangère/référencée déclarées explicitement dans les `belongsTo` générés — EX-312
- [x] Comptage sans rapatriement via agrégat ServiceNow (`countRecords()`) — EX-314, filtre traduit à l'identique de la lecture — EX-315, `paginate()` complet — EX-316
- [x] `exists()` borné à un enregistrement (`compileExists()`) — EX-317
- [x] Agrégats hors comptage rejetés, `count('colonne')` inclus (cas limite) — non numéroté
- [x] Exceptions API reconnaissables comme erreurs BDD Laravel sans perdre leur type (`QueryException`), connexion + URI portées — EX-318, EX-319
- [x] Clause non supportée / opération de masse rejetée : hors famille des erreurs BDD (`RuntimeException` pure) — EX-320
- [x] ~~EX-324~~ *(interrogation strictement paresseuse, supprimée le 2026-08-18)* au profit de la vérification de fraîcheur au démarrage (EX-338)
- [x] ~~EX-321~~ *(cache par table, supprimée le 2026-08-18)*, remplacée par EX-337 (périmètre resserré aux tables configurées)
- [x] Cache de la liste des tables (`TableSchemaCache::tableNames()`, une entrée par connexion, indépendante de `servicenow.models.tables`) — EX-322, réintroduite le 2026-08-18
- [x] Cache du schéma (colonnes/types/FK) et du comptage non filtré, par table configurée dans `servicenow.models.tables` — EX-337 (`src/Schema/TableSchemaCache.php`)
- [x] Durée de validité configurable, `0` désactive tout cache — EX-323 (`servicenow.cache.ttl` / `SNOW_SCHEMA_CACHE_TTL`, défaut 3600s)
- [x] Vérification de fraîcheur à chaque lecture et au démarrage (`ServiceNowServiceProvider::warmSchemaCache()`, sans appel réseau synchrone) — EX-338
- [x] Mise à jour opportuniste du comptage via l'en-tête `X-Total-Count` sur tout listing sans filtre — EX-339
- [x] Rafraîchissement asynchrone d'un cache expiré (`RefreshSchemaCacheJob`, `dispatch(...)->afterResponse()`, un seul job groupé par lot) — EX-340
- [x] Cache expiré servi tel quel sans attente bloquante du rafraîchissement — EX-341
- [x] `$fillable`/`$casts` des modèles générés : champs inscriptibles hors champs techniques, casts par type (dont booléen dédié) — EX-325, EX-326, EX-327
- [x] Conversion booléenne correcte y compris pour `"false"` (cast natif `'boolean'` purement déclaratif, conversion réelle assurée par un accessor/mutator dédié) — EX-332 (`Eloquent\Casts\ServiceNowBoolean`)
- [x] Ordre display puis mandatory en tête de `$fillable` — EX-328, EX-329 ; champs `virtual` exclus même sans `read_only` — EX-330
- [x] Cast `'string'` explicite pour les champs texte sans cast Eloquent dédié (reference exclu, valeur non scalaire) — EX-331
- [x] Ordre display puis identifiants usuels (sys_id, number, title, name, short_description, description) en tête des colonnes exposées par le schema builder — EX-333, EX-334, EX-335
- [x] Accessor/mutator booléen nommé `get{Champ}Attribute()`/`set{Champ}Attribute()` (jamais `{champ}()`) pour éviter toute collision avec les méthodes d'événement Eloquent réservées — EX-336 (`Eloquent\Casts\ServiceNowBoolean::read()`/`write()`)
- [x] Garde-fou hors SFD : opérations de modification de schéma et introspections non couvertes lèvent `ServiceNowUnsupportedQueryException` plutôt qu'une erreur PHP de bas niveau
- [x] Garde-fou hors SFD : JSON non décodable en amont de `select()` lève `ServiceNowUnsupportedQueryException`

**Points d'attention actifs :**
- Le comptage en cache ne couvre que le comptage non filtré (`Model::count()` sans clause) — un comptage filtré interroge toujours l'agrégat en direct.
- `sys_dictionary.internal_type`/`reference` renvoient le nom technique de la table cible directement (`{value, link}`), jamais un sys_id — à la différence de `super_class`. `DictionaryReader` couvre néanmoins les trois formats par robustesse.
- Le constructeur de schéma (`getSchemaBuilder()`) et son `DictionaryReader` sont mémorisés par connexion : plusieurs `hasTable()`/lectures de colonnes au sein d'une même requête HTTP ne coûtent qu'un seul aller-retour `sys_db_object`.
- Rien n'est vérifié contre `modelbase` en exécution réelle (seule la séquence d'appels est reproduite par `GenericHostToolContractTest`).
- Évolutions possibles côté métier : OAuth2 client credentials (EX-103) ; essai de bout en bout avec un outil hôte générique installé aux côtés du plug-in.

## Phase 9 — Modèles générés automatiquement

SFD : [2. Génération de modèles ServiceNow.md](sfd/2.%20G%C3%A9n%C3%A9ration%20de%20mod%C3%A8les%20ServiceNow.md) — EX-201 à EX-211

- [x] `servicenow.models.tables` : tables ServiceNow à modéliser automatiquement, vide par défaut — EX-201
- [x] `servicenow.models.namespace` (`SNOW_MODELS_NAMESPACE`), `App\Models` par défaut — EX-205
- [x] Génération au boot (`ServiceNowServiceProvider::generateModels()` → `ModelFileGenerator::generate()`) via stub, uniquement si le fichier n'existe pas déjà ; aucun appel réseau si la config est vide (préserve EX-121) — EX-202
- [x] Nom de classe StudlyCase, `$table` explicite dans le fichier généré — EX-203
- [x] Modèle généré aux mêmes capacités qu'un modèle manuel (hérite de `ServiceNowModel`, vérifié en exécution réelle) — EX-204
- [x] Génération en deux passes (tous les fichiers créés avant les relations) pour ne pas dépendre de l'ordre de traitement des tables
- [x] Champs `reference` identifiés via `DictionaryReader::fields()` — EX-206
- [x] `belongsTo()` nommée `{champ}Record()` si la table cible a un modèle résolvable (généré ou manuel), FK/clé référencée explicites (EX-312) — EX-207
- [x] Aucune relation générée si cible non résolvable ou champ non-reference (glide_list, choice) — EX-208
- [x] `hasMany()` nommée d'après le pluriel StudlyCase de la table source, recherche limitée à `servicenow.models.tables` — EX-209, EX-210
- [x] Désambiguïsation par suffixe de champ quand plusieurs reference pointent vers la même cible — EX-211
- [x] Aucune régénération/écrasement d'un fichier existant (personnalisation manuelle préservée)
- [x] Échec d'écriture (FS lecture seule) signalé (`Log::warning()`) sans bloquer le boot
- [x] Namespace non résoluble (hors `App\`) → signalement sans requête
- [x] Limite documentée : autoloader Composer avec classmap optimisée en production nécessite `composer dump-autoload` côté déploiement (pas de contournement)

## Notes de suivi

- Convention d'implémentation : chaque exigence `EX-...` doit être référencée en commentaire dans le code qui l'implémente.
- Si une nouvelle SFD est ajoutée (premier chiffre de l'identifiant différent de `1`), lui ajouter une section dédiée dans cette roadmap plutôt que de mélanger les phases.
- Toutes les phases planifiées (0 à 9) sont terminées. Pas de travail en cours à ce jour (2026-08-19).

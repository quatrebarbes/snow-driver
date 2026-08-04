# Roadmap

Plan de développement et suivi d'avancement du plug-in Laravel snow-driver.

État au 2026-08-04 : Phases 0 à 7 pour l'essentiel terminées (squelette de package, connexion et authentification, client HTTP interne et gestion des erreurs API, mapping modèle Eloquent ↔ table ServiceNow, lecture des enregistrements via query builder, application de démo Blade dans `demo/`, écriture create/update/delete, relations belongsTo via champs de référence). La SFD [1. Driver ServiceNow.md](sfd/1.%20Driver%20ServiceNow.md) (31 exigences, EX-101 à EX-131) comporte deux écarts identifiés en revue SFD du 2026-08-04 : EX-110 (`paginate()`, Phase 4) partiellement couvert — voir note dédiée — et EX-131 (rejet des mises à jour/suppressions de masse via le query builder, Phase 6) restant à implémenter ; le reste est couvert. La SFD [2. Génération de modèles ServiceNow.md](sfd/2.%20G%C3%A9n%C3%A9ration%20de%20mod%C3%A8les%20ServiceNow.md) (11 exigences, EX-201 à EX-211 : modèles générés automatiquement depuis la configuration, y compris génération automatique des relations belongsTo et de leurs relations inverses hasMany depuis le dictionnaire ServiceNow) reste à implémenter (Phase 9). La SFD [3. Introspection du schéma ServiceNow.md](sfd/3.%20Introspection%20du%20sch%C3%A9ma%20ServiceNow.md) (27 exigences, EX-301 à EX-327 : constructeur de schéma alimenté par le dictionnaire ServiceNow, typage des colonnes, champs de référence exposés en clés étrangères, comptage sans rapatriement, reconnaissance des erreurs par l'application hôte, cache d'introspection), rédigée le 2026-08-04 à la suite de l'analyse de compatibilité du driver avec un outil hôte générique d'exploration de données, est implémentée (Phase 8) à l'exception d'EX-312 et EX-325 à EX-327, qui portent sur le contenu des modèles générés et dépendent donc de la Phase 9. La roadmap ci-dessous découpe ces exigences en phases techniques ordonnées par dépendance : la connexion doit exister avant le mapping des modèles, le mapping avant les requêtes, les requêtes avant les relations. Seules les Phases 8 et 9 dérogent à cet ordre par dépendance — l'introspection du schéma (Phase 8) a été traitée avant la génération de modèles (Phase 9) bien que quatre de ses exigences en dépendent, pour lever d'abord les deux blocages qui rendaient toute exploration générique impossible. Chaque exigence n'apparaît que dans une seule phase.

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
- [~] Traduction `take/limit`, `skip/offset` → `sysparm_limit`/`sysparm_offset` — EX-110 (limite explicite = un seul appel). `paginate()` reste hors périmètre de cette phase : Eloquent appelle `count()` en interne, ce qui déclenche le garde-fou anti-agrégats de `ServiceNowGrammar` et lève `ServiceNowUnsupportedQueryException` — comportement non voulu (effet de bord, pas un rejet délibéré) et non testé. Revue SFD du 2026-08-04 : EX-110 maintenu tel quel comme cible ; support réel de `paginate()` à planifier dans une phase ultérieure (nécessite une stratégie de comptage total, ex. requête `sysparm_query` avec `GROUPBY`/en-tête `X-Total-Count`, ou COUNT explicite préalable)
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

SFD : EX-112, EX-113, EX-114, EX-115, EX-123, EX-124, EX-131

- [x] Création via `save()` sur nouvelle instance → POST — EX-112 : `ServiceNowModel::performInsert()` appelle directement `tableApi()->post()` (contourne le cycle `Builder::insert()`/`Connection::insert()`, sans équivalent côté API Table, à l'image du contournement déjà pratiqué pour `select()` en Phase 4)
- [x] Modification via `save()`/`update()` → PUT/PATCH — EX-113 : `ServiceNowModel::performUpdate()` envoie un PATCH (mise à jour partielle) des seuls attributs modifiés (`getDirtyForUpdate()`) ; aucun appel si le modèle n'est pas dirty
- [x] Suppression via `delete()` → DELETE — EX-114 : `ServiceNowModel::performDeleteOnModel()`
- [x] Rafraîchissement automatique du modèle avec les valeurs retournées par ServiceNow après create/update — EX-115 : `setRawAttributes()` avec le corps de la réponse ServiceNow (sys_id généré, timestamps serveur...) ; pas d'appel à `updateTimestamps()` côté client, ces champs étant calculés par ServiceNow
- [x] Traitement best-effort des opérations groupées (`ServiceNowModel::saveMany()`) : chaque enregistrement indépendant (une exception `RuntimeException` sur l'un n'interrompt pas les suivants), retour détaillé via `SaveManyResult`/`SaveManyFailure` (successes/failures), sans rollback applicatif — EX-123
- [x] Aucune détection/résolution de conflit concurrentiel : comportement natif ServiceNow (dernier écrivain gagnant) — EX-124 (documentation dans `ServiceNowModel::performUpdate()` + test de non-régression vérifiant l'absence d'en-tête conditionnel, pas de mécanisme construit)
- [ ] Exception explicite (`ServiceNowUnsupportedQueryException`) pour toute mise à jour/suppression de masse via le query builder sans instance chargée (`Model::where(...)->update([...])`, `Model::where(...)->delete()`) — EX-131, ajoutée lors de la revue SFD du 2026-08-04. Comportement actuel : ni supporté, ni rejeté proprement — `Builder::update()`/`delete()` retombe sur la grammaire SQL générique (non surchargée par `ServiceNowGrammar`) puis sur `getPdo()->prepare()`, qui échoue avec une `\Error` PHP native non catchée (`ServiceNowConnection::establishConnection()` renvoie un `stdClass`, pas un vrai PDO) ; le garde-fou doit intervenir avant tout appel réseau, en surchargeant `ServiceNowConnection::update()`/`delete()` ou `ServiceNowGrammar::compileUpdate()`/`compileDelete()`
- Tests Feature (`tests/Feature/ServiceNowWriteTest.php`) : construction des payloads POST/PATCH/DELETE, cycle create/update/delete complet, `saveMany` avec échec partiel, non-régression EX-124, EX-131 (mise à jour/suppression de masse → exception)

## Phase 7 — Relations via champs de référence

SFD : EX-116, EX-117, EX-118, EX-129, EX-125

- [x] Exposition d'un champ `reference` comme relation `belongsTo` Eloquent standard — EX-116 : les modèles applicatifs déclarent la relation avec `$this->belongsTo(TargetModel::class, 'champ_reference', 'sys_id')`, exactement comme pour tout autre driver Eloquent
- [x] Déclaration via syntaxe Eloquent standard (méthode de relation sur le modèle), pas de DSL propriétaire — EX-117 : aucune méthode ni attribut propriétaire ; `ServiceNowModel::newBelongsTo()` (point d'extension standard d'Eloquent) est la seule surcharge, uniquement pour retourner `ServiceNowBelongsTo` au lieu de `BelongsTo`
- [x] Support lazy loading et eager loading (`with()`) — EX-118 : comportement standard d'Eloquent, non modifié ; la requête de la relation (lazy `first()` ou eager `whereIn()`) passe par `ServiceNowConnection::select()` comme toute autre requête (Phase 4)
- [x] Référence à `sys_id` vide → relation résolue à `null` — EX-129 : `ServiceNowBelongsTo` (`src/Eloquent/Relations/ServiceNowBelongsTo.php`) normalise la chaîne vide en `null` dans `getForeignKeyFrom()`, car ce champ ServiceNow est une chaîne et non un `null` natif ; `BelongsTo::getResults()` ne teste que `is_null()`, ce qui court-circuite alors la relation sans appel à l'API Table
- [x] Référence vers `sys_id` supprimé → `null` (404) ; droits insuffisants → exception dédiée (403), distincte du cas absence de donnée — EX-125 : résolution via une requête filtrée (`sys_id=...`) sur l'API Table plutôt qu'un GET par identifiant ; un `sys_id` introuvable donne un résultat vide (200), déjà résolu à `null` par `BelongsTo::getResults()` ; un 403 lève déjà `ServiceNowAuthenticationException` via `TableApiClient::assertSuccessful()` (Phase 2), propagée sans capture
- [x] Tests Unit : résolution de la relation (cas nominal, valeur vide) (`tests/Unit/Eloquent/Relations/ServiceNowBelongsToTest.php`)
- [x] Tests Feature : eager/lazy loading, 404 → null, 403 → exception (`tests/Feature/ServiceNowRelationsTest.php`)

## Phase 8 — Introspection du schéma et compatibilité des outils génériques d'exploration

SFD : [3. Introspection du schéma ServiceNow.md](sfd/3.%20Introspection%20du%20sch%C3%A9ma%20ServiceNow.md) — EX-301 à EX-327

Indépendante de la Phase 9 pour l'essentiel, à deux exceptions près : EX-312, EX-325 à EX-327 portent sur le contenu des fichiers de modèles générés et supposent donc la Phase 9 faite. Le reste (constructeur de schéma, comptage, exceptions, cache) ne dépend que des Phases 1 à 7 et a été implémenté le 2026-08-04 — d'où cette phase traitée avant la génération de modèles, contrairement à l'ordre des SFD.

- [x] Constructeur de schéma dédié à la connexion ServiceNow (surcharge de `getSchemaBuilder()`), répondant aux cinq interrogations standards (tables, existence de table, colonnes, existence de colonne, clés étrangères) — EX-301 : `src/Schema/ServiceNowSchemaBuilder.php`. `getTableListing()`, `getColumnListing()`, `hasColumn()` et `hasColumns()` en dérivent nativement, seules `getTables()`/`hasTable()`/`getColumns()`/`getForeignKeys()` sont surchargées. Écart corrigé : `Connection::getSchemaBuilder()` renvoyait le `Builder` générique avec une grammaire de schéma nulle, et chaque appel échouait en `\Error` PHP non catchée (`Call to a member function compileTables() on null`)
- [x] Liste des tables depuis `sys_db_object` — EX-302 ; réponse aux interrogations d'existence sans lecture des enregistrements de la table — EX-303 : `src/Schema/DictionaryReader.php`, existence par interrogation ciblée (`name=<table>`, limite 1) plutôt que par rapatriement de la liste complète
- [x] Liste des colonnes depuis `sys_dictionary`, chaîne d'héritage `super_class` remontée pour inclure les champs des tables ancêtres — EX-304 : une requête par niveau d'héritage (deux à quatre en pratique) plutôt qu'un rapatriement complet de `sys_db_object`, qui compte plusieurs milliers d'enregistrements ; réordonnancement explicite des champs (`usort` sur le rang dans la chaîne), l'API Table ne garantissant aucun ordre entre les tables d'une clause `nameIN`
- [x] Table inexistante → absence signalée sans exception ; dictionnaire inaccessible (droits) → exception explicite — EX-305 : le 403 lève déjà `ServiceNowAuthenticationException` via `TableApiClient` (Phase 2), propagée sans capture
- [x] Correspondance des types internes ServiceNow vers le vocabulaire de types reconnu par les outils d'introspection Laravel — EX-306 ; repli en chaîne pour un type inconnu — EX-307 ; distinction du texte long (journal, html, script...) — EX-308 ; caractéristiques complètes du contrat standard (nullabilité, défaut, auto-incrément, commentaire) — EX-309 : `src/Schema/ColumnTypeMap.php`
- [x] Champs `reference` exposés en clés étrangères vers `sys_id` de la table cible — EX-310, table cible lue depuis le dictionnaire — EX-311, table cible non résolvable → colonne ordinaire — EX-313 ; exclusion de `glide_list`/`document_id` de fait, seul le type interne `reference` étant retenu (cas limite SFD)
- [ ] Clé étrangère et clé référencée déclarées explicitement dans les relations `belongsTo` générées — EX-312 (dépend de la Phase 9, non implémentée)
- [x] Comptage sans rapatriement via la fonction d'agrégation ServiceNow — EX-314, avec filtre traduit à l'identique de la lecture — EX-315, `paginate()` complet (total et nombre de pages) — EX-316 : `ServiceNowGrammar::compileSelect()` compile un agrégat `count` en requête marquée, exécutée par `ServiceNowConnection::countRecords()` sur `/api/now/stats/{table}?sysparm_count=true`. Écart corrigé : `count()` et `getCountForPagination()` levaient `ServiceNowUnsupportedQueryException` (garde-fou d'agrégat d'EX-128)
- [x] Test d'existence borné à un enregistrement — EX-317 : `ServiceNowGrammar::compileExists()` surchargée. Écart corrigé : `exists()` échouait en `TypeError` — la grammaire de base emballait le JSON compilé dans `select exists(...) as exists`, que `ServiceNowConnection::select()` ne pouvait plus décoder (même famille d'écart qu'EX-131)
- [x] Agrégats autres que le comptage maintenus en exception (cas limite SFD) ; `count('colonne')` rejeté lui aussi, la fonction d'agrégation de l'API ne comptant que des enregistrements, jamais les valeurs renseignées d'un champ — une traduction silencieusement fausse dès que la colonne comporte des valeurs vides
- [x] Exceptions d'erreur d'API reconnaissables comme erreurs de base de données Laravel sans perdre leurs types spécifiques — EX-318 : `ServiceNowApiException` et `ServiceNowMalformedResponseException` ré-enracinées sur `QueryException`, message métier préservé (réassignation de `$this->message` après `parent::__construct()`, que `QueryException` compose autour d'une requête SQL inexistante ici) ; nom de connexion et URI appelée portés par l'exception — EX-319 (`ServiceNowConnection::connectionName()`, propagé par `TableApiClient`)
- [x] Clause non supportée et opération de masse rejetée maintenues hors de la famille des erreurs de base de données — EX-320 : `ServiceNowUnsupportedQueryException` et `ServiceNowConnectionException` restent des `RuntimeException` pures
- [x] Cache du schéma d'une table — EX-321 et de la liste des tables — EX-322, durée configurable, nulle = désactivé — EX-323 (`servicenow.schema.cache_ttl`, `SNOW_SCHEMA_CACHE_TTL`, 300 s par défaut), interrogation strictement paresseuse — EX-324 : mémoïsation par instance systématique, doublée du cache applicatif quand la durée est strictement positive
- [ ] Champs modifiables et conversions déclarés dans les modèles générés, champs techniques ServiceNow exclus — EX-325, EX-326, EX-327 (dépend de la Phase 9, non implémentée)
- [x] Garde-fou hors exigence SFD : les opérations de modification de schéma (`create`, `drop`, `dropIfExists`, `rename`, `table`, `createDatabase`, `dropDatabaseIfExists`, `dropAllTables`) et les introspections non couvertes (`getViews`, `getIndexes`, `getSchemas`) lèvent `ServiceNowUnsupportedQueryException` plutôt que d'échouer sur l'absence de grammaire de schéma par une erreur PHP de bas niveau — même principe qu'EX-128, la structure d'une table ServiceNow se modifiant côté instance
- [x] Garde-fou hors exigence SFD : une requête arrivant à `ServiceNowConnection::select()` sans être passée par `ServiceNowGrammar` (JSON non décodable) lève `ServiceNowUnsupportedQueryException` au lieu de produire une erreur PHP sur un décodage vide — ne couvre pas EX-131, qui passe par `statement()`/`affectingStatement()` et reste à traiter
- [x] Tests Unit : correspondance des types internes ServiceNow (`tests/Unit/Schema/ColumnTypeMapTest.php` — chaque famille de types, casse indifférente, type inconnu, texte long, longueur maximale) ; lecteur de dictionnaire (`tests/Unit/Schema/DictionaryReaderTest.php` — chaîne d'héritage à trois niveaux, table racine, table inconnue, cycle d'héritage, normalisation des booléens, mémoïsation) ; grammaire (`tests/Unit/Query/ServiceNowGrammarTest.php` — comptage compilé avec ses filtres, comptage sans limite ni tri, agrégat autre que le comptage rejeté, `count('colonne')` rejeté, `compileExists()`) ; hiérarchie des exceptions (`tests/Unit/Exceptions/ServiceNowApiExceptionTest.php` — erreur d'API et réponse malformée reconnues comme erreurs de base de données, message métier préservé, nom de connexion et URI portés, cause accessible en exception précédente, clause non supportée et échec de connexion non reconnus comme erreurs de base de données)
- [x] Tests Feature : `tests/Feature/ServiceNowSchemaIntrospectionTest.php` (15 tests — liste des tables, table présente/absente, aucune lecture de la table interrogée, colonnes héritées réordonnées, typage, `internal_type` renvoyé en sys_id résolu, contrat de description complet, `hasColumn`, clé étrangère résolue, clé étrangère écartée pour table cible inconnue, cache effectif, cache désactivé, aucune interrogation avant usage, dictionnaire inaccessible, modification de schéma rejetée) ; `tests/Feature/ServiceNowCountingTest.php` (10 tests — comptage sans rapatriement, filtres portés, filtre vide omis, total et nombre de pages, page unique bornée, `exists()` borné, `exists()` négatif, `doesntExist()`, agrégat autre que comptage rejeté, réponse d'agrégation sans compteur rejetée) ; `tests/Feature/GenericHostToolContractTest.php` (séquence complète des onze interrogations d'un outil hôte générique, en test de non-régression du contrat)
- [x] Suite complète 134 tests / 247 assertions au vert (80/131 avant cette phase, +54/+116)

Points d'attention :
- **Le format exact sous lequel l'API Table renvoie `sys_dictionary.internal_type` et `sys_dictionary.reference` n'a pas pu être confirmé** : aucune instance ServiceNow n'est joignable depuis l'environnement de développement. Ces deux champs étant des références (vers `sys_glide_object` et `sys_db_object`), la forme reçue varie selon les paramètres d'appel et les versions d'instance — chaîne brute, `{value, link}`, ou `{value, display_value}`. `DictionaryReader` normalise donc les trois formes indifféremment, avec résolution du sys_id vers le nom technique quand c'est un sys_id qui est reçu (une requête `sys_glide_object` pour les types, `sys_db_object` pour les tables, toutes deux mises en cache), et les trois formes sont couvertes en test. À confirmer contre une instance réelle dès qu'une est disponible : c'est le seul point de la phase qui repose sur une hypothèse plutôt que sur une vérification.
- La valeur d'affichage d'un champ référençant `sys_db_object` est l'étiquette de la table (ex. « Company »), jamais son nom technique (`core_company`) : seule la valeur, résolue si nécessaire, est exploitable pour EX-311 — d'où le traitement distinct de `internal_type` (où le repli sur la valeur d'affichage a du sens) et de `reference` (où il n'en a pas).
- Bug trouvé par le test du cycle d'héritage, corrigé avant clôture : la garde anti-boucle de `inheritanceChain()` comparait la valeur brute de `super_class` (un sys_id) à une chaîne composée de noms de tables, et ne détectait donc jamais un cycle — un dictionnaire incohérent faisait boucler la remontée indéfiniment (constaté en dépassement de délai sur la suite de tests). La garde porte désormais sur le nom résolu.
- La vérification de l'existence de la table cible de chaque clé étrangère (EX-313) coûte une requête par table cible distincte, mise en cache : le coût est proportionnel au nombre de tables référencées par la table interrogée, pas au nombre de champs.
- Rien n'est vérifié contre `modelbase` en exécution réelle : `GenericHostToolContractTest` reproduit la séquence d'interrogations qu'un outil hôte générique adresse à la connexion (celle-là même qui échouait avant cette phase), mais un essai de bout en bout avec les deux plug-ins installés dans une même application hôte reste à faire.

## Phase 9 — Modèles générés automatiquement pour les tables configurées

SFD : [2. Génération de modèles ServiceNow.md](sfd/2.%20G%C3%A9n%C3%A9ration%20de%20mod%C3%A8les%20ServiceNow.md) — EX-201, EX-202, EX-203, EX-204, EX-205, EX-206, EX-207, EX-208, EX-209, EX-210, EX-211

- [ ] Nouvelle clé de configuration (`config/servicenow.php`) : tableau des noms techniques des tables ServiceNow à modéliser automatiquement — EX-201
- [ ] Clé de configuration du namespace global des modèles générés, `App\Models` par défaut si non renseignée — EX-205
- [ ] Au démarrage de l'application hôte (service provider), pour chaque table déclarée : génération d'un fichier de classe réel dans le code source de l'app hôte (namespace configuré) via un stub, uniquement si ce fichier n'existe pas déjà — EX-202
- [ ] Dérivation du nom de classe depuis le nom de table (StudlyCase) et association explicite à la table ServiceNow d'origine dans le fichier généré (indépendante de la convention Eloquent table↔classe) — EX-203
- [ ] Vérification qu'un modèle généré dispose des mêmes capacités qu'un modèle déclaré manuellement (lecture, écriture, relations) — EX-204
- [ ] Génération en deux passes : tous les fichiers de modèles des tables configurées sont créés avant que la génération des relations (belongsTo et hasMany) ne débute, pour ne pas dépendre de l'ordre de traitement des tables (cas limite SFD, EX-207/EX-209)
- [ ] Interrogation du dictionnaire ServiceNow (sys_dictionary) pour identifier les champs de type reference de la table générée — EX-206
- [ ] Génération d'une méthode belongsTo() nommée `{champ}Record()` pour chaque champ reference dont la table cible dispose d'un modèle Eloquent résolvable (généré ou manuel) — EX-207
- [ ] Aucune génération de relation (ignoré silencieusement) pour un champ reference dont la table cible n'a pas de modèle local résolvable, ni pour les champs non-reference référençant indirectement d'autres tables (glide_list, choice) — EX-208 (cas limite SFD)
- [ ] Identification, parmi les tables configurées, de celles disposant d'un champ reference vers la table en cours de génération, pour générer les relations inverses — EX-209
- [ ] Génération d'une méthode hasMany() nommée d'après le pluriel StudlyCase du nom de la table source (ex : tasks() sur Incident pour task.incident) — EX-210
- [ ] Désambiguïsation par suffixe du nom de champ (StudlyCase) quand plusieurs champs reference d'une même table source pointent vers la même table cible (ex : tasksIncident(), tasksParentIncident()) — EX-211
- [ ] Aucune génération de relation hasMany() pour un champ reference porté par une table non configurée (cas limite SFD)
- [ ] Aucune régénération/écrasement si le fichier existe déjà (préservation d'une personnalisation manuelle) (cas limite SFD)
- [ ] Échec d'écriture (filesystem en lecture seule) signalé sans bloquer le boot de l'application (cas limite SFD)
- [ ] Documentation de la limite liée à un autoloader Composer avec classmap optimisée en production (cas limite SFD, pas de contournement technique prévu)
- [ ] Résolution du chemin de fichier à partir du namespace configuré selon les règles PSR-4 standards (namespace enraciné sous `App\`) ; signalement explicite si non résoluble (cas limite SFD)
- [ ] Aucune erreur ni génération si le tableau de configuration est absent ou vide (cas limite SFD)
- [ ] Tests Unit : dérivation du nom de classe, association à la table d'origine, résolution du chemin depuis le namespace, résolution des champs reference depuis sys_dictionary, dérivation du nom de méthode `{champ}Record()`, dérivation du nom de méthode hasMany() (pluriel simple et désambiguïsation par champ)
- [ ] Tests Feature : génération au premier boot, absence de régénération au boot suivant, config vide → aucun effet, échec d'écriture non bloquant, namespace non résoluble → signalement, relation belongsTo générée vers un modèle existant, champ reference ignoré si table cible non résolvable, relation hasMany générée entre deux tables configurées mutuellement liées (validant la génération en deux passes), désambiguïsation de deux champs reference d'une même table source vers la même cible

## Notes de suivi

- Convention d'implémentation : chaque exigence `EX-...` doit être référencée en commentaire dans le code qui l'implémente.
- Si une nouvelle SFD est ajoutée (autre module, premier chiffre de l'identifiant différent de `1`), lui ajouter une section dédiée dans cette roadmap plutôt que de mélanger les phases.
- Prochaine étape : implémenter la Phase 9 (modèles automatiques), qui débloquera du même coup les quatre exigences de la Phase 8 laissées en attente (EX-312, EX-325 à EX-327) ; au-delà, évolution possible du côté métier (nouvelle SFD, par ex. OAuth2 client credentials en complément de l'abstraction `Credentials` posée en Phase 1 — EX-103).

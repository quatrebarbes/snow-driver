<?php

namespace Quatrebarbes\SnowDriver\Connection;

use Illuminate\Database\Connection;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Quatrebarbes\SnowDriver\Auth\Credentials;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowConnectionException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowMalformedResponseException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowUnsupportedQueryException;
use Quatrebarbes\SnowDriver\Http\TableApiClient;
use Quatrebarbes\SnowDriver\Query\ServiceNowGrammar;
use Quatrebarbes\SnowDriver\Schema\ServiceNowSchemaBuilder;
use Quatrebarbes\SnowDriver\Schema\TableSchemaCache;

/**
 * EX-101 : connexion à une instance ServiceNow configurée via config/database.php.
 */
class ServiceNowConnection extends Connection
{
    /**
     * Racine de l'API Table, préfixe de toute lecture ou écriture
     * d'enregistrements.
     */
    private const TABLE_API = '/api/now/table/';

    /**
     * Racine de l'API d'agrégation, distincte de l'API Table (EX-314).
     */
    private const STATS_API = '/api/now/stats/';

    private ?Credentials $credentials = null;

    private ?TableSchemaCache $schemaCache = null;

    private ?ServiceNowSchemaBuilder $schemaBuilder = null;

    /**
     * Mémorisation des comptages live par table + sysparm_query, pour la
     * durée de vie de cette connexion (une requête HTTP courante) : deux
     * appels identiques à countLive() — par ex. le clamp de page puis le
     * comptage interne de Builder::paginate() sur la même relation filtrée —
     * ne déclenchent qu'un seul appel réseau.
     *
     * @var array<string, int>
     */
    private array $countCache = [];

    public function __construct(string $database, string $tablePrefix, array $config)
    {
        // EX-121 : on réutilise le mécanisme de résolution différée de
        // Connection::getPdo() (fermeture invoquée une seule fois, au premier
        // appel) pour ne rien valider avant la première requête effective.
        // Ce driver n'a pas de PDO ; la fermeture vérifie la joignabilité de
        // l'instance ServiceNow à la place.
        parent::__construct(fn () => $this->establishConnection(), $database, $tablePrefix, $config);
    }

    public function connect(): void
    {
        $this->getPdo();
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->getConfig('base_url'), '/');
    }

    public function timeout(): int
    {
        return (int) ($this->getConfig('timeout') ?? 30);
    }

    public function credentials(): Credentials
    {
        if (! $this->credentials instanceof Credentials) {
            try {
                $this->credentials = Credentials::fromConfig($this->getConfig('auth') ?? []);
            } catch (InvalidArgumentException $e) {
                throw ServiceNowConnectionException::invalidConfiguration($e->getMessage());
            }
        }

        return $this->credentials;
    }

    /**
     * EX-104 : chaque requête envoyée à l'API ServiceNow porte les
     * identifiants de la connexion active.
     */
    public function httpClient(): PendingRequest
    {
        return $this->credentials()->applyTo(
            Http::baseUrl($this->baseUrl())->timeout($this->timeout())
        );
    }

    /**
     * Client HTTP interne pour les appels à l'API Table (Phase 2).
     */
    public function tableApi(): TableApiClient
    {
        return new TableApiClient($this);
    }

    /**
     * EX-337 à EX-341 : cache applicatif du schéma et du comptage des tables
     * configurées, partagé par ServiceNowSchemaBuilder (colonnes, clés
     * étrangères) et cette connexion (comptage).
     */
    public function schemaCache(): TableSchemaCache
    {
        return $this->schemaCache ??= new TableSchemaCache($this);
    }

    protected function getDefaultQueryGrammar(): ServiceNowGrammar
    {
        return new ServiceNowGrammar($this);
    }

    /**
     * EX-108, EX-109, EX-110, EX-111 : exécute la requête compilée par
     * ServiceNowGrammar (table + sysparm_query + limite/décalage) via l'API
     * Table, au lieu du cycle PDO standard.
     *
     * On n'utilise volontairement pas Connection::run() : celui-ci
     * remplacerait toute exception (ServiceNowApiException,
     * ServiceNowAuthenticationException...) par une Illuminate\Database\QueryException
     * générique, ce qui casserait la distinction attendue par EX-119/EX-120/EX-130.
     * Depuis EX-318, ces exceptions sont elles-mêmes des QueryException — un
     * outil hôte générique les reconnaît donc comme des erreurs de base de
     * données — mais chacune conserve son type propre, ce que l'enveloppement
     * par run() perdrait précisément.
     *
     * @return array<int, array<string, mixed>>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $decoded = json_decode($query, true);

        // Une requête arrivant ici sans être passée par ServiceNowGrammar
        // (chemin du query builder non surchargé retombant sur la grammaire
        // SQL de base) est rejetée explicitement, plutôt que de produire une
        // erreur PHP de bas niveau sur un décodage vide (même principe
        // qu'EX-128).
        if (! is_array($decoded) || ! isset($decoded['table'])) {
            throw ServiceNowUnsupportedQueryException::forClause(
                'requête non traduite par la grammaire ServiceNow (clause sans équivalent dans l\'API Table)'
            );
        }

        // EX-314, EX-315 : un comptage s'exécute via la fonction d'agrégation
        // de l'API, sans rapatrier d'enregistrement. La clé `aggregate` de la
        // ligne retournée est celle qu'attend Illuminate\Database\Query\Builder
        // pour tout agrégat — c'est aussi par ce chemin que paginate() obtient
        // son total et son nombre de pages (EX-316), via getCountForPagination().
        if (($decoded['aggregate'] ?? null) === 'count') {
            return [['aggregate' => $this->countRecords($decoded['table'], $decoded['query'] ?? '')]];
        }

        // EX-317 : un test d'existence se satisfait d'une lecture bornée à un
        // enregistrement. La clé `exists` est celle qu'attend le query builder.
        if ($decoded['exists'] ?? false) {
            return [['exists' => $this->hasAnyRecord($decoded['table'], $decoded['query'] ?? '') ? 1 : 0]];
        }

        $table = $decoded['table'];
        $sysparmQuery = $decoded['query'];
        $fields = $decoded['fields'];
        $limit = $decoded['limit'];
        $offset = $decoded['offset'] ?? 0;

        $params = array_filter([
            'sysparm_query' => $sysparmQuery !== '' ? $sysparmQuery : null,
            'sysparm_fields' => $fields !== null ? implode(',', $fields) : null,
        ], fn ($value) => $value !== null);

        if ($limit !== null) {
            return $this->tableApi()->get(self::TABLE_API.$table, $params + [
                'sysparm_limit' => $limit,
                'sysparm_offset' => $offset,
            ]);
        }

        return $this->fetchAllPages($table, $params, $offset);
    }

    /**
     * EX-122 : pagination automatique et transparente pour all()/get() sans
     * limite explicite, en enchaînant les appels avec un décalage croissant.
     *
     * Publique car le lecteur de dictionnaire (EX-302) en a besoin pour
     * rapatrier la liste complète des tables de l'instance, qui dépasse
     * couramment une page : il interroge l'API Table directement, sans passer
     * par le query builder, pour que l'introspection du schéma ne dépende pas
     * de la grammaire qu'elle sert justement à alimenter.
     *
     * Bug de production signalé le 2026-08-18 (chaîne d'héritage de
     * customer_contact réduite à elle-même, sys_user jamais atteint) : une
     * page peut revenir plus courte que sysparm_limit sans être la dernière —
     * constaté sur sys_db_object dès que super_class est demandé parmi les
     * champs, une ACL de champ semblant alors écarter certains
     * enregistrements du listage en lot (alors que la même table reste
     * lisible par une lecture individuelle filtrée sur son sys_id), y compris
     * au milieu d'une page. S'arrêter dès qu'une page est plus courte que la
     * limite demandée prenait alors à tort cette page pour la dernière et
     * tronquait silencieusement la suite du catalogue. L'en-tête
     * X-Total-Count (capturé dès la première page, quels que soient les
     * paramètres) sert désormais de critère d'arrêt prioritaire, non affecté
     * par ce filtrage par champ ; l'ancien critère (page pleine) ne reste un
     * repli que si cet en-tête est absent de la réponse.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllPages(string $table, array $params = [], int $offset = 0): array
    {
        $pageSize = (int) config('servicenow.pagination.page_size');

        // EX-339 : un listing sans filtre (aucun sysparm_query) d'une table
        // couverte par le cache de schéma est l'occasion d'obtenir son
        // nombre d'enregistrements sans appel dédié à l'API d'agrégation, via
        // l'en-tête X-Total-Count de sa première page.
        $rememberCount = $offset === 0
            && ! isset($params['sysparm_query'])
            && $this->schemaCache()->eligible($table);

        $records = [];
        $total = null;

        do {
            $pageParams = $params + [
                'sysparm_limit' => $pageSize,
                'sysparm_offset' => $offset,
            ];

            ['records' => $page, 'total' => $pageTotal] = $this->tableApi()->getWithTotal(self::TABLE_API.$table, $pageParams);

            if ($total === null) {
                $total = $pageTotal;
            }

            if ($rememberCount && $pageTotal !== null) {
                $this->schemaCache()->rememberCount($table, $pageTotal);
            }

            $rememberCount = false;

            $records = array_merge($records, $page);
            $offset += $pageSize;
        } while ($page !== [] && ($total !== null ? count($records) < $total : count($page) === $pageSize));

        return $records;
    }

    /**
     * EX-314, EX-315 : nombre d'enregistrements d'une table, filtres compris,
     * obtenu via la fonction d'agrégation de l'API ServiceNow (API Aggregate)
     * plutôt qu'en rapatriant puis dénombrant les enregistrements.
     *
     * EX-337 à EX-341 : seul le comptage non filtré (le nombre d'enregistrements
     * de la table elle-même) d'une table configurée passe par le cache
     * applicatif ; un comptage filtré n'a pas de valeur unique représentative
     * à mémoriser et interroge donc toujours l'instance.
     */
    private function countRecords(string $table, string $sysparmQuery): int
    {
        if ($sysparmQuery === '' && $this->schemaCache()->eligible($table)) {
            return $this->schemaCache()->count($table, fn () => $this->countLive($table));
        }

        return $this->countLive($table, $sysparmQuery);
    }

    /**
     * Comptage effectif auprès de l'API d'agrégation, sans passage par le
     * cache — utilisée directement par countRecords() pour un comptage
     * filtré ou hors cache, et par RefreshSchemaCacheJob pour rafraîchir le
     * volet count du cache.
     */
    public function countLive(string $table, string $sysparmQuery = ''): int
    {
        // EX-323 : la mémorisation ci-dessous reste soumise au même
        // interrupteur global que le cache de schéma — ttl à 0 doit continuer
        // à garantir un appel réseau par comptage, sans exception.
        $memoize = $this->schemaCache()->enabled();
        $cacheKey = $table.'|'.$sysparmQuery;

        if ($memoize && array_key_exists($cacheKey, $this->countCache)) {
            return $this->countCache[$cacheKey];
        }

        $params = ['sysparm_count' => 'true'];

        if ($sysparmQuery !== '') {
            $params['sysparm_query'] = $sysparmQuery;
        }

        $result = $this->tableApi()->get(self::STATS_API.$table, $params);

        $count = $result['stats']['count'] ?? null;

        // EX-130 : une réponse d'agrégation sans compteur exploitable est
        // rejetée explicitement plutôt que traduite en 0, qui serait un
        // résultat par défaut trompeur (table vide indiscernable d'une
        // réponse inattendue).
        if (! is_numeric($count)) {
            throw ServiceNowMalformedResponseException::forMissingAggregate($table);
        }

        if ($memoize) {
            $this->countCache[$cacheKey] = (int) $count;
        }

        return (int) $count;
    }

    /**
     * EX-317 : présence d'au moins un enregistrement correspondant aux
     * filtres, par une lecture bornée à un enregistrement et au seul champ
     * sys_id — ni comptage, ni rapatriement de l'ensemble des correspondances.
     */
    private function hasAnyRecord(string $table, string $sysparmQuery): bool
    {
        $params = [
            'sysparm_fields' => 'sys_id',
            'sysparm_limit' => 1,
        ];

        if ($sysparmQuery !== '') {
            $params['sysparm_query'] = $sysparmQuery;
        }

        return $this->tableApi()->get(self::TABLE_API.$table, $params) !== [];
    }

    /**
     * EX-301 : constructeur de schéma adossé au dictionnaire de l'instance,
     * en lieu et place du constructeur générique de Laravel, inutilisable ici
     * faute de grammaire de schéma SQL.
     *
     * Mémorisé par connexion (plutôt que reconstruit à chaque appel, comme le
     * fait le Connection::getSchemaBuilder() générique de Laravel — sans
     * conséquence pour un driver SQL, dont le Builder ne porte aucun état
     * coûteux) : ServiceNowSchemaBuilder construit paresseusement son propre
     * DictionaryReader (EX-324), dont la mémorisation du catalogue des tables
     * (tableCatalog(), sys_db_object) et des champs de chaque table ne profite
     * qu'aux appels partageant la même instance. Un outil hôte vérifiant
     * l'existence de plusieurs tables au sein d'une même requête (ex.
     * hasTable() par table d'un menu) obtenait sinon un DictionaryReader vierge
     * — donc un aller-retour sys_db_object complet — à chaque table (bug
     * constaté en production le 2026-08-18, cf. Phase 8, roadmap).
     */
    public function getSchemaBuilder(): ServiceNowSchemaBuilder
    {
        return $this->schemaBuilder ??= new ServiceNowSchemaBuilder($this);
    }

    /**
     * EX-319 : nom de la connexion tel que déclaré par l'application hôte,
     * porté par les exceptions d'erreur d'API et par les clés de cache du
     * schéma. `name` est ajouté à la configuration par ConnectionFactory ;
     * une connexion construite directement (tests) retombe sur le driver.
     */
    public function connectionName(): string
    {
        return (string) ($this->getConfig('name') ?? $this->getConfig('driver') ?? 'servicenow');
    }

    private function establishConnection(): object
    {
        $baseUrl = $this->baseUrl();

        if ($baseUrl === '') {
            throw ServiceNowConnectionException::invalidConfiguration("l'URL de base (base_url) est manquante");
        }

        try {
            $this->httpClient()->get('');
        } catch (HttpConnectionException $e) {
            throw ServiceNowConnectionException::unreachable($baseUrl, $e);
        }

        return new \stdClass();
    }

    public function __debugInfo(): array
    {
        $config = $this->config;
        unset($config['auth']);

        return [
            'database' => $this->database,
            'tablePrefix' => $this->tablePrefix,
            'config' => $config,
        ];
    }
}

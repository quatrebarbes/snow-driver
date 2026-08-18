<?php

namespace Quatrebarbes\SnowDriver\Schema;

use Closure;
use Illuminate\Support\Facades\Cache;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;

/**
 * EX-337 à EX-341 : cache applicatif du schéma (résultat brut de
 * DictionaryReader::fields()) et du nombre d'enregistrements des tables
 * déclarées dans servicenow.models.tables.
 *
 * EX-322 : la liste des tables de l'instance (DictionaryReader::tableNames())
 * suit le même mécanisme (fraîcheur vérifiée à chaque lecture et au démarrage,
 * rafraîchissement asynchrone d'une entrée expirée), mais sans être limitée
 * aux tables de servicenow.models.tables : il n'existe qu'une seule liste,
 * commune à toute l'instance, cf. tableNames()/tableNamesEligible().
 *
 * EX-323 : entièrement désactivé lorsque servicenow.cache.ttl vaut 0 ; le
 * volet par table (fields, count) reste en outre borné aux tables de
 * servicenow.models.tables — seules ces tables sont concernées par EX-337.
 *
 * EX-338, EX-340, EX-341 : chaque lecture (fields()/count()/tableNames())
 * vérifie la fraîcheur de l'entrée mémorisée. Une entrée absente déclenche un
 * premier chargement synchrone (rien à servir en attendant). Une entrée
 * expirée est renvoyée telle quelle sans attendre : son rafraîchissement est
 * délégué à RefreshSchemaCacheJob, dispatché après la réponse HTTP en cours
 * (dispatch()->afterResponse()) afin de ne jamais pénaliser la lecture qui l'a
 * déclenché — seules les lectures suivantes en bénéficient.
 *
 * Les deux volets (fields, count) sont mémorisés sous une seule clé par table,
 * mais restent rafraîchis indépendamment : interroger les colonnes d'une table
 * ne force pas un appel à l'API d'agrégation, et réciproquement.
 */
class TableSchemaCache
{
    private const KEY_PREFIX = 'snow-driver:schema-cache:';

    /**
     * EX-322 : clé unique par connexion, la liste des tables de l'instance
     * n'étant pas rattachée à une table en particulier — `__tables__` ne peut
     * entrer en collision avec un nom de table technique ServiceNow.
     */
    private const TABLE_LIST_KEY = '__tables__';

    public function __construct(private readonly ServiceNowConnection $connection)
    {
    }

    /**
     * EX-337, EX-323 : une table n'est concernée par ce cache que si elle
     * figure dans servicenow.models.tables et que la durée de validité
     * configurée est strictement positive.
     */
    public function eligible(string $table): bool
    {
        return $this->ttl() > 0 && in_array($table, $this->configuredTables(), true);
    }

    /**
     * EX-323 : point de repli pour tout mécanisme de mise en mémoire, même
     * hors du volet fields/count par table, tant que le cache applicatif est
     * globalement désactivé (servicenow.cache.ttl à 0).
     */
    public function enabled(): bool
    {
        return $this->ttl() > 0;
    }

    /**
     * @param  Closure(): array<int, array<string, mixed>>  $live
     * @return array<int, array<string, mixed>>
     */
    public function fields(string $table, Closure $live): array
    {
        return $this->resolve($table, 'fields', $live);
    }

    public function count(string $table, Closure $live): int
    {
        return $this->resolve($table, 'count', $live);
    }

    /**
     * EX-322, EX-323 : la liste des tables de l'instance n'est concernée par
     * ce cache que si la durée de validité configurée est strictement
     * positive — à la différence de eligible(), elle n'est pas bornée à
     * servicenow.models.tables, une seule liste servant toute l'instance.
     */
    public function tableNamesEligible(): bool
    {
        return $this->ttl() > 0;
    }

    /**
     * EX-322 : liste des tables de l'instance, servie par le même mécanisme
     * de fraîcheur/rafraîchissement asynchrone que fields()/count().
     *
     * @param  Closure(): array<int, string>  $live
     * @return array<int, string>
     */
    public function tableNames(Closure $live): array
    {
        $entry = $this->tableListEntry();

        if ($entry === null) {
            $value = $live();

            $this->storeTableNames($value);

            return $value;
        }

        if ($this->stale($entry['cached_at'])) {
            $this->scheduleBatchRefresh([], [], true);
        }

        return $entry['value'];
    }

    /**
     * EX-339 : mise à jour opportuniste du comptage en cache (ex. en-tête
     * X-Total-Count d'un listing sans filtre), sans passer par le circuit de
     * rafraîchissement — la valeur est immédiatement disponible, elle n'a pas
     * besoin d'être différée.
     */
    public function rememberCount(string $table, int $count): void
    {
        if (! $this->eligible($table)) {
            return;
        }

        $this->store($table, 'count', $count);
    }

    /**
     * @internal utilisé par RefreshSchemaCacheJob à l'issue du rafraîchissement.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    public function storeFields(string $table, array $fields): void
    {
        $this->store($table, 'fields', $fields);
    }

    /**
     * @internal utilisé par RefreshSchemaCacheJob à l'issue du rafraîchissement.
     */
    public function storeCount(string $table, int $count): void
    {
        $this->store($table, 'count', $count);
    }

    /**
     * @internal utilisé par RefreshSchemaCacheJob à l'issue du rafraîchissement.
     *
     * @param  array<int, string>  $names
     */
    public function storeTableNames(array $names): void
    {
        Cache::forever($this->tableListKey(), ['value' => $names, 'cached_at' => now()->getTimestamp()]);
    }

    /**
     * EX-338 : vérification de fraîcheur au démarrage de l'application hôte,
     * sans aucun appel réseau (EX-324) — seul un rafraîchissement différé
     * après réponse est éventuellement programmé pour les entrées absentes ou
     * expirées.
     *
     * Un unique job porte l'ensemble des tables à rafraîchir (cf.
     * RefreshSchemaCacheJob), plutôt qu'un job par table et par volet : sur
     * une application configurant de nombreuses tables, un job par table
     * réinterrogerait autant de fois le catalogue des tables
     * (`sys_db_object`), que RefreshSchemaCacheJob ne partage qu'au sein d'un
     * même job (un seul `DictionaryReader` pour tout le lot).
     *
     * EX-322 : la fraîcheur de la liste des tables de l'instance est vérifiée
     * dans le même mouvement, indépendamment de $tables (qui ne porte que sur
     * servicenow.models.tables).
     *
     * @param  array<int, string>  $tables
     */
    public function warm(array $tables): void
    {
        if ($this->ttl() <= 0) {
            return;
        }

        $needsFields = [];
        $needsCount = [];

        foreach (array_unique($tables) as $table) {
            $fieldsEntry = $this->part($table, 'fields');

            if ($fieldsEntry === null || $this->stale($fieldsEntry['cached_at'])) {
                $needsFields[] = $table;
            }

            $countEntry = $this->part($table, 'count');

            if ($countEntry === null || $this->stale($countEntry['cached_at'])) {
                $needsCount[] = $table;
            }
        }

        $listEntry = $this->tableListEntry();
        $needsTableList = $listEntry === null || $this->stale($listEntry['cached_at']);

        if ($needsFields === [] && $needsCount === [] && ! $needsTableList) {
            return;
        }

        $this->scheduleBatchRefresh($needsFields, $needsCount, $needsTableList);
    }

    private function resolve(string $table, string $part, Closure $live): mixed
    {
        $entry = $this->part($table, $part);

        if ($entry === null) {
            $value = $live();

            $this->store($table, $part, $value);

            return $value;
        }

        if ($this->stale($entry['cached_at'])) {
            $this->scheduleBatchRefresh(
                $part === 'fields' ? [$table] : [],
                $part === 'count' ? [$table] : [],
            );
        }

        return $entry['value'];
    }

    /**
     * @return array{value: mixed, cached_at: int}|null
     */
    private function part(string $table, string $part): ?array
    {
        $cached = Cache::get($this->key($table));

        return $cached[$part] ?? null;
    }

    /**
     * @return array{value: array<int, string>, cached_at: int}|null
     */
    private function tableListEntry(): ?array
    {
        return Cache::get($this->tableListKey());
    }

    private function store(string $table, string $part, mixed $value): void
    {
        $key = $this->key($table);
        $cached = Cache::get($key) ?? [];

        $cached[$part] = ['value' => $value, 'cached_at' => now()->getTimestamp()];

        Cache::forever($key, $cached);
    }

    private function stale(int $cachedAt): bool
    {
        return (now()->getTimestamp() - $cachedAt) >= $this->ttl();
    }

    /**
     * @param  array<int, string>  $fieldsTables
     * @param  array<int, string>  $countTables
     */
    private function scheduleBatchRefresh(array $fieldsTables, array $countTables, bool $refreshTableList = false): void
    {
        dispatch(new RefreshSchemaCacheJob(
            $this->connection->connectionName(),
            $fieldsTables,
            $countTables,
            $refreshTableList,
        ))->afterResponse();
    }

    private function ttl(): int
    {
        return (int) config('servicenow.cache.ttl', 0);
    }

    /**
     * @return array<int, string>
     */
    private function configuredTables(): array
    {
        return (array) config('servicenow.models.tables', []);
    }

    private function key(string $table): string
    {
        return self::KEY_PREFIX.$this->connection->connectionName().':'.$table;
    }

    private function tableListKey(): string
    {
        return self::KEY_PREFIX.$this->connection->connectionName().':'.self::TABLE_LIST_KEY;
    }
}

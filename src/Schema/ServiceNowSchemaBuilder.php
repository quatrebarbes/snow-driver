<?php

namespace Quatrebarbes\SnowDriver\Schema;

use Closure;
use Illuminate\Database\Schema\Builder;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowUnsupportedQueryException;

/**
 * EX-301 : constructeur de schéma d'une connexion ServiceNow, répondant aux
 * mêmes interrogations qu'un driver SQL (liste des tables, existence d'une
 * table, colonnes d'une table, existence d'une colonne, clés étrangères) à
 * partir du dictionnaire de l'instance plutôt que d'une grammaire SQL.
 *
 * Les descripteurs retournés respectent le contrat des méthodes homonymes de
 * Illuminate\Database\Schema\Builder, clé pour clé : un outil hôte générique
 * lit le schéma d'une connexion ServiceNow sans savoir qu'il ne s'agit pas
 * d'une base SQL. `getTableListing()`, `getColumnListing()`, `hasColumn()` et
 * `hasColumns()` en dérivent nativement et ne sont donc pas surchargées.
 *
 * EX-303 : aucune de ces méthodes ne lit d'enregistrement de la table
 * interrogée — seul le dictionnaire est interrogé.
 */
class ServiceNowSchemaBuilder extends Builder
{
    /**
     * EX-334 : champs identifiants usuels placés en tête de la liste des
     * colonnes, dans cet ordre, à la suite du champ display (EX-333).
     */
    private const LEADING_IDENTIFIER_FIELDS = ['sys_id', 'number', 'title', 'name', 'short_description', 'description'];

    private ?DictionaryReader $dictionary = null;

    /**
     * La propriété $connection héritée est typée sur Illuminate\Database\Connection ;
     * la connexion est donc conservée une seconde fois sous son type réel,
     * dont le lecteur de dictionnaire a besoin (accès à l'API Table).
     */
    public function __construct(private readonly ServiceNowConnection $serviceNowConnection)
    {
        parent::__construct($serviceNowConnection);
    }

    /**
     * EX-302 : tables de l'instance, décrites selon le contrat standard.
     *
     * Le paramètre de schéma est sans objet pour ServiceNow, qui n'a pas de
     * notion de schéma : il est accepté pour respecter la signature héritée,
     * et ignoré.
     *
     * @param  string|string[]|null  $schema
     * @return list<array{name: string, schema: string|null, schema_qualified_name: string, size: int|null, comment: string|null, collation: string|null, engine: string|null}>
     */
    public function getTables($schema = null)
    {
        return array_map(fn (string $name) => [
            'name' => $name,
            'schema' => null,
            'schema_qualified_name' => $name,
            'size' => null,
            'comment' => null,
            'collation' => null,
            'engine' => null,
        ], $this->tableNames());
    }

    /**
     * EX-303, EX-305 : existence d'une table par interrogation ciblée du
     * dictionnaire. Une table absente est signalée comme telle, sans
     * exception ; un refus d'accès au dictionnaire lève en revanche
     * ServiceNowAuthenticationException (réponse 403, EX-120), afin de ne
     * jamais présenter un dictionnaire inaccessible comme une instance sans
     * tables.
     *
     * EX-322 : résolue depuis tableNames(), donc servie par le cache
     * applicatif de la liste des tables lorsqu'il est actif — sans quoi un
     * outil hôte interrogeant hasTable() à chaque requête HTTP (une connexion
     * neuve à chaque fois) redéclenchait un aller-retour sys_db_object complet
     * à chaque appel, le partage par instance de DictionaryReader ne
     * bénéficiant qu'aux appels d'une même connexion (cf. getSchemaBuilder()).
     *
     * @param  string  $table
     * @return bool
     */
    public function hasTable($table)
    {
        return $this->tableExists($this->connection->getTablePrefix().$table);
    }

    /**
     * EX-304, EX-306 à EX-309 : colonnes d'une table, champs hérités des
     * tables ancêtres compris, chacune décrite selon le contrat standard.
     *
     * EX-333 à EX-335 : le champ display, puis les champs identifiants usuels
     * présents sur la table, sont placés en tête de la liste retournée — cf.
     * orderColumns().
     *
     * @param  string  $table
     * @return list<array{name: string, type: string, type_name: string, collation: string|null, nullable: bool, default: mixed, auto_increment: bool, comment: string|null, generation: array{type: string, expression: string|null}|null}>
     */
    public function getColumns($table)
    {
        $fields = $this->orderColumns($this->fields($table));

        return array_map(fn (array $field) => [
            'name' => $field['element'],
            'type_name' => ColumnTypeMap::typeName($field['internal_type']),
            'type' => ColumnTypeMap::type($field['internal_type'], $field['max_length']),
            'collation' => null,
            // EX-309 : un champ non obligatoire au dictionnaire accepte
            // l'absence de valeur. Le caractère obligatoire est exposé à ce
            // seul titre : sa violation reste tranchée par l'instance à
            // l'écriture, le driver n'en dérivant aucune validation.
            'nullable' => ! $field['mandatory'],
            'default' => $field['default'],
            // EX-106 : aucun champ ServiceNow n'est auto-incrémenté, sys_id
            // étant une chaîne générée par l'instance.
            'auto_increment' => false,
            'comment' => $field['label'],
            'generation' => null,
        ], $fields);
    }

    /**
     * EX-310, EX-311, EX-313 : champs de type reference exposés comme clés
     * étrangères vers sys_id de la table qu'ils référencent, cette table étant
     * lue depuis le dictionnaire. Un champ dont la table cible est inexistante
     * ou inaccessible n'est pas exposé comme clé étrangère (il reste une
     * colonne ordinaire, cf. getColumns()).
     *
     * Les champs référençant plusieurs enregistrements (glide_list) ou dont la
     * table cible dépend d'un autre champ (document_id) sont exclus de fait :
     * seul le type interne `reference` est retenu ici.
     *
     * @param  string  $table
     * @return list<array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string|null, on_delete: string|null}>
     */
    public function getForeignKeys($table)
    {
        $fields = $this->fields($table);

        $foreignKeys = [];

        foreach ($fields as $field) {
            if (strtolower($field['internal_type']) !== 'reference' || $field['reference_table'] === null) {
                continue;
            }

            if (! $this->tableExists($field['reference_table'])) {
                continue;
            }

            $foreignKeys[] = [
                // ServiceNow n'a pas de contrainte d'intégrité référentielle
                // nommée : la clé étrangère exposée est descriptive, déduite du
                // dictionnaire, et n'a donc pas de nom de contrainte.
                'name' => null,
                'columns' => [$field['element']],
                'foreign_schema' => null,
                'foreign_table' => $field['reference_table'],
                'foreign_columns' => ['sys_id'],
                'on_update' => null,
                'on_delete' => null,
            ];
        }

        return $foreignKeys;
    }

    /**
     * Hors périmètre du module 3, qui ne couvre que la lecture du schéma : les
     * opérations de modification de schéma et les introspections non couvertes
     * lèvent une exception explicite plutôt que d'échouer sur l'absence de
     * grammaire de schéma par une erreur PHP de bas niveau (même principe
     * qu'EX-128 pour le query builder). La structure d'une table ServiceNow se
     * modifie côté instance, pas depuis une migration Laravel.
     */
    public function createDatabase($name)
    {
        throw $this->unsupported('création de base de données');
    }

    public function dropDatabaseIfExists($name)
    {
        throw $this->unsupported('suppression de base de données');
    }

    public function create($table, Closure $callback)
    {
        throw $this->unsupported('création de table');
    }

    public function drop($table)
    {
        throw $this->unsupported('suppression de table');
    }

    public function dropIfExists($table)
    {
        throw $this->unsupported('suppression de table');
    }

    public function dropAllTables()
    {
        throw $this->unsupported('suppression de toutes les tables');
    }

    public function rename($from, $to)
    {
        throw $this->unsupported('renommage de table');
    }

    public function table($table, Closure $callback)
    {
        throw $this->unsupported('modification de table');
    }

    public function getViews($schema = null)
    {
        throw $this->unsupported('listage des vues');
    }

    public function getIndexes($table)
    {
        throw $this->unsupported('listage des index');
    }

    public function getSchemas()
    {
        throw $this->unsupported('listage des schémas');
    }

    private function unsupported(string $operation): ServiceNowUnsupportedQueryException
    {
        return ServiceNowUnsupportedQueryException::forClause($operation.' (le schéma d\'une instance ServiceNow se modifie côté instance)');
    }

    /**
     * EX-333 : le champ marqué display par le dictionnaire, s'il en existe un
     * parmi $fields, est placé en tête. Si plusieurs champs sont marqués
     * display (dictionnaire non conforme aux conventions ServiceNow), seul le
     * premier rencontré dans l'ordre EX-304 (déjà celui de $fields en entrée)
     * est placé en tête ; les autres restent traités comme des colonnes
     * ordinaires, comme pour $fillable (EX-328, EX-329).
     *
     * EX-334 : à la suite du champ display, chacun des champs identifiants
     * usuels de LEADING_IDENTIFIER_FIELDS est placé dans cet ordre fixe, s'il
     * existe sur la table et n'a pas déjà été placé par EX-333.
     *
     * EX-335 : les champs restants conservent entre eux l'ordre EX-304 de
     * $fields en entrée — cet ordre n'est jamais modifié, seuls des champs en
     * sont extraits pour être replacés en tête.
     *
     * Cet ordre ne régit que la liste des colonnes retournée par
     * getColumns() ; il est indépendant de l'ordre du champ display puis des
     * champs mandatory appliqué à $fillable des modèles générés (EX-328,
     * EX-329, Generator\ModelFileGenerator::fillableFields()).
     *
     * @param  array<int, array<string, mixed>>  $fields  ordonnés selon EX-304
     * @return array<int, array<string, mixed>>
     */
    private function orderColumns(array $fields): array
    {
        $displayIndex = null;

        foreach ($fields as $index => $field) {
            if ($field['display']) {
                $displayIndex = $index;
                break;
            }
        }

        $leading = [];

        if ($displayIndex !== null) {
            $leading[] = $fields[$displayIndex];
            unset($fields[$displayIndex]);
        }

        foreach (self::LEADING_IDENTIFIER_FIELDS as $element) {
            foreach ($fields as $index => $field) {
                if ($field['element'] === $element) {
                    $leading[] = $field;
                    unset($fields[$index]);

                    continue 2;
                }
            }
        }

        return array_merge($leading, array_values($fields));
    }

    private function dictionary(): DictionaryReader
    {
        // EX-324 : le lecteur n'est construit, et le dictionnaire interrogé,
        // qu'à la première interrogation effective du schéma.
        return $this->dictionary ??= new DictionaryReader($this->serviceNowConnection);
    }

    /**
     * EX-337 à EX-341 : champs du dictionnaire d'une table, servis par le
     * cache applicatif lorsque la table est configurée (servicenow.models.tables)
     * et le cache actif, en direct depuis le dictionnaire sinon.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fields(string $table): array
    {
        $prefixedTable = $this->connection->getTablePrefix().$table;

        $cache = $this->serviceNowConnection->schemaCache();

        if (! $cache->eligible($prefixedTable)) {
            return $this->dictionary()->fields($prefixedTable);
        }

        return $cache->fields($prefixedTable, fn () => $this->dictionary()->fields($prefixedTable));
    }

    /**
     * EX-322 : liste des tables de l'instance, servie par le cache applicatif
     * lorsqu'il est actif, en direct depuis le dictionnaire sinon — à la
     * différence de fields(), sans dépendre de servicenow.models.tables.
     *
     * @return array<int, string>
     */
    private function tableNames(): array
    {
        $cache = $this->serviceNowConnection->schemaCache();

        if (! $cache->tableNamesEligible()) {
            return $this->dictionary()->tableNames();
        }

        return $cache->tableNames(fn () => $this->dictionary()->tableNames());
    }

    /**
     * EX-303, EX-322 : existence d'une table, dérivée de tableNames() plutôt
     * que d'une interrogation dédiée du dictionnaire, afin de bénéficier du
     * même cache applicatif que getTables() — utilisée par hasTable() et par
     * la vérification de la table cible d'un champ reference (getForeignKeys()).
     */
    private function tableExists(string $table): bool
    {
        return in_array($table, $this->tableNames(), true);
    }
}

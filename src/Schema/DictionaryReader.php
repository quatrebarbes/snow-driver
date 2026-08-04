<?php

namespace Quatrebarbes\SnowDriver\Schema;

use Closure;
use Illuminate\Support\Facades\Cache;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;

/**
 * Lecture du dictionnaire de données d'une instance ServiceNow (tables
 * sys_db_object et sys_dictionary), seule source du schéma exposé par
 * ServiceNowSchemaBuilder.
 *
 * EX-321 à EX-324 : chaque lecture est mise en cache (durée configurable via
 * servicenow.schema.cache_ttl, une durée nulle désactivant le cache) et
 * n'a lieu qu'à la première interrogation effective — rien n'est interrogé au
 * démarrage de l'application.
 *
 * Normalisation défensive des valeurs (cf. `technicalValue()`) : un champ
 * ServiceNow de type reference est renvoyé par l'API Table sous une forme qui
 * varie selon les paramètres d'appel et les versions d'instance (chaîne brute,
 * `{value, link}`, ou `{value, display_value}` avec sysparm_display_value).
 * Les deux champs dont ce module a besoin — `internal_type` (vers
 * sys_glide_object) et `reference` (vers sys_db_object) — sont donc traités
 * indifféremment de la forme reçue, avec résolution du sys_id vers le nom
 * technique lorsque c'est un sys_id qui est renvoyé.
 *
 * Limite : le format exact renvoyé par ces deux champs n'a pas pu être
 * confirmé contre une instance ServiceNow réelle (aucune instance joignable
 * depuis l'environnement de développement) — d'où cette normalisation
 * tolérante plutôt qu'un format supposé, et la couverture des trois formes en
 * test unitaire.
 */
class DictionaryReader
{
    /**
     * Un sys_id ServiceNow est une chaîne de 32 caractères hexadécimaux
     * (EX-106) : ce motif distingue un identifiant à résoudre d'un nom
     * technique déjà exploitable tel quel.
     */
    private const SYS_ID = '/^[0-9a-f]{32}$/i';

    /**
     * Mémoïsation par instance, indépendante du cache applicatif : deux
     * interrogations du même schéma au sein d'une même requête HTTP ne
     * déclenchent qu'un seul appel au dictionnaire, y compris lorsque le cache
     * applicatif est désactivé (EX-323).
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    public function __construct(private readonly ServiceNowConnection $connection)
    {
    }

    /**
     * EX-302 : noms techniques de toutes les tables de l'instance.
     *
     * EX-322 : mise en cache comme le schéma d'une table — une instance
     * ServiceNow compte plusieurs milliers de tables, dont le rapatriement
     * enchaîne plusieurs pages (EX-122).
     *
     * @return array<int, string>
     */
    public function tableNames(): array
    {
        return $this->remember('tables', function (): array {
            $records = $this->connection->fetchAllPages('sys_db_object', [
                'sysparm_fields' => 'name',
                'sysparm_query' => 'ORDERBYname',
            ]);

            return array_values(array_filter(array_map(
                fn (array $record) => $this->technicalValue($record['name'] ?? null),
                $records
            )));
        });
    }

    /**
     * EX-303, EX-305 : existence d'une table, par interrogation ciblée du
     * dictionnaire plutôt que par rapatriement de la liste complète des
     * tables, et sans lire aucun enregistrement de la table concernée.
     */
    public function tableExists(string $table): bool
    {
        return $this->remember('exists:'.$table, fn (): bool => $this->firstTableRecord($table) !== null);
    }

    /**
     * EX-304 : champs d'une table, ceux hérités de ses tables ancêtres
     * inclus, ordonnés de la table la plus générale à la table interrogée.
     *
     * @return array<int, array{table: string, element: string, internal_type: string, reference_table: string|null, max_length: int|null, mandatory: bool, read_only: bool, default: string|null, label: string|null}>
     */
    public function fields(string $table): array
    {
        return $this->remember('fields:'.$table, function () use ($table): array {
            $chain = $this->inheritanceChain($table);

            if ($chain === []) {
                return [];
            }

            $records = $this->connection->fetchAllPages('sys_dictionary', [
                'sysparm_query' => 'nameIN'.implode(',', $chain).'^elementISNOTEMPTY^active=true',
                'sysparm_fields' => 'name,element,internal_type,reference,max_length,mandatory,read_only,default_value,column_label',
                'sysparm_display_value' => 'all',
            ]);

            $fields = array_values(array_filter(array_map(
                fn (array $record) => $this->normalizeField($record),
                $records
            )));

            // Ordre stable : les champs de la table la plus générale d'abord
            // (l'API Table ne garantit aucun ordre entre les tables de la
            // clause nameIN, et sysparm_query ne permet pas de trier sur une
            // liste ordonnée de valeurs).
            $rank = array_flip($chain);

            usort($fields, fn (array $a, array $b) => $rank[$a['table']] <=> $rank[$b['table']]);

            return $fields;
        });
    }

    /**
     * EX-304 : chaîne d'héritage d'une table, de la plus générale à la table
     * interrogée (ex. ['task', 'incident']). Vide si la table est inconnue.
     *
     * Une requête par niveau d'héritage (deux à quatre en pratique) plutôt
     * qu'un rapatriement complet de sys_db_object, qui compte plusieurs
     * milliers d'enregistrements sur une instance ServiceNow courante.
     *
     * @return array<int, string>
     */
    public function inheritanceChain(string $table): array
    {
        return $this->remember('chain:'.$table, function () use ($table): array {
            $record = $this->firstTableRecord($table);

            if ($record === null) {
                return [];
            }

            $chain = [$table];
            $parent = $this->technicalValue($record['super_class'] ?? null);

            while ($parent !== null) {
                $parentName = $this->resolveTableName($parent);

                // La garde porte sur le nom résolu, jamais sur la valeur brute
                // de super_class : celle-ci est un sys_id, qui ne figure jamais
                // dans une chaîne composée de noms de tables et ne détecterait
                // donc aucun cycle. Un cycle d'héritage est impossible côté
                // ServiceNow, mais sans cette garde un dictionnaire incohérent
                // ferait boucler la remontée indéfiniment.
                if ($parentName === null || in_array($parentName, $chain, true)) {
                    break;
                }

                $chain[] = $parentName;

                $record = $this->firstTableRecord($parentName);
                $parent = $record !== null ? $this->technicalValue($record['super_class'] ?? null) : null;
            }

            return array_reverse($chain);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstTableRecord(string $table): ?array
    {
        $records = $this->connection->tableApi()->get('/api/now/table/sys_db_object', [
            'sysparm_query' => 'name='.$table,
            'sysparm_fields' => 'name,super_class,label',
            'sysparm_limit' => 1,
        ]);

        return $records[0] ?? null;
    }

    /**
     * Nom technique d'une table à partir de la valeur d'un champ la
     * référençant : soit la valeur est déjà ce nom, soit c'est un sys_id
     * d'enregistrement sys_db_object à résoudre.
     */
    private function resolveTableName(string $value): ?string
    {
        if (preg_match(self::SYS_ID, $value) !== 1) {
            return $value;
        }

        return $this->remember('table-name:'.$value, function () use ($value): ?string {
            $records = $this->connection->tableApi()->get('/api/now/table/sys_db_object', [
                'sysparm_query' => 'sys_id='.$value,
                'sysparm_fields' => 'name',
                'sysparm_limit' => 1,
            ]);

            return $this->technicalValue($records[0]['name'] ?? null);
        });
    }

    /**
     * Noms techniques des types de champs de l'instance (sys_glide_object),
     * indexés par sys_id — nécessaire lorsque `internal_type` est renvoyé
     * sous forme de sys_id plutôt que de nom technique. Une seule requête
     * pour toute l'instance (une centaine d'enregistrements), mise en cache.
     *
     * @return array<string, string>
     */
    private function fieldTypeNames(): array
    {
        return $this->remember('field-types', function (): array {
            $records = $this->connection->fetchAllPages('sys_glide_object', [
                'sysparm_fields' => 'sys_id,name',
            ]);

            $names = [];

            foreach ($records as $record) {
                $sysId = $this->technicalValue($record['sys_id'] ?? null);
                $name = $this->technicalValue($record['name'] ?? null);

                if ($sysId !== null && $name !== null) {
                    $names[$sysId] = $name;
                }
            }

            return $names;
        });
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{table: string, element: string, internal_type: string, reference_table: string|null, max_length: int|null, mandatory: bool, read_only: bool, default: string|null, label: string|null}|null
     */
    private function normalizeField(array $record): ?array
    {
        $element = $this->technicalValue($record['element'] ?? null);
        $table = $this->technicalValue($record['name'] ?? null);

        if ($element === null || $table === null) {
            return null;
        }

        $maxLength = $this->technicalValue($record['max_length'] ?? null);

        return [
            'table' => $table,
            'element' => $element,
            'internal_type' => $this->resolveInternalType($record['internal_type'] ?? null),
            'reference_table' => $this->resolveReferenceTable($record['reference'] ?? null),
            'max_length' => is_numeric($maxLength) ? (int) $maxLength : null,
            'mandatory' => $this->isTrue($record['mandatory'] ?? null),
            'read_only' => $this->isTrue($record['read_only'] ?? null),
            'default' => $this->technicalValue($record['default_value'] ?? null),
            'label' => $this->displayValue($record['column_label'] ?? null),
        ];
    }

    /**
     * EX-306, EX-307 : nom technique du type interne du champ. Un sys_id est
     * résolu via sys_glide_object ; à défaut de résolution, la valeur
     * d'affichage est tentée, puis le repli en chaîne de caractères (assuré
     * par ColumnTypeMap pour tout type inconnu).
     */
    private function resolveInternalType(mixed $value): string
    {
        $technical = $this->technicalValue($value);

        if ($technical === null) {
            return 'string';
        }

        if (preg_match(self::SYS_ID, $technical) !== 1) {
            return $technical;
        }

        return $this->fieldTypeNames()[$technical]
            ?? $this->displayValue($value)
            ?? 'string';
    }

    /**
     * EX-311 : table référencée par un champ de type reference, lue depuis le
     * dictionnaire et jamais déduite du nom du champ. La valeur d'affichage
     * d'un champ référençant sys_db_object est l'étiquette de la table (ex.
     * « Company »), pas son nom technique : seule la valeur, résolue si c'est
     * un sys_id, est exploitable ici.
     */
    private function resolveReferenceTable(mixed $value): ?string
    {
        $technical = $this->technicalValue($value);

        if ($technical === null || $technical === '') {
            return null;
        }

        return $this->resolveTableName($technical);
    }

    /**
     * Valeur exploitable d'un champ renvoyé par l'API Table, quelle que soit
     * sa forme : chaîne brute, `{value, link}` ou `{value, display_value}`.
     */
    private function technicalValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function displayValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['display_value'] ?? null;
        }

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    /**
     * Un booléen ServiceNow est renvoyé par l'API Table sous forme de chaîne
     * ("true"/"false", parfois "1"/"0"), jamais de booléen JSON natif.
     */
    private function isTrue(mixed $value): bool
    {
        return in_array($this->technicalValue($value), ['true', '1'], true);
    }

    /**
     * EX-321 à EX-323 : mémoïsation par instance systématique, doublée du
     * cache applicatif lorsque sa durée de validité est strictement positive.
     */
    private function remember(string $key, Closure $callback): mixed
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $ttl = (int) config('servicenow.schema.cache_ttl', 0);

        if ($ttl <= 0) {
            return $this->memo[$key] = $callback();
        }

        return $this->memo[$key] = Cache::remember(
            'snow-driver:schema:'.$this->connection->connectionName().':'.$key,
            $ttl,
            $callback
        );
    }
}

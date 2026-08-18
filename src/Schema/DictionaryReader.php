<?php

namespace Quatrebarbes\SnowDriver\Schema;

use Closure;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;

/**
 * Lecture du dictionnaire de données d'une instance ServiceNow (tables
 * sys_db_object et sys_dictionary), seule source du schéma exposé par
 * ServiceNowSchemaBuilder.
 *
 * EX-324 : chaque lecture n'a lieu qu'à la première interrogation effective
 * — rien n'est interrogé au démarrage de l'application.
 *
 * Catalogue unique (cf. `tableCatalog()`) : name, sys_id et super_class de
 * toutes les tables de l'instance sont rapatriés en un seul appel à
 * sys_db_object, mémorisé et mis en cache comme le reste du dictionnaire.
 * tableNames(), tableExists(), inheritanceChain() et la résolution des champs
 * `reference` s'y résolvent ensuite sans aucun appel réseau supplémentaire —
 * la remontée d'une chaîne d'héritage à plusieurs niveaux ou la résolution de
 * plusieurs champs de référence ne coûtaient auparavant qu'un aller-retour
 * par niveau ou par lot, chacun mesuré à plus d'une seconde contre une
 * instance réelle ; ce coût disparaît une fois le catalogue chargé.
 *
 * Normalisation défensive des valeurs (cf. `technicalValue()`) : un champ
 * ServiceNow de type reference est renvoyé par l'API Table sous une forme qui
 * varie selon les paramètres d'appel et les versions d'instance (chaîne brute,
 * `{value, link}`, ou `{value, display_value}` avec sysparm_display_value).
 * Les deux champs dont ce module a besoin — `internal_type` (vers
 * sys_glide_object) et `reference` (vers sys_db_object) — sont donc traités
 * indifféremment de la forme reçue, avec résolution du sys_id vers le nom
 * technique lorsque c'est un sys_id qui est renvoyé. Constaté contre une
 * instance réelle : ces deux champs renvoient en pratique directement le nom
 * technique (jamais un sys_id) dès que `super_class` n'est pas concerné — le
 * cas sys_id reste néanmoins couvert, un champ de référence ServiceNow restant
 * par définition capable de le faire.
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
     * Mémorisation par instance : deux interrogations du même schéma au
     * moyen de cette même instance de DictionaryReader ne déclenchent qu'un
     * seul appel au dictionnaire.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    public function __construct(private readonly ServiceNowConnection $connection)
    {
    }

    /**
     * EX-302 : noms techniques de toutes les tables de l'instance. Dérivé de
     * tableCatalog(), qui porte seul la mémorisation : aucun appel réseau
     * propre à cette méthode.
     *
     * @return array<int, string>
     */
    public function tableNames(): array
    {
        return array_column($this->tableCatalog(), 'name');
    }

    /**
     * EX-303, EX-305 : existence d'une table, sans lire aucun enregistrement
     * de la table concernée — une recherche dans tableCatalog(), déjà chargé
     * en mémoire, jamais un aller-retour réseau dédié.
     */
    public function tableExists(string $table): bool
    {
        return $this->tableRecord($table) !== null;
    }

    /**
     * EX-304 : champs d'une table, ceux hérités de ses tables ancêtres
     * inclus, ordonnés de la table la plus générale à la table interrogée.
     *
     * @return array<int, array{table: string, element: string, internal_type: string, reference_table: string|null, max_length: int|null, mandatory: bool, read_only: bool, display: bool, virtual: bool, default: string|null, label: string|null}>
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
                'sysparm_fields' => 'name,element,internal_type,reference,max_length,mandatory,read_only,display,virtual,default_value,column_label',
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

            return $this->deduplicateByElement($fields);
        });
    }

    /**
     * Une table enfant peut disposer, pour un champ hérité, de son propre
     * enregistrement sys_dictionary surchargeant certains attributs (ex.
     * read_only) de la définition portée par une table ancêtre : ne
     * conserver que la définition la plus spécifique évite qu'une définition
     * héritée, moins restrictive, ne rende à tort un champ modifiable.
     *
     * @param  array<int, array<string, mixed>>  $fields  ordonnés de la table
     *     la plus générale à la plus spécifique (cf. tri par rang dans
     *     fields())
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateByElement(array $fields): array
    {
        $byElement = [];

        foreach ($fields as $field) {
            $byElement[$field['element']] = $field;
        }

        return array_values($byElement);
    }

    /**
     * EX-304 : chaîne d'héritage d'une table, de la plus générale à la table
     * interrogée (ex. ['task', 'incident']). Vide si la table est inconnue.
     *
     * Résolue entièrement en mémoire depuis tableCatalog() : plus aucun appel
     * réseau par niveau d'héritage, quelle que soit la profondeur de la
     * chaîne.
     *
     * @return array<int, string>
     */
    public function inheritanceChain(string $table): array
    {
        return $this->remember('chain:'.$table, function () use ($table): array {
            $record = $this->tableRecord($table);

            if ($record === null) {
                return [];
            }

            $chain = [$table];
            $parent = $record['super_class'];

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

                $record = $this->tableRecord($parentName);
                $parent = $record !== null ? $record['super_class'] : null;
            }

            return array_reverse($chain);
        });
    }

    /**
     * @return array{name: string, super_class: string|null}|null
     */
    private function tableRecord(string $table): ?array
    {
        return $this->tableCatalogByName()[$table] ?? null;
    }

    /**
     * Nom technique d'une table à partir de la valeur d'un champ la
     * référençant (`super_class` ou `reference`) : soit la valeur est déjà ce
     * nom, soit c'est un sys_id d'enregistrement sys_db_object, résolu depuis
     * tableCatalog() sans aucun appel réseau.
     */
    private function resolveTableName(string $value): ?string
    {
        if (preg_match(self::SYS_ID, $value) !== 1) {
            return $value;
        }

        return $this->tableCatalogBySysId()[$value] ?? null;
    }

    /**
     * EX-302, EX-303, EX-311 : name, sys_id et super_class de toutes les
     * tables de l'instance, rapatriés en un seul appel et mémorisés comme
     * le reste du dictionnaire. Seule source de tableNames(),
     * tableRecord() et resolveTableName() : la liste des tables, l'existence
     * ou la chaîne d'héritage d'une table quelconque, et la résolution de tout
     * champ `reference` s'y résolvent ensuite sans appel réseau
     * supplémentaire, quel que soit le nombre de tables ou de champs à
     * résoudre.
     *
     * @return array<int, array{sys_id: string|null, name: string, super_class: string|null}>
     */
    private function tableCatalog(): array
    {
        return $this->remember('table-catalog', function (): array {
            $records = $this->connection->fetchAllPages('sys_db_object', [
                'sysparm_fields' => 'sys_id,name,super_class',
                'sysparm_query' => 'ORDERBYname',
            ]);

            return array_values(array_filter(array_map(function (array $record): ?array {
                $name = $this->technicalValue($record['name'] ?? null);

                if ($name === null) {
                    return null;
                }

                return [
                    'sys_id' => $this->technicalValue($record['sys_id'] ?? null),
                    'name' => $name,
                    'super_class' => $this->technicalValue($record['super_class'] ?? null),
                ];
            }, $records)));
        });
    }

    /**
     * Index de tableCatalog() par nom, calculé en mémoire à partir de son
     * seul résultat déjà mis en cache : pas de clé de cache applicative
     * propre, qui ne ferait que dupliquer les mêmes données.
     *
     * @return array<string, array{name: string, super_class: string|null}>
     */
    private function tableCatalogByName(): array
    {
        if (! array_key_exists('table-catalog-by-name', $this->memo)) {
            $byName = [];

            foreach ($this->tableCatalog() as $entry) {
                $byName[$entry['name']] = ['name' => $entry['name'], 'super_class' => $entry['super_class']];
            }

            $this->memo['table-catalog-by-name'] = $byName;
        }

        return $this->memo['table-catalog-by-name'];
    }

    /**
     * Index de tableCatalog() par sys_id, même principe que
     * tableCatalogByName().
     *
     * @return array<string, string>
     */
    private function tableCatalogBySysId(): array
    {
        if (! array_key_exists('table-catalog-by-sys-id', $this->memo)) {
            $bySysId = [];

            foreach ($this->tableCatalog() as $entry) {
                if ($entry['sys_id'] !== null) {
                    $bySysId[$entry['sys_id']] = $entry['name'];
                }
            }

            $this->memo['table-catalog-by-sys-id'] = $bySysId;
        }

        return $this->memo['table-catalog-by-sys-id'];
    }

    /**
     * Noms techniques des types de champs de l'instance (sys_glide_object),
     * indexés par sys_id — nécessaire lorsque `internal_type` est renvoyé
     * sous forme de sys_id plutôt que de nom technique. Une seule requête
     * pour toute l'instance (une centaine d'enregistrements), mémorisée.
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
     * @return array{table: string, element: string, internal_type: string, reference_table: string|null, max_length: int|null, mandatory: bool, read_only: bool, display: bool, virtual: bool, default: string|null, label: string|null}|null
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
            // EX-328 : champ marqué display par le dictionnaire, utilisé pour
            // ordonner $fillable dans les modèles générés (Phase 9).
            'display' => $this->isTrue($record['display'] ?? null),
            // EX-330 : champ virtual (ex. sys_user.name, calculé par un
            // script de calcul plutôt que stocké dans une colonne propre),
            // non marqué read_only par le dictionnaire mais sans aucun
            // stockage où persister une valeur écrite.
            'virtual' => $this->isTrue($record['virtual'] ?? null),
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
     * Mémorisation par instance systématique (cf. $memo).
     */
    private function remember(string $key, Closure $callback): mixed
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $callback();
    }
}

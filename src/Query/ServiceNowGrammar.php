<?php

namespace Quatrebarbes\SnowDriver\Query;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowUnsupportedQueryException;

/**
 * Traduit un query builder Eloquent vers les paramètres de l'API Table de
 * ServiceNow (sysparm_query, sysparm_limit/offset, tri) au lieu de compiler
 * du SQL. Le résultat n'est pas exécutable tel quel : il est décodé par
 * ServiceNowConnection::select(), qui l'exécute via TableApiClient.
 *
 * EX-128 : toute clause sans équivalent dans la syntaxe ServiceNow (join,
 * groupBy, having, union, lock, distinct, agrégats, sous-requêtes, wheres
 * imbriqués, comparaison de colonnes, etc.) lève ServiceNowUnsupportedQueryException
 * plutôt que d'être ignorée ou mal traduite.
 */
class ServiceNowGrammar extends Grammar
{
    /**
     * EX-108, EX-109, EX-110, EX-111 : compile la requête en un tableau
     * (table, sysparm_query, sysparm_fields, limit, offset) sérialisé en
     * JSON, seul format transporté jusqu'à Connection::select().
     */
    public function compileSelect(Builder $query)
    {
        $this->guardAgainstUnsupportedClauses($query);

        if ($this->isCountAggregate($query)) {
            return $this->compileCount($query);
        }

        $sysparmQuery = $this->compileWheres($query);

        $orderBy = $this->compileServiceNowOrders($query->orders ?? []);

        if ($orderBy !== '') {
            $sysparmQuery .= ($sysparmQuery !== '' ? '^' : '').$orderBy;
        }

        $columns = $query->columns;
        $fields = ($columns === null || $columns === ['*']) ? null : array_map(
            fn ($column) => $this->columnName($column),
            $columns
        );

        return json_encode([
            'table' => $query->from,
            'query' => $sysparmQuery,
            'fields' => $fields,
            'limit' => $query->limit,
            'offset' => $query->offset ?? 0,
        ]);
    }

    private function guardAgainstUnsupportedClauses(Builder $query): void
    {
        if (! empty($query->joins)) {
            throw ServiceNowUnsupportedQueryException::forClause('jointure (join)');
        }

        if (! empty($query->groups)) {
            throw ServiceNowUnsupportedQueryException::forClause('regroupement (groupBy)');
        }

        if (! empty($query->havings)) {
            throw ServiceNowUnsupportedQueryException::forClause('clause having');
        }

        if (! empty($query->unions)) {
            throw ServiceNowUnsupportedQueryException::forClause('union');
        }

        if ($query->lock) {
            throw ServiceNowUnsupportedQueryException::forClause('verrouillage de lecture (lockForUpdate/sharedLock)');
        }

        if ($query->distinct) {
            throw ServiceNowUnsupportedQueryException::forClause('distinct');
        }

        // EX-314 : le comptage fait exception, l'API ServiceNow exposant une
        // fonction d'agrégation dédiée. Les autres agrégats restent sans
        // équivalent exploitable ici et continuent de relever d'EX-128.
        if ($query->aggregate && ! $this->isCountAggregate($query)) {
            throw ServiceNowUnsupportedQueryException::forClause(sprintf(
                "fonction d'agrégation \"%s\" (seul le comptage est supporté)",
                is_string($query->aggregate['function'] ?? null) ? $query->aggregate['function'] : 'inconnue'
            ));
        }

        // Un comptage de colonne (count('colonne')) exclurait en SQL les
        // valeurs nulles : la fonction d'agrégation de l'API ServiceNow ne
        // compte que des enregistrements, la traduction serait donc fausse
        // dès que la colonne comporte des valeurs vides (EX-128).
        if ($this->isCountAggregate($query) && ($query->aggregate['columns'] ?? ['*']) !== ['*']) {
            throw ServiceNowUnsupportedQueryException::forClause(
                "comptage des valeurs renseignées d'une colonne (count(colonne)), l'API ServiceNow ne comptant que des enregistrements"
            );
        }

        if (! is_string($query->from)) {
            throw ServiceNowUnsupportedQueryException::forClause('table dérivée d\'une sous-requête ou d\'une expression');
        }
    }

    /**
     * EX-314, EX-315 : un comptage se compile en une requête portant la même
     * traduction de filtres qu'une lecture (compileWheres), mais marquée comme
     * agrégat : ServiceNowConnection::select() l'exécute alors via la fonction
     * d'agrégation de l'API ServiceNow, sans rapatrier d'enregistrement.
     *
     * Ni le tri (retiré par Eloquent lui-même sur un agrégat), ni la limite,
     * ni le décalage ne sont transportés : ils n'ont pas de sens pour un
     * comptage, dont le résultat porte sur l'ensemble des enregistrements
     * correspondant aux filtres.
     */
    private function compileCount(Builder $query): string
    {
        return json_encode([
            'table' => $query->from,
            'query' => $this->compileWheres($query),
            'aggregate' => 'count',
        ]);
    }

    /**
     * EX-131 : une mise à jour de masse via le query builder
     * (Model::where(...)->update([...])) n'a pas d'équivalent atomique côté
     * API Table ServiceNow (qui n'opère que sur un enregistrement identifié
     * par son sys_id) ; elle est rejetée explicitement ici, avant tout appel
     * réseau, plutôt que d'échouer plus loin en erreur PHP de bas niveau sur
     * un pseudo-PDO (ServiceNowConnection::establishConnection() ne renvoie
     * pas un vrai PDO). Les mises à jour passent par une instance chargée
     * (ServiceNowModel::performUpdate()), qui ne compile jamais cette
     * méthode.
     */
    public function compileUpdate(Builder $query, array $values)
    {
        throw ServiceNowUnsupportedQueryException::forClause(
            'mise à jour de masse via le query builder (Model::where(...)->update(...)) : chaque modification doit passer par une instance chargée (save()/update() sur un modèle)'
        );
    }

    /**
     * EX-131 : même garde-fou que compileUpdate(), pour la suppression de
     * masse via le query builder (Model::where(...)->delete()).
     */
    public function compileDelete(Builder $query)
    {
        throw ServiceNowUnsupportedQueryException::forClause(
            'suppression de masse via le query builder (Model::where(...)->delete()) : chaque suppression doit passer par une instance chargée (delete() sur un modèle)'
        );
    }

    /**
     * EX-317 : le test d'existence se compile en une lecture bornée à un
     * enregistrement, sans comptage.
     *
     * La grammaire de base emballerait la requête compilée dans un
     * `select exists(...) as exists` : du SQL, que ServiceNowConnection::select()
     * ne saurait pas décoder. La surcharge produit à la place la même
     * structure JSON que toute autre lecture, marquée comme test d'existence.
     */
    public function compileExists(Builder $query)
    {
        return json_encode([
            'table' => $query->from,
            'query' => $this->compileWheres($query),
            'exists' => true,
        ]);
    }

    /**
     * Un agrégat `count`, quelle que soit la colonne comptée : l'API
     * ServiceNow ne compte que des enregistrements, jamais les valeurs non
     * nulles d'une colonne donnée (cf. limite documentée côté SFD).
     */
    private function isCountAggregate(Builder $query): bool
    {
        return is_array($query->aggregate)
            && strtolower((string) ($query->aggregate['function'] ?? '')) === 'count';
    }

    /**
     * EX-109 : traduction des where() en syntaxe encodée ServiceNow.
     */
    public function compileWheres(Builder $query)
    {
        $segments = [];

        foreach ($query->wheres ?? [] as $where) {
            $segment = $this->compileWhere($where);
            $boolean = strtolower($where['boolean'] ?? 'and');

            $segments[] = $segments === [] ? $segment : ($boolean === 'or' ? '^OR' : '^').$segment;
        }

        return implode('', $segments);
    }

    private function compileWhere(array $where): string
    {
        return match ($where['type'] ?? null) {
            'Basic', 'Date', 'Time', 'Day', 'Month', 'Year' => $this->compileBasicWhere(
                $this->columnName($where['column']),
                $where['operator'],
                $where['value']
            ),
            'Like' => $this->compileLikeWhere($where),
            'In' => $this->compileInWhere($this->columnName($where['column']), $where['values'], false),
            'NotIn' => $this->compileInWhere($this->columnName($where['column']), $where['values'], true),
            'Null' => $this->columnName($where['column']).'ISEMPTY',
            'NotNull' => $this->columnName($where['column']).'ISNOTEMPTY',
            'between' => $this->compileBetweenWhere($where),
            default => throw ServiceNowUnsupportedQueryException::forClause(sprintf(
                'clause where de type "%s" sur la colonne "%s"',
                $where['type'] ?? 'inconnu',
                is_string($where['column'] ?? null) ? $where['column'] : '?'
            )),
        };
    }

    private function compileBasicWhere(string $column, string $operator, mixed $value): string
    {
        $operator = strtolower($operator);

        if (in_array($operator, ['like', 'not like'], true)) {
            $needle = $this->formatValue(is_string($value) ? trim($value, '%') : $value);

            return $column.($operator === 'like' ? 'LIKE' : 'NOT LIKE').$needle;
        }

        $mapped = match ($operator) {
            '=' => '=',
            '!=', '<>' => '!=',
            '>' => '>',
            '>=' => '>=',
            '<' => '<',
            '<=' => '<=',
            default => throw ServiceNowUnsupportedQueryException::forClause(
                "opérateur \"{$operator}\" sans équivalent dans sysparm_query"
            ),
        };

        return $column.$mapped.$this->formatValue($value);
    }

    private function compileLikeWhere(array $where): string
    {
        if (! empty($where['caseSensitive'])) {
            throw ServiceNowUnsupportedQueryException::forClause('comparaison "like" sensible à la casse');
        }

        $operator = empty($where['not']) ? 'LIKE' : 'NOT LIKE';
        $value = is_string($where['value']) ? trim($where['value'], '%') : $where['value'];

        return $this->columnName($where['column']).$operator.$this->formatValue($value);
    }

    private function compileInWhere(string $column, array $values, bool $not): string
    {
        $operator = $not ? 'NOT IN' : 'IN';

        $formatted = implode(',', array_map(fn ($value) => $this->formatValue($value), $values));

        return $column.$operator.$formatted;
    }

    private function compileBetweenWhere(array $where): string
    {
        if (! empty($where['not'])) {
            throw ServiceNowUnsupportedQueryException::forClause(
                'whereNotBetween (aucun opérateur de regroupement disponible dans sysparm_query pour exprimer cette négation)'
            );
        }

        $column = $this->columnName($where['column']);
        [$min, $max] = [$where['values'][0], $where['values'][1]];

        return $column.'>='.$this->formatValue($min).'^'.$column.'<='.$this->formatValue($max);
    }

    /**
     * EX-111 : traduction de orderBy() en directives de tri ServiceNow.
     * L'API Table de ServiceNow n'expose pas de paramètre sysparm_order_by
     * séparé : le tri s'exprime en ajoutant des directives ORDERBY /
     * ORDERBYDESC à la fin de sysparm_query.
     *
     * @param  array<int, array<string, mixed>>  $orders
     */
    private function compileServiceNowOrders(array $orders): string
    {
        $segments = [];

        foreach ($orders as $order) {
            if (! isset($order['column'], $order['direction']) || ! is_string($order['column'])) {
                throw ServiceNowUnsupportedQueryException::forClause(
                    'clause orderBy sans équivalent dans sysparm_query (tri par expression brute ou par liste ordonnée de valeurs)'
                );
            }

            $column = $this->columnName($order['column']);

            $segments[] = strtolower($order['direction']) === 'desc'
                ? 'ORDERBYDESC'.$column
                : 'ORDERBY'.$column;
        }

        return implode('^', $segments);
    }

    /**
     * Une colonne qualifiée par Eloquent (ex. "incidents.sys_id", ajouté par
     * whereKey()/find()) est ramenée au nom de champ ServiceNow attendu par
     * sysparm_query, qui ignore le préfixe de table.
     */
    private function columnName(mixed $column): string
    {
        if (! is_string($column)) {
            throw ServiceNowUnsupportedQueryException::forClause(
                'colonne de requête non textuelle (expression brute ou sous-requête)'
            );
        }

        $position = strrpos($column, '.');

        return $position === false ? $column : substr($column, $position + 1);
    }

    private function formatValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}

<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Query;

use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowUnsupportedQueryException;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-109, EX-110, EX-111 : traduction des clauses du query builder Eloquent
 * vers les paramètres sysparm_* de l'API Table de ServiceNow.
 * EX-128 : clauses sans équivalent -> exception dédiée.
 */
class ServiceNowGrammarTest extends TestCase
{
    private function newQuery()
    {
        return (new ServiceNowConnection('', '', []))->query()->from('incidents');
    }

    private function compile($builder): array
    {
        return json_decode($builder->toSql(), true);
    }

    public function test_a_basic_where_is_translated_to_sysparm_query(): void
    {
        // EX-109
        $compiled = $this->compile($this->newQuery()->where('active', '=', 'true'));

        $this->assertSame('active=true', $compiled['query']);
    }

    public function test_multiple_wheres_are_joined_with_and_or_or_markers(): void
    {
        // EX-109
        $compiled = $this->compile(
            $this->newQuery()
                ->where('active', '=', 'true')
                ->where('priority', '=', '1')
                ->orWhere('urgency', '=', '1')
        );

        $this->assertSame('active=true^priority=1^ORurgency=1', $compiled['query']);
    }

    public function test_where_in_and_where_not_in_are_translated(): void
    {
        // EX-109
        $this->assertSame(
            'priorityIN1,2,3',
            $this->compile($this->newQuery()->whereIn('priority', [1, 2, 3]))['query']
        );

        $this->assertSame(
            'priorityNOT IN1,2',
            $this->compile($this->newQuery()->whereNotIn('priority', [1, 2]))['query']
        );
    }

    public function test_where_null_and_where_not_null_are_translated_to_isempty(): void
    {
        // EX-109
        $this->assertSame(
            'assigned_toISEMPTY',
            $this->compile($this->newQuery()->whereNull('assigned_to'))['query']
        );

        $this->assertSame(
            'assigned_toISNOTEMPTY',
            $this->compile($this->newQuery()->whereNotNull('assigned_to'))['query']
        );
    }

    public function test_where_between_is_translated_to_a_conjunction_of_bounds(): void
    {
        // EX-109
        $compiled = $this->compile($this->newQuery()->whereBetween('priority', [1, 3]));

        $this->assertSame('priority>=1^priority<=3', $compiled['query']);
    }

    public function test_where_not_between_throws_because_it_cannot_be_expressed_without_grouping(): void
    {
        // EX-128
        $this->expectException(ServiceNowUnsupportedQueryException::class);

        $this->compile($this->newQuery()->whereNotBetween('priority', [1, 3]));
    }

    public function test_like_and_not_like_are_translated_with_wildcards_stripped(): void
    {
        // EX-109
        $this->assertSame(
            'short_descriptionLIKEnetwork',
            $this->compile($this->newQuery()->where('short_description', 'like', '%network%'))['query']
        );

        $this->assertSame(
            'short_descriptionNOT LIKEnetwork',
            $this->compile($this->newQuery()->where('short_description', 'not like', '%network%'))['query']
        );
    }

    public function test_an_unsupported_operator_throws_a_dedicated_exception(): void
    {
        // EX-128
        $this->expectException(ServiceNowUnsupportedQueryException::class);

        $this->compile($this->newQuery()->where('short_description', 'rlike', 'network'));
    }

    public function test_a_qualified_column_from_find_or_where_key_has_its_table_prefix_stripped(): void
    {
        // find()/whereKey() qualifient la colonne avec le nom de la table
        // (ex. "incidents.sys_id"), sans équivalent côté ServiceNow.
        $compiled = $this->compile($this->newQuery()->where('incidents.sys_id', '=', 'abc123'));

        $this->assertSame('sys_id=abc123', $compiled['query']);
    }

    public function test_limit_and_offset_are_translated_and_marked_as_explicit(): void
    {
        // EX-110
        $compiled = $this->compile($this->newQuery()->limit(50)->offset(100));

        $this->assertSame(50, $compiled['limit']);
        $this->assertSame(100, $compiled['offset']);
    }

    public function test_no_explicit_limit_is_preserved_as_null_for_automatic_pagination(): void
    {
        // EX-110, EX-122
        $compiled = $this->compile($this->newQuery());

        $this->assertNull($compiled['limit']);
        $this->assertSame(0, $compiled['offset']);
    }

    public function test_order_by_ascending_and_descending_are_translated(): void
    {
        // EX-111
        $compiled = $this->compile($this->newQuery()->orderBy('number')->orderByDesc('priority'));

        $this->assertSame('ORDERBYnumber^ORDERBYDESCpriority', $compiled['query']);
    }

    public function test_order_by_is_appended_after_the_where_clauses(): void
    {
        // EX-111
        $compiled = $this->compile($this->newQuery()->where('active', '=', 'true')->orderBy('number'));

        $this->assertSame('active=true^ORDERBYnumber', $compiled['query']);
    }

    public function test_a_nested_where_group_throws_a_dedicated_exception(): void
    {
        // EX-128 : sysparm_query n'a pas d'opérateur de regroupement générique.
        $this->expectException(ServiceNowUnsupportedQueryException::class);

        $this->compile($this->newQuery()->where(function ($query) {
            $query->where('active', '=', 'true')->orWhere('priority', '=', '1');
        }));
    }

    public function test_a_join_clause_throws_a_dedicated_exception(): void
    {
        // EX-128
        $this->expectException(ServiceNowUnsupportedQueryException::class);

        $this->compile($this->newQuery()->join('sys_user', 'incidents.caller_id', '=', 'sys_user.sys_id'));
    }

    public function test_a_group_by_clause_throws_a_dedicated_exception(): void
    {
        // EX-128
        $this->expectException(ServiceNowUnsupportedQueryException::class);

        $this->compile($this->newQuery()->groupBy('priority'));
    }

    public function test_a_count_aggregate_is_compiled_with_its_filters(): void
    {
        // EX-314, EX-315 : le comptage est compilé comme agrégat, en
        // conservant la traduction des filtres de la lecture.
        $query = $this->newQuery()->where('active', '=', 'true');
        $query->aggregate = ['function' => 'count', 'columns' => ['*']];

        $compiled = $this->compile($query);

        $this->assertSame('count', $compiled['aggregate']);
        $this->assertSame('active=true', $compiled['query']);
        $this->assertSame('incidents', $compiled['table']);
    }

    public function test_a_count_aggregate_carries_neither_limit_nor_order(): void
    {
        // EX-314 : un comptage porte sur l'ensemble des enregistrements
        // correspondant aux filtres ; limite, décalage et tri n'y ont pas de sens.
        $query = $this->newQuery()->orderBy('number')->limit(10)->offset(20);
        $query->aggregate = ['function' => 'count', 'columns' => ['*']];

        $compiled = $this->compile($query);

        $this->assertSame(['table', 'query', 'aggregate'], array_keys($compiled));
        $this->assertSame('', $compiled['query']);
    }

    public function test_an_aggregate_other_than_count_throws_a_dedicated_exception(): void
    {
        // EX-128 : somme, moyenne, minimum et maximum restent sans équivalent
        // exploitable, seul le comptage fait exception (EX-314).
        $query = $this->newQuery()->where('active', '=', 'true');
        $query->aggregate = ['function' => 'sum', 'columns' => ['priority']];

        $this->expectException(ServiceNowUnsupportedQueryException::class);

        $this->compile($query);
    }

    public function test_counting_a_single_column_throws_a_dedicated_exception(): void
    {
        // EX-128 : count('colonne') exclurait les valeurs nulles en SQL, ce
        // que la fonction d'agrégation de l'API ServiceNow ne sait pas faire.
        $query = $this->newQuery();
        $query->aggregate = ['function' => 'count', 'columns' => ['assigned_to']];

        $this->expectException(ServiceNowUnsupportedQueryException::class);

        $this->compile($query);
    }

    public function test_an_exists_query_is_compiled_as_a_bounded_read(): void
    {
        // EX-317 : le test d'existence ne compile ni agrégat ni SQL
        // `select exists(...)`, mais la même structure JSON qu'une lecture.
        $query = $this->newQuery()->where('sys_id', '=', 'abc123');

        $compiled = json_decode($query->getGrammar()->compileExists($query), true);

        $this->assertTrue($compiled['exists']);
        $this->assertSame('sys_id=abc123', $compiled['query']);
        $this->assertSame('incidents', $compiled['table']);
    }
}

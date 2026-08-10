<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowUnsupportedQueryException;
use Quatrebarbes\SnowDriver\Tests\Fixtures\Incident;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-108 à EX-111, EX-122, EX-128, EX-132 : lecture des enregistrements au
 * travers des méthodes standards Eloquent (all(), get(), find(), first())
 * via l'API Table de ServiceNow.
 */
class ServiceNowReadTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.servicenow.base_url', 'https://dev12345.service-now.com');
        $app['config']->set('database.connections.servicenow.auth', [
            'mode' => 'basic',
            'username' => 'alice',
            'password' => 'secret',
        ]);
    }

    public function test_all_sends_a_get_request_to_the_table_api(): void
    {
        // EX-108
        $this->fakeEmptyDictionary();

        Http::fake([
            '*/api/now/table/incidents*' => Http::response(['result' => [
                ['sys_id' => 'abc123'],
            ]], 200),
        ]);

        $incidents = Incident::all();

        $this->assertCount(1, $incidents);
        $this->assertSame('abc123', $incidents->first()->sys_id);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/api/now/table/incidents'));
    }

    public function test_where_clauses_are_translated_into_sysparm_query(): void
    {
        // EX-109
        Http::fake(['*' => Http::response(['result' => []], 200)]);

        Incident::where('active', '=', 'true')->where('priority', '=', '1')->get();

        Http::assertSent(fn ($request) => ($request['sysparm_query'] ?? null) === 'active=true^priority=1');
    }

    public function test_find_issues_a_single_request_with_an_explicit_limit_of_one(): void
    {
        // EX-108 : find() se traduit par une limite explicite (un seul appel, cf. EX-122)
        $this->fakeEmptyDictionary();

        Http::fake(['*' => Http::response(['result' => [['sys_id' => 'abc123']]], 200)]);

        $incident = Incident::find('abc123');

        $this->assertNotNull($incident);

        // EX-132 : +1 requête, la lecture interrogeant aussi le dictionnaire
        // (sys_db_object) pour la conversion de type des enregistrements lus.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => ($request['sysparm_query'] ?? null) === 'sys_id=abc123'
            && ($request['sysparm_limit'] ?? null) === 1);
    }

    public function test_order_by_and_explicit_limit_offset_are_translated_into_request_parameters(): void
    {
        // EX-110, EX-111
        Http::fake(['*' => Http::response(['result' => []], 200)]);

        Incident::orderBy('number')->skip(10)->take(5)->get();

        Http::assertSent(fn ($request) => ($request['sysparm_query'] ?? null) === 'ORDERBYnumber'
            && ($request['sysparm_limit'] ?? null) === 5
            && ($request['sysparm_offset'] ?? null) === 10);
    }

    public function test_get_without_an_explicit_limit_paginates_automatically_across_several_requests(): void
    {
        // EX-122 : enchaînement transparent des pages tant que l'API renvoie une page pleine.
        $this->app['config']->set('servicenow.pagination.page_size', 2);

        $this->fakeEmptyDictionary();

        Http::fake([
            '*' => function ($request) {
                $offset = (int) ($request['sysparm_offset'] ?? 0);

                $page = $offset === 0
                    ? [['sys_id' => '1'], ['sys_id' => '2']]
                    : [['sys_id' => '3']];

                return Http::response(['result' => $page], 200);
            },
        ]);

        $incidents = Incident::all();

        $this->assertCount(3, $incidents);

        // EX-132 : +1 requête (dictionnaire), un seul appel quel que soit le
        // nombre de pages : la coercion s'applique une fois par select(), pas
        // par page interne à fetchAllPages().
        Http::assertSentCount(3);
    }

    public function test_take_with_an_explicit_limit_beyond_the_page_size_issues_a_single_request(): void
    {
        // EX-122 : une limite explicite ne déclenche jamais l'enchaînement automatique.
        $this->app['config']->set('servicenow.pagination.page_size', 2);

        $this->fakeEmptyDictionary();

        Http::fake(['*' => Http::response(['result' => array_fill(0, 5, ['sys_id' => 'x'])], 200)]);

        Incident::take(5)->get();

        // EX-132 : +1 requête (dictionnaire).
        Http::assertSentCount(2);
    }

    public function test_an_unsupported_query_clause_throws_before_any_request_is_sent(): void
    {
        // EX-128
        Http::fake();

        try {
            Incident::where(fn ($query) => $query->where('active', '=', 'true')->orWhere('priority', '=', '1'))->get();
            $this->fail('Une exception ServiceNowUnsupportedQueryException était attendue.');
        } catch (ServiceNowUnsupportedQueryException) {
            Http::assertNothingSent();
        }
    }

    public function test_boolean_integer_and_decimal_fields_are_coerced_to_native_php_types(): void
    {
        // EX-132
        $this->fakeDictionary([
            ['element' => 'active', 'internal_type' => 'boolean'],
            ['element' => 'priority', 'internal_type' => 'integer'],
            ['element' => 'business_impact', 'internal_type' => 'decimal'],
        ]);

        Http::fake([
            '*/api/now/table/incidents*' => Http::response(['result' => [[
                'sys_id' => 'abc123',
                'active' => 'false',
                'priority' => '3',
                'business_impact' => '4.5',
                // EX-132, limite : un champ absent du dictionnaire traverse
                // la conversion sans modification.
                'number' => 'INC0000123',
            ]]], 200),
        ]);

        $incident = Incident::first();

        $this->assertFalse($incident->active);
        $this->assertSame(3, $incident->priority);
        $this->assertSame(4.5, $incident->business_impact);
        $this->assertSame('INC0000123', $incident->number);
    }

    public function test_a_null_boolean_field_stays_null_rather_than_becoming_false(): void
    {
        // EX-132 : même convention que l'accessor/mutator booléen généré dans les modèles.
        $this->fakeDictionary([
            ['element' => 'active', 'internal_type' => 'boolean'],
        ]);

        Http::fake([
            '*/api/now/table/incidents*' => Http::response(['result' => [[
                'sys_id' => 'abc123',
                'active' => null,
            ]]], 200),
        ]);

        $this->assertNull(Incident::first()->active);
    }

    public function test_an_empty_integer_or_decimal_field_becomes_null_rather_than_zero(): void
    {
        // EX-132 : une chaîne vide n'est pas une valeur numérique exploitable,
        // à la différence d'un pilote SQL qui renverrait NULL pour ce champ.
        $this->fakeDictionary([
            ['element' => 'priority', 'internal_type' => 'integer'],
            ['element' => 'business_impact', 'internal_type' => 'decimal'],
        ]);

        Http::fake([
            '*/api/now/table/incidents*' => Http::response(['result' => [[
                'sys_id' => 'abc123',
                'priority' => '',
                'business_impact' => '',
            ]]], 200),
        ]);

        $incident = Incident::first();

        $this->assertNull($incident->priority);
        $this->assertNull($incident->business_impact);
    }

    public function test_an_inaccessible_dictionary_does_not_prevent_reading_records(): void
    {
        // EX-132, limite : un dictionnaire inaccessible (droits insuffisants)
        // ne doit pas empêcher la lecture des enregistrements, seulement leur
        // conversion de type.
        Http::fake([
            '*/api/now/table/sys_db_object*' => Http::response(
                ['error' => ['message' => 'Insufficient rights']],
                403
            ),
            '*/api/now/table/incidents*' => Http::response(['result' => [[
                'sys_id' => 'abc123',
                'active' => 'false',
            ]]], 200),
        ]);

        $incident = Incident::first();

        $this->assertNotNull($incident);
        $this->assertSame('false', $incident->active);
    }

    /**
     * @param  array<int, array{element: string, internal_type: string}>  $fields
     */
    private function fakeDictionary(array $fields): void
    {
        Http::fake([
            '*/api/now/table/sys_db_object*' => Http::response(['result' => [
                ['name' => 'incidents', 'super_class' => ''],
            ]], 200),
            '*/api/now/table/sys_dictionary*' => Http::response(['result' => array_map(
                fn (array $field) => [
                    'name' => 'incidents',
                    'element' => $field['element'],
                    'internal_type' => $field['internal_type'],
                    'reference' => '',
                    'max_length' => '40',
                    'mandatory' => 'false',
                    'read_only' => 'false',
                    'default_value' => '',
                    'column_label' => $field['element'],
                ],
                $fields
            )], 200),
        ]);
    }
}

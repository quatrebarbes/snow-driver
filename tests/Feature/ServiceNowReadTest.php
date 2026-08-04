<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowUnsupportedQueryException;
use Quatrebarbes\SnowDriver\Tests\Fixtures\Incident;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-108 à EX-111, EX-122, EX-128 : lecture des enregistrements au travers
 * des méthodes standards Eloquent (all(), get(), find(), first()) via
 * l'API Table de ServiceNow.
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
        Http::fake(['*' => Http::response(['result' => [['sys_id' => 'abc123']]], 200)]);

        $incident = Incident::find('abc123');

        $this->assertNotNull($incident);

        Http::assertSentCount(1);
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
        Http::assertSentCount(2);
    }

    public function test_take_with_an_explicit_limit_beyond_the_page_size_issues_a_single_request(): void
    {
        // EX-122 : une limite explicite ne déclenche jamais l'enchaînement automatique.
        $this->app['config']->set('servicenow.pagination.page_size', 2);

        Http::fake(['*' => Http::response(['result' => array_fill(0, 5, ['sys_id' => 'x'])], 200)]);

        Incident::take(5)->get();

        Http::assertSentCount(1);
    }

    public function test_an_unsupported_query_clause_throws_before_any_request_is_sent(): void
    {
        // EX-128
        Http::fake();

        try {
            Incident::where(fn ($query) => $query->where('active', '=', 'true')->orWhere('priority', '=', '1'))->get();
            $this->fail('Une exception ServiceNowUnsupportedQueryException était attendue.');
        } catch (ServiceNowUnsupportedQueryException $e) {
            Http::assertNothingSent();
        }
    }
}

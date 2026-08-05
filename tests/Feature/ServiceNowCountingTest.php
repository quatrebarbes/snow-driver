<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowMalformedResponseException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowUnsupportedQueryException;
use Quatrebarbes\SnowDriver\Tests\Fixtures\Incident;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-314 à EX-317 : comptage des enregistrements sans rapatriement (fonction
 * d'agrégation de l'API ServiceNow), pagination complète et test d'existence.
 */
class ServiceNowCountingTest extends TestCase
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

    public function test_count_uses_the_aggregate_api_without_fetching_records(): void
    {
        // EX-314
        $this->fakeCount(42);

        $this->assertSame(42, Incident::count());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/now/stats/incidents')
            && ($request['sysparm_count'] ?? null) === 'true');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/now/table/incidents'));
    }

    public function test_count_carries_the_translated_filters(): void
    {
        // EX-315 : mêmes filtres traduits que pour une lecture (EX-109).
        $this->fakeCount(7);

        $count = Incident::where('active', '=', 'true')->where('priority', '=', '1')->count();

        $this->assertSame(7, $count);

        Http::assertSent(fn ($request) => ($request['sysparm_query'] ?? null) === 'active=true^priority=1');
    }

    public function test_count_omits_an_empty_filter(): void
    {
        // EX-314 : un comptage nu ne porte pas de sysparm_query vide.
        $this->fakeCount(3);

        Incident::count();

        Http::assertSent(fn ($request) => ! array_key_exists('sysparm_query', $request->data()));
    }

    public function test_pagination_reports_the_total_and_the_page_count(): void
    {
        // EX-316
        $this->fakeEmptyDictionary();

        Http::fake([
            '*/api/now/stats/incidents*' => Http::response(['result' => ['stats' => ['count' => '25']]]),
            '*/api/now/table/incidents*' => Http::response(['result' => [
                ['sys_id' => 'abc123'],
                ['sys_id' => 'def456'],
            ]]),
        ]);

        $paginator = Incident::paginate(10);

        $this->assertSame(25, $paginator->total());
        $this->assertSame(3, $paginator->lastPage());
        $this->assertSame(10, $paginator->perPage());
        $this->assertCount(2, $paginator->items());
    }

    public function test_pagination_requests_a_single_page_of_records(): void
    {
        // EX-110, EX-316 : la page demandée correspond à un seul appel borné.
        $this->fakeEmptyDictionary();

        Http::fake([
            '*/api/now/stats/incidents*' => Http::response(['result' => ['stats' => ['count' => '25']]]),
            '*/api/now/table/incidents*' => Http::response(['result' => [['sys_id' => 'abc123']]]),
        ]);

        Incident::paginate(10, ['*'], 'page', 2);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/now/table/incidents')
            && (int) ($request['sysparm_limit'] ?? 0) === 10
            && (int) ($request['sysparm_offset'] ?? -1) === 10);
    }

    public function test_exists_issues_a_single_bounded_request(): void
    {
        // EX-317 : un appel unique borné à un enregistrement, sans comptage.
        Http::fake(['*' => Http::response(['result' => [['sys_id' => 'abc123']]])]);

        $this->assertTrue(Incident::where('sys_id', '=', 'abc123')->exists());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/now/table/incidents')
            && (int) ($request['sysparm_limit'] ?? 0) === 1
            && ($request['sysparm_fields'] ?? null) === 'sys_id');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/now/stats/'));
    }

    public function test_exists_is_false_when_no_record_matches(): void
    {
        // EX-317
        Http::fake(['*' => Http::response(['result' => []])]);

        $this->assertFalse(Incident::where('sys_id', '=', 'inconnu')->exists());
    }

    public function test_does_not_exist_is_the_negation(): void
    {
        // EX-317 : doesntExist() dérive du même mécanisme.
        Http::fake(['*' => Http::response(['result' => []])]);

        $this->assertTrue(Incident::where('sys_id', '=', 'inconnu')->doesntExist());
    }

    public function test_an_aggregate_other_than_count_is_rejected(): void
    {
        // EX-128 : seul le comptage fait exception.
        Http::fake(['*' => Http::response(['result' => []])]);

        $this->expectException(ServiceNowUnsupportedQueryException::class);

        Incident::sum('priority');
    }

    public function test_an_aggregate_response_without_counter_is_rejected(): void
    {
        // EX-314, EX-130 : jamais de repli sur 0, qui serait trompeur.
        Http::fake(['*/api/now/stats/*' => Http::response(['result' => ['stats' => []]])]);

        $this->expectException(ServiceNowMalformedResponseException::class);

        Incident::count();
    }

    private function fakeCount(int $count): void
    {
        Http::fake([
            '*/api/now/stats/*' => Http::response(['result' => ['stats' => ['count' => (string) $count]]]),
            '*' => Http::response(['result' => []]),
        ]);
    }
}

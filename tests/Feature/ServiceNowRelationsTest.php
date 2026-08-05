<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowAuthenticationException;
use Quatrebarbes\SnowDriver\Tests\Fixtures\Incident;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-116 à EX-118, EX-125, EX-129 : relations entre modèles via champs de
 * référence, chargement différé et anticipé, distinction absence de
 * donnée / droits insuffisants lors de la résolution.
 */
class ServiceNowRelationsTest extends TestCase
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

    public function test_lazy_loading_only_issues_a_request_when_the_relation_is_actually_accessed(): void
    {
        // EX-118 : lazy loading standard d'Eloquent.
        $this->fakeEmptyDictionary();

        Http::fake([
            '*/api/now/table/incidents*' => Http::response(['result' => [
                ['sys_id' => 'inc1', 'company' => 'comp1'],
            ]], 200),
            '*/api/now/table/companies*' => Http::response(['result' => [
                ['sys_id' => 'comp1', 'name' => 'Acme'],
            ]], 200),
        ]);

        $incident = Incident::first();

        // EX-132 : +1 requête (dictionnaire pour la table incidents).
        Http::assertSentCount(2);

        $company = $incident->companyRecord;

        $this->assertSame('Acme', $company->name);

        // EX-132 : +1 requête supplémentaire (dictionnaire pour la table companies).
        Http::assertSentCount(4);
    }

    public function test_eager_loading_via_with_batches_a_single_request_for_all_referenced_records(): void
    {
        // EX-118 : eager loading standard d'Eloquent (with()).
        $this->fakeEmptyDictionary();

        Http::fake([
            '*/api/now/table/incidents*' => Http::response(['result' => [
                ['sys_id' => 'inc1', 'company' => 'comp1'],
                ['sys_id' => 'inc2', 'company' => 'comp2'],
            ]], 200),
            '*/api/now/table/companies*' => Http::response(['result' => [
                ['sys_id' => 'comp1', 'name' => 'Acme'],
                ['sys_id' => 'comp2', 'name' => 'Globex'],
            ]], 200),
        ]);

        $incidents = Incident::with('companyRecord')->get();

        $this->assertSame('Acme', $incidents[0]->companyRecord->name);
        $this->assertSame('Globex', $incidents[1]->companyRecord->name);

        // Une requête pour les incidents, une seule requête groupée pour les
        // deux références (whereIn), pas une par incident. EX-132 : +2
        // requêtes (dictionnaire pour chacune des deux tables).
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/now/table/companies')
            && str_contains((string) ($request['sysparm_query'] ?? ''), 'comp1')
            && str_contains((string) ($request['sysparm_query'] ?? ''), 'comp2'));
    }

    public function test_a_reference_to_a_deleted_record_resolves_to_null(): void
    {
        // EX-125 : sys_id introuvable côté ServiceNow -> relation résolue à null.
        Http::fake([
            '*/api/now/table/companies*' => Http::response(['result' => []], 200),
        ]);

        $incident = new Incident(['sys_id' => 'inc1', 'company' => 'comp-deleted']);

        $this->assertNull($incident->companyRecord);
    }

    public function test_insufficient_access_rights_when_resolving_a_reference_throws_a_dedicated_exception(): void
    {
        // EX-125 : 403 lors de la résolution -> exception dédiée, distincte
        // d'une simple absence de donnée.
        Http::fake([
            '*/api/now/table/companies*' => Http::response(['error' => ['message' => 'Insufficient rights']], 403),
        ]);

        $incident = new Incident(['sys_id' => 'inc1', 'company' => 'comp1']);

        $this->expectException(ServiceNowAuthenticationException::class);

        $incident->companyRecord;
    }
}

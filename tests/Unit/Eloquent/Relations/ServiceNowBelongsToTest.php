<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Eloquent\Relations;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;
use Quatrebarbes\SnowDriver\Eloquent\Relations\ServiceNowBelongsTo;
use Quatrebarbes\SnowDriver\Tests\Fixtures\Company;
use Quatrebarbes\SnowDriver\Tests\Fixtures\Incident;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-116, EX-117, EX-129 : résolution d'une relation belongsTo Eloquent
 * standard basée sur un champ reference ServiceNow.
 */
class ServiceNowBelongsToTest extends TestCase
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

    public function test_belongs_to_declared_with_the_standard_eloquent_syntax_returns_a_dedicated_relation_instance(): void
    {
        // EX-117 : belongsTo() reste la méthode standard Eloquent, sans DSL propriétaire.
        $relation = (new Incident())->companyRecord();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertInstanceOf(ServiceNowBelongsTo::class, $relation);
    }

    public function test_a_reference_field_resolves_the_related_model_in_the_nominal_case(): void
    {
        // EX-116 : cas nominal, la relation est résolue via l'API Table.
        Http::fake([
            '*/api/now/table/companies*' => Http::response(['result' => [
                ['sys_id' => 'comp1', 'name' => 'Acme'],
            ]], 200),
        ]);

        $incident = new Incident(['sys_id' => 'inc1', 'company' => 'comp1']);

        $company = $incident->companyRecord;

        $this->assertInstanceOf(Company::class, $company);
        $this->assertSame('comp1', $company->sys_id);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/api/now/table/companies')
            && ($request['sysparm_query'] ?? null) === 'sys_id=comp1');
    }

    public function test_an_empty_reference_value_resolves_to_null_without_sending_any_request(): void
    {
        // EX-129 : sys_id vide -> relation résolue à null, sans appel réseau.
        Http::fake();

        $incident = new Incident(['sys_id' => 'inc1', 'company' => '']);

        $this->assertNull($incident->companyRecord);

        Http::assertNothingSent();
    }
}

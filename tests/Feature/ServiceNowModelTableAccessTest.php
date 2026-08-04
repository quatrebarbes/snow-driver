<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowApiException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowAuthenticationException;
use Quatrebarbes\SnowDriver\Tests\Fixtures\NonexistentTableModel;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-127 : la résolution d'un modèle vers une table ServiceNow inexistante
 * ou inaccessible doit produire une exception explicite, jamais un résultat
 * vide silencieux.
 */
class ServiceNowModelTableAccessTest extends TestCase
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

    public function test_a_nonexistent_table_throws_a_dedicated_exception_instead_of_an_empty_result(): void
    {
        Http::fake([
            '*/api/now/table/nonexistent_table*' => Http::response(
                ['error' => ['message' => 'Invalid table nonexistent_table']],
                400
            ),
        ]);

        $model = new NonexistentTableModel();

        $this->expectException(ServiceNowApiException::class);

        $model->tableApi()->get('/api/now/table/'.$model->getTable());
    }

    public function test_insufficient_access_rights_on_a_table_throws_a_dedicated_authentication_exception(): void
    {
        Http::fake([
            '*/api/now/table/nonexistent_table*' => Http::response(
                ['error' => ['message' => 'Accès refusé à la table nonexistent_table']],
                403
            ),
        ]);

        $model = new NonexistentTableModel();

        $this->expectException(ServiceNowAuthenticationException::class);

        $model->tableApi()->get('/api/now/table/'.$model->getTable());
    }
}

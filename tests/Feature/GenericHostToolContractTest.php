<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * Module 3, contrat d'ensemble : la séquence d'interrogations qu'un outil hôte
 * générique d'exploration de données adresse à une connexion, sans savoir
 * qu'il ne s'agit pas d'une base SQL. Chacune de ces onze interrogations
 * échouait avant la Phase 9 — les six premières sur une erreur PHP de bas
 * niveau (« Call to a member function compileTables() on null »), le comptage
 * et la pagination sur le garde-fou d'agrégat (EX-128), le test d'existence
 * sur une TypeError.
 *
 * Ce test vaut donc test de non-régression du contrat lui-même : il échoue si
 * l'une de ces capacités disparaît, quel qu'en soit le motif.
 */
class GenericHostToolContractTest extends TestCase
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

    public function test_the_whole_generic_introspection_sequence_answers(): void
    {
        $this->fakeInstance();

        $schema = Schema::connection('servicenow');
        $connection = DB::connection('servicenow');

        // Découverte du schéma (EX-301 à EX-311)
        $this->assertContains('incident', $schema->getTableListing());
        $this->assertTrue($schema->hasTable('incident'));
        $this->assertNotSame([], $schema->getColumns('incident'));
        $this->assertContains('company', $schema->getColumnListing('incident'));
        $this->assertTrue($schema->hasColumn('incident', 'number'));
        $this->assertSame('core_company', $schema->getForeignKeys('incident')[0]['foreign_table']);

        // Dénombrement et pagination du contenu (EX-314 à EX-316)
        $this->assertSame(25, $connection->table('incident')->count());
        $this->assertSame(25, $connection->table('incident')->getCountForPagination());
        $this->assertSame(3, $connection->table('incident')->paginate(10)->lastPage());

        // Navigation par clé étrangère (EX-317)
        $this->assertTrue($connection->table('core_company')->where('sys_id', 'abc123')->exists());

        // Identification du driver, utilisée par les outils hôtes pour adapter
        // leur traitement des erreurs (EX-318 à EX-320)
        $this->assertSame('servicenow', $connection->getDriverName());
    }

    private function fakeInstance(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            $query = $request['sysparm_query'] ?? '';

            if (str_contains($url, '/api/now/stats/')) {
                return Http::response(['result' => ['stats' => ['count' => '25']]]);
            }

            if (str_contains($url, '/api/now/table/sys_db_object')) {
                return Http::response(['result' => match ($query) {
                    'ORDERBYname' => [['name' => 'core_company'], ['name' => 'incident']],
                    'name=incident' => [['name' => 'incident', 'super_class' => '']],
                    'name=core_company' => [['name' => 'core_company', 'super_class' => '']],
                    default => [],
                }]);
            }

            if (str_contains($url, '/api/now/table/sys_dictionary')) {
                return Http::response(['result' => [
                    [
                        'name' => 'incident', 'element' => 'number', 'internal_type' => 'string',
                        'reference' => '', 'max_length' => '40', 'mandatory' => 'true',
                        'read_only' => 'false', 'default_value' => '', 'column_label' => 'Number',
                    ],
                    [
                        'name' => 'incident', 'element' => 'company', 'internal_type' => 'reference',
                        'reference' => 'core_company', 'max_length' => '32', 'mandatory' => 'false',
                        'read_only' => 'false', 'default_value' => '', 'column_label' => 'Company',
                    ],
                ]]);
            }

            return Http::response(['result' => [['sys_id' => 'abc123']]]);
        });
    }
}

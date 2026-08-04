<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Schema;

use Illuminate\Support\Facades\Http;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;
use Quatrebarbes\SnowDriver\Schema\DictionaryReader;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-302, EX-304, EX-321 : lecture du dictionnaire de l'instance
 * (sys_db_object, sys_dictionary), remontée de la chaîne d'héritage et
 * normalisation des valeurs renvoyées par l'API Table.
 */
class DictionaryReaderTest extends TestCase
{
    private function reader(): DictionaryReader
    {
        return new DictionaryReader(new ServiceNowConnection('', '', [
            'driver' => 'servicenow',
            'name' => 'servicenow',
            'base_url' => 'https://dev12345.service-now.com',
            'timeout' => 5,
            'auth' => ['mode' => 'basic', 'username' => 'alice', 'password' => 'secret'],
        ]));
    }

    public function test_it_walks_the_whole_inheritance_chain(): void
    {
        // EX-304 : de la table la plus générale à la table interrogée.
        $this->fakeTables([
            'name=sc_request' => [['name' => 'sc_request', 'super_class' => ['value' => str_repeat('a', 32)]]],
            'sys_id='.str_repeat('a', 32) => [['name' => 'task']],
            'name=task' => [['name' => 'task', 'super_class' => ['value' => str_repeat('b', 32)]]],
            'sys_id='.str_repeat('b', 32) => [['name' => 'sys_metadata']],
            'name=sys_metadata' => [['name' => 'sys_metadata', 'super_class' => '']],
        ]);

        $this->assertSame(
            ['sys_metadata', 'task', 'sc_request'],
            $this->reader()->inheritanceChain('sc_request')
        );
    }

    public function test_a_root_table_has_a_single_element_chain(): void
    {
        // EX-304
        $this->fakeTables([
            'name=core_company' => [['name' => 'core_company', 'super_class' => '']],
        ]);

        $this->assertSame(['core_company'], $this->reader()->inheritanceChain('core_company'));
    }

    public function test_an_unknown_table_has_an_empty_chain(): void
    {
        // EX-305 : une table absente ne lève pas d'exception.
        $this->fakeTables([]);

        $this->assertSame([], $this->reader()->inheritanceChain('ghost_table'));
        $this->assertFalse($this->reader()->tableExists('ghost_table'));
    }

    public function test_a_self_referencing_super_class_does_not_loop(): void
    {
        // Garde-fou : un dictionnaire incohérent ne doit pas boucler à l'infini.
        $this->fakeTables([
            'name=boucle' => [['name' => 'boucle', 'super_class' => ['value' => str_repeat('c', 32)]]],
            'sys_id='.str_repeat('c', 32) => [['name' => 'boucle']],
        ]);

        $this->assertSame(['boucle'], $this->reader()->inheritanceChain('boucle'));
    }

    public function test_it_reads_no_field_for_an_unknown_table(): void
    {
        // EX-304 : sans chaîne d'héritage, aucun champ n'est interrogé.
        $this->fakeTables([]);

        $this->assertSame([], $this->reader()->fields('ghost_table'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sys_dictionary'));
    }

    public function test_it_normalises_the_boolean_flags_of_a_field(): void
    {
        // Un booléen ServiceNow est renvoyé sous forme de chaîne par l'API
        // Table, jamais de booléen JSON natif.
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'sys_dictionary')) {
                return Http::response(['result' => [
                    [
                        'name' => 'core_company', 'element' => 'name', 'internal_type' => 'string',
                        'reference' => '', 'max_length' => '80', 'mandatory' => 'true',
                        'read_only' => '1', 'default_value' => '', 'column_label' => 'Name',
                    ],
                ]]);
            }

            return Http::response(['result' => [['name' => 'core_company', 'super_class' => '']]]);
        });

        $fields = $this->reader()->fields('core_company');

        $this->assertTrue($fields[0]['mandatory']);
        $this->assertTrue($fields[0]['read_only']);
        $this->assertSame(80, $fields[0]['max_length']);
        $this->assertSame('Name', $fields[0]['label']);
    }

    public function test_it_reads_the_dictionary_only_once_for_the_same_question(): void
    {
        // EX-321 : mémoïsation, y compris pour un lecteur unique.
        $this->fakeTables([
            'name=core_company' => [['name' => 'core_company', 'super_class' => '']],
        ]);

        $reader = $this->reader();
        $reader->inheritanceChain('core_company');
        $reader->inheritanceChain('core_company');

        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'sys_db_object')));
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $byQuery
     */
    private function fakeTables(array $byQuery): void
    {
        Http::fake(fn ($request) => Http::response([
            'result' => $byQuery[$request['sysparm_query'] ?? ''] ?? [],
        ]));
    }
}

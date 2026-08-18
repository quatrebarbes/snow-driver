<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Schema;

use Illuminate\Support\Facades\Http;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;
use Quatrebarbes\SnowDriver\Schema\DictionaryReader;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-302, EX-304, EX-311 : lecture du dictionnaire de l'instance
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
            ['sys_id' => str_repeat('s', 32), 'name' => 'sc_request', 'super_class' => ['value' => str_repeat('a', 32)]],
            ['sys_id' => str_repeat('a', 32), 'name' => 'task', 'super_class' => ['value' => str_repeat('b', 32)]],
            ['sys_id' => str_repeat('b', 32), 'name' => 'sys_metadata', 'super_class' => ''],
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
            ['sys_id' => str_repeat('r', 32), 'name' => 'core_company', 'super_class' => ''],
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
            ['sys_id' => str_repeat('c', 32), 'name' => 'boucle', 'super_class' => ['value' => str_repeat('c', 32)]],
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
        $this->fakeTables(
            [['sys_id' => str_repeat('x', 32), 'name' => 'core_company', 'super_class' => '']],
            [
                [
                    'name' => 'core_company', 'element' => 'name', 'internal_type' => 'string',
                    'reference' => '', 'max_length' => '80', 'mandatory' => 'true',
                    'read_only' => '1', 'default_value' => '', 'column_label' => 'Name',
                ],
            ]
        );

        $fields = $this->reader()->fields('core_company');

        $this->assertTrue($fields[0]['mandatory']);
        $this->assertTrue($fields[0]['read_only']);
        $this->assertSame(80, $fields[0]['max_length']);
        $this->assertSame('Name', $fields[0]['label']);
    }

    public function test_it_normalises_the_display_flag_of_a_field(): void
    {
        // EX-328 : champ marqué display par le dictionnaire, normalisé comme
        // les autres booléens ServiceNow (chaîne, jamais booléen JSON natif).
        $this->fakeTables(
            [['sys_id' => str_repeat('x', 32), 'name' => 'core_company', 'super_class' => '']],
            [
                [
                    'name' => 'core_company', 'element' => 'name', 'internal_type' => 'string',
                    'reference' => '', 'max_length' => '80', 'mandatory' => 'false',
                    'read_only' => 'false', 'display' => 'true', 'default_value' => '',
                    'column_label' => 'Name',
                ],
                [
                    'name' => 'core_company', 'element' => 'notes', 'internal_type' => 'string',
                    'reference' => '', 'max_length' => '160', 'mandatory' => 'false',
                    'read_only' => 'false', 'default_value' => '', 'column_label' => 'Notes',
                ],
            ]
        );

        $fields = $this->reader()->fields('core_company');

        $this->assertTrue($fields[0]['display']);
        // Absence du champ display dans la réponse ServiceNow -> repli à false.
        $this->assertFalse($fields[1]['display']);
    }

    public function test_it_normalises_the_virtual_flag_of_a_field(): void
    {
        // EX-330 : un champ calculé (virtual=true) n'est pas nécessairement
        // marqué read_only par le dictionnaire (ex. sys_user.name).
        $this->fakeTables(
            [['sys_id' => str_repeat('x', 32), 'name' => 'sys_user', 'super_class' => '']],
            [
                [
                    'name' => 'sys_user', 'element' => 'name', 'internal_type' => 'string',
                    'reference' => '', 'max_length' => '100', 'mandatory' => 'false',
                    'read_only' => 'false', 'virtual' => 'true', 'default_value' => '',
                    'column_label' => 'Name',
                ],
            ]
        );

        $fields = $this->reader()->fields('sys_user');

        $this->assertFalse($fields[0]['read_only']);
        $this->assertTrue($fields[0]['virtual']);
    }

    public function test_it_resolves_reference_fields_without_any_extra_call(): void
    {
        // EX-311 : la table référencée par un champ reference est lue depuis
        // le catalogue sys_db_object déjà chargé pour la chaîne d'héritage —
        // aucun appel supplémentaire, quel que soit le nombre de champs de
        // référence à résoudre.
        $companySysId = str_repeat('e', 32);
        $userSysId = str_repeat('f', 32);

        $this->fakeTables(
            [
                ['sys_id' => str_repeat('i', 32), 'name' => 'incident', 'super_class' => ''],
                ['sys_id' => $companySysId, 'name' => 'core_company', 'super_class' => ''],
                ['sys_id' => $userSysId, 'name' => 'sys_user', 'super_class' => ''],
            ],
            [
                [
                    'name' => 'incident', 'element' => 'company', 'internal_type' => 'reference',
                    'reference' => ['value' => $companySysId], 'max_length' => '32',
                    'mandatory' => 'false', 'read_only' => 'false', 'default_value' => '',
                    'column_label' => 'Company',
                ],
                [
                    'name' => 'incident', 'element' => 'assigned_to', 'internal_type' => 'reference',
                    'reference' => ['value' => $userSysId], 'max_length' => '32',
                    'mandatory' => 'false', 'read_only' => 'false', 'default_value' => '',
                    'column_label' => 'Assigned to',
                ],
            ]
        );

        $fields = $this->reader()->fields('incident');

        $this->assertSame('core_company', $fields[0]['reference_table']);
        $this->assertSame('sys_user', $fields[1]['reference_table']);

        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/api/now/table/sys_db_object')));
    }

    public function test_a_child_table_dictionary_override_takes_precedence_over_the_inherited_definition(): void
    {
        // Une table enfant peut surcharger, pour un champ hérité, des
        // attributs comme read_only via son propre enregistrement
        // sys_dictionary : seule cette définition la plus spécifique doit
        // être retenue, faute de quoi un champ rendu lecture seule par la
        // surcharge resterait modifiable via la définition héritée.
        $this->fakeTables(
            [
                ['sys_id' => str_repeat('i', 32), 'name' => 'incident', 'super_class' => ['value' => str_repeat('d', 32)]],
                ['sys_id' => str_repeat('d', 32), 'name' => 'task', 'super_class' => ''],
            ],
            [
                [
                    'name' => 'task', 'element' => 'state', 'internal_type' => 'string',
                    'reference' => '', 'max_length' => '40', 'mandatory' => 'false',
                    'read_only' => 'false', 'default_value' => '', 'column_label' => 'State',
                ],
                [
                    'name' => 'incident', 'element' => 'state', 'internal_type' => 'string',
                    'reference' => '', 'max_length' => '40', 'mandatory' => 'false',
                    'read_only' => 'true', 'default_value' => '', 'column_label' => 'State',
                ],
            ]
        );

        $fields = $this->reader()->fields('incident');

        $this->assertCount(1, $fields);
        $this->assertTrue($fields[0]['read_only']);
    }

    public function test_it_reads_the_dictionary_only_once_for_the_same_question(): void
    {
        // Mémorisation par instance : un même DictionaryReader ne réinterroge
        // pas le dictionnaire pour une question déjà posée.
        $this->fakeTables([
            ['sys_id' => str_repeat('r', 32), 'name' => 'core_company', 'super_class' => ''],
        ]);

        $reader = $this->reader();
        $reader->inheritanceChain('core_company');
        $reader->inheritanceChain('core_company');

        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'sys_db_object')));
    }

    /**
     * @param  array<int, array<string, mixed>>  $catalog  enregistrements sys_db_object
     * @param  array<int, array<string, mixed>>  $dictionaryFields  enregistrements sys_dictionary
     */
    private function fakeTables(array $catalog, array $dictionaryFields = []): void
    {
        Http::fake(function ($request) use ($catalog, $dictionaryFields) {
            $url = $request->url();

            if (str_contains($url, '/api/now/table/sys_db_object')) {
                return Http::response(['result' => $catalog]);
            }

            if (str_contains($url, '/api/now/table/sys_dictionary')) {
                return Http::response(['result' => $dictionaryFields]);
            }

            return Http::response(['result' => []]);
        });
    }
}

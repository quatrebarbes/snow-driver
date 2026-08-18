<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowAuthenticationException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowUnsupportedQueryException;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-301 à EX-313, EX-324 : lecture du schéma d'une instance ServiceNow au
 * travers du constructeur de schéma standard de Laravel, comme le ferait un
 * outil hôte générique ne connaissant pas le driver.
 *
 * Le dictionnaire simulé décrit une table `incident` héritant de `task`
 * (EX-304), avec un champ de référence résolvable vers `core_company`
 * (EX-310) et un autre pointant vers une table inconnue (EX-313). Les trois
 * formes sous lesquelles l'API Table peut renvoyer `internal_type` (chaîne
 * brute, sys_id à résoudre, valeur accompagnée de son libellé) y sont
 * représentées.
 */
class ServiceNowSchemaIntrospectionTest extends TestCase
{
    private const TASK_SYS_ID = '11111111111111111111111111111111';

    private const INTEGER_TYPE_SYS_ID = '22222222222222222222222222222222';

    private const COMPANY_SYS_ID = '33333333333333333333333333333333';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.servicenow.base_url', 'https://dev12345.service-now.com');
        $app['config']->set('database.connections.servicenow.auth', [
            'mode' => 'basic',
            'username' => 'alice',
            'password' => 'secret',
        ]);
    }

    public function test_it_lists_the_tables_of_the_instance(): void
    {
        // EX-302
        $this->fakeDictionary();

        $tables = Schema::connection('servicenow')->getTableListing();

        $this->assertSame(['core_company', 'incident', 'task'], $tables);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/now/table/sys_db_object')
            && ($request['sysparm_fields'] ?? null) === 'sys_id,name,super_class');
    }

    public function test_it_reports_an_existing_table(): void
    {
        // EX-303, EX-305
        $this->fakeDictionary();

        $this->assertTrue(Schema::connection('servicenow')->hasTable('incident'));
    }

    public function test_it_reports_a_missing_table_without_raising(): void
    {
        // EX-305 : une table absente est signalée comme telle, pas par une exception.
        $this->fakeDictionary();

        $this->assertFalse(Schema::connection('servicenow')->hasTable('ghost_table'));
    }

    public function test_it_never_reads_records_of_the_inspected_table(): void
    {
        // EX-303 : seul le dictionnaire est interrogé.
        $this->fakeDictionary();

        Schema::connection('servicenow')->getColumns('incident');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/now/table/incident'));
    }

    public function test_it_orders_columns_with_leading_identifiers_then_inherited_order(): void
    {
        // EX-304 : les champs de `task` précèdent ceux d'`incident`, alors que
        // le dictionnaire les renvoie dans l'ordre inverse. EX-334, EX-335 :
        // `number` et `description`, présents sur cette table, sont replacés
        // en tête dans cet ordre fixe ; le reste (`active`, `severity`,
        // `opened_at`, `company`, `orphan_ref`) conserve l'ordre EX-304 entre
        // eux. Aucun champ n'est marqué display dans ce dictionnaire simulé
        // (cf. test dédié à EX-333 ci-dessous).
        $this->fakeDictionary();

        $columns = Schema::connection('servicenow')->getColumnListing('incident');

        $this->assertSame(
            ['number', 'description', 'active', 'severity', 'opened_at', 'company', 'orphan_ref'],
            $columns
        );
    }

    public function test_it_places_the_display_field_before_the_leading_identifier_fields(): void
    {
        // EX-333 : le champ display prime sur l'ordre fixe d'EX-334, même
        // lorsque celui-ci contient déjà, comme ici, le champ number.
        $this->fakeSingleTableDictionary('x_ticket', [
            $this->dictionaryField('x_ticket', 'number', 'string', ['mandatory' => 'true']),
            $this->dictionaryField('x_ticket', 'category', 'string', ['display' => 'true']),
            $this->dictionaryField('x_ticket', 'short_description', 'string'),
        ]);

        $columns = Schema::connection('servicenow')->getColumnListing('x_ticket');

        $this->assertSame(['category', 'number', 'short_description'], $columns);
    }

    public function test_only_the_first_display_field_in_ex304_order_is_placed_first(): void
    {
        // Cas limite (avertissement sous EX-333) : dictionnaire non conforme
        // aux conventions ServiceNow, deux champs marqués display sur la même
        // table. Seul le premier rencontré dans l'ordre EX-304 est placé en
        // tête ; le second reste une colonne ordinaire, replacée par EX-334.
        $this->fakeSingleTableDictionary('x_ticket', [
            $this->dictionaryField('x_ticket', 'category', 'string', ['display' => 'true']),
            $this->dictionaryField('x_ticket', 'subcategory', 'string', ['display' => 'true']),
            $this->dictionaryField('x_ticket', 'number', 'string', ['mandatory' => 'true']),
        ]);

        $columns = Schema::connection('servicenow')->getColumnListing('x_ticket');

        $this->assertSame(['category', 'number', 'subcategory'], $columns);
    }

    public function test_it_types_columns_from_the_dictionary(): void
    {
        // EX-306, EX-308, EX-309
        $this->fakeDictionary();

        $columns = collect(Schema::connection('servicenow')->getColumns('incident'))
            ->keyBy('name');

        $this->assertSame('varchar', $columns['number']['type_name']);
        $this->assertSame('varchar(40)', $columns['number']['type']);
        $this->assertSame('boolean', $columns['active']['type_name']);
        $this->assertSame('text', $columns['description']['type_name']);
        $this->assertSame('datetime', $columns['opened_at']['type_name']);
    }

    public function test_it_resolves_an_internal_type_returned_as_a_sys_id(): void
    {
        // EX-306 : `internal_type` renvoyé sous forme de sys_id est résolu vers
        // son nom technique via sys_glide_object.
        $this->fakeDictionary();

        $columns = collect(Schema::connection('servicenow')->getColumns('incident'))
            ->keyBy('name');

        $this->assertSame('integer', $columns['severity']['type_name']);
    }

    public function test_it_describes_columns_with_the_standard_contract(): void
    {
        // EX-309 : les caractéristiques attendues du contrat standard sont
        // renseignées, obligatoire/étiquette compris.
        $this->fakeDictionary();

        $columns = collect(Schema::connection('servicenow')->getColumns('incident'))
            ->keyBy('name');

        $this->assertSame([
            'name', 'type_name', 'type', 'collation', 'nullable', 'default', 'auto_increment', 'comment', 'generation',
        ], array_keys($columns['number']));

        $this->assertFalse($columns['number']['nullable']);
        $this->assertTrue($columns['description']['nullable']);
        $this->assertFalse($columns['number']['auto_increment']);
        $this->assertSame('Number', $columns['number']['comment']);
    }

    public function test_it_answers_has_column_from_the_dictionary(): void
    {
        // EX-301 : hasColumn() dérive des colonnes exposées.
        $this->fakeDictionary();

        $this->assertTrue(Schema::connection('servicenow')->hasColumn('incident', 'number'));
        $this->assertFalse(Schema::connection('servicenow')->hasColumn('incident', 'champ_absent'));
    }

    public function test_it_exposes_a_reference_field_as_a_foreign_key(): void
    {
        // EX-310, EX-311
        $this->fakeDictionary();

        $foreignKeys = Schema::connection('servicenow')->getForeignKeys('incident');

        $this->assertCount(1, $foreignKeys);
        $this->assertSame(['company'], $foreignKeys[0]['columns']);
        $this->assertSame('core_company', $foreignKeys[0]['foreign_table']);
        $this->assertSame(['sys_id'], $foreignKeys[0]['foreign_columns']);
        $this->assertNull($foreignKeys[0]['name']);
    }

    public function test_it_ignores_a_reference_field_whose_target_table_is_unknown(): void
    {
        // EX-313 : le champ reste une colonne ordinaire, sans erreur.
        $this->fakeDictionary();

        $foreignKeys = Schema::connection('servicenow')->getForeignKeys('incident');
        $columns = Schema::connection('servicenow')->getColumnListing('incident');

        $this->assertNotContains('orphan_ref', array_column($foreignKeys, 'columns'));
        $this->assertContains('orphan_ref', $columns);
    }

    public function test_it_queries_nothing_until_the_schema_is_actually_inspected(): void
    {
        // EX-324 : introspection paresseuse, rien au démarrage ni à la
        // construction du constructeur de schéma.
        $this->fakeDictionary();

        DB::connection('servicenow')->getSchemaBuilder();

        Http::assertNothingSent();
    }

    public function test_an_inaccessible_dictionary_raises_an_explicit_exception(): void
    {
        // EX-305 : un dictionnaire inaccessible n'est jamais présenté comme une
        // instance sans tables.
        Http::fake(['*' => Http::response(['error' => ['message' => 'ACL restreint']], 403)]);

        $this->expectException(ServiceNowAuthenticationException::class);

        Schema::connection('servicenow')->getTableListing();
    }

    public function test_a_schema_modification_is_rejected_explicitly(): void
    {
        // Garde-fou hors exigence : le schéma d'une instance ServiceNow se
        // modifie côté instance, pas depuis une migration Laravel.
        $this->expectException(ServiceNowUnsupportedQueryException::class);

        Schema::connection('servicenow')->drop('incident');
    }

    /**
     * Dictionnaire simulé à une seule table sans héritage, dédié aux tests
     * EX-333/EX-334 : plus court que fakeDictionary() (partagée par les tests
     * ci-dessus), qui n'a aucun champ marqué display.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function fakeSingleTableDictionary(string $table, array $fields): void
    {
        Http::fake(function ($request) use ($table, $fields) {
            $url = $request->url();

            if (str_contains($url, '/api/now/table/sys_db_object')) {
                return Http::response(['result' => [
                    ['sys_id' => str_repeat('x', 32), 'name' => $table, 'super_class' => ''],
                ]]);
            }

            if (str_contains($url, '/api/now/table/sys_dictionary')) {
                return Http::response(['result' => $fields]);
            }

            return Http::response(['result' => []]);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function dictionaryField(string $table, string $element, string $internalType, array $overrides = []): array
    {
        return array_merge([
            'name' => $table,
            'element' => $element,
            'internal_type' => $internalType,
            'reference' => '',
            'max_length' => '40',
            'mandatory' => 'false',
            'read_only' => 'false',
            'default_value' => '',
            'column_label' => ucfirst(str_replace('_', ' ', $element)),
        ], $overrides);
    }

    /**
     * Dictionnaire simulé : `incident` hérite de `task`, `core_company` existe,
     * `ghost_table` n'existe pas.
     */
    private function fakeDictionary(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            $query = $request['sysparm_query'] ?? '';

            if (str_contains($url, '/api/now/table/sys_db_object')) {
                return Http::response(['result' => $query === 'ORDERBYname' ? $this->tableRecords() : []]);
            }

            if (str_contains($url, '/api/now/table/sys_dictionary')) {
                return Http::response(['result' => $this->dictionaryRecords()]);
            }

            if (str_contains($url, '/api/now/table/sys_glide_object')) {
                return Http::response(['result' => [
                    ['sys_id' => self::INTEGER_TYPE_SYS_ID, 'name' => 'integer'],
                ]]);
            }

            return Http::response(['result' => []]);
        });
    }

    /**
     * Catalogue complet des tables de l'instance, rapatrié en un seul appel
     * (DictionaryReader::tableCatalog()) : `incident` hérite de `task` via
     * son sys_id, et le champ `company` du dictionnaire (EX-311) référence
     * `core_company` via le sien.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tableRecords(): array
    {
        return [
            ['sys_id' => self::COMPANY_SYS_ID, 'name' => 'core_company', 'super_class' => ''],
            ['sys_id' => '44444444444444444444444444444444', 'name' => 'incident', 'super_class' => ['value' => self::TASK_SYS_ID, 'link' => '...']],
            ['sys_id' => self::TASK_SYS_ID, 'name' => 'task', 'super_class' => ''],
        ];
    }

    /**
     * Champs renvoyés volontairement dans l'ordre inverse de la chaîne
     * d'héritage (incident avant task), pour vérifier le réordonnancement
     * d'EX-304.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dictionaryRecords(): array
    {
        return [
            // internal_type sous forme de sys_id, à résoudre via sys_glide_object.
            [
                'name' => 'incident',
                'element' => 'severity',
                'internal_type' => ['value' => self::INTEGER_TYPE_SYS_ID, 'link' => '...'],
                'reference' => '',
                'max_length' => '40',
                'mandatory' => 'false',
                'read_only' => 'false',
                'default_value' => '3',
                'column_label' => ['value' => 'Severity', 'display_value' => 'Severity'],
            ],
            // internal_type accompagné de son libellé d'affichage.
            [
                'name' => 'incident',
                'element' => 'opened_at',
                'internal_type' => ['value' => 'glide_date_time', 'display_value' => 'Date/Time'],
                'reference' => '',
                'max_length' => '40',
                'mandatory' => 'false',
                'read_only' => 'false',
                'default_value' => '',
                'column_label' => ['value' => 'Opened', 'display_value' => 'Opened'],
            ],
            [
                'name' => 'incident',
                'element' => 'company',
                'internal_type' => 'reference',
                'reference' => ['value' => self::COMPANY_SYS_ID, 'link' => '...'],
                'max_length' => '32',
                'mandatory' => 'false',
                'read_only' => 'false',
                'default_value' => '',
                'column_label' => ['value' => 'Company', 'display_value' => 'Company'],
            ],
            [
                'name' => 'incident',
                'element' => 'orphan_ref',
                'internal_type' => 'reference',
                'reference' => 'ghost_table',
                'max_length' => '32',
                'mandatory' => 'false',
                'read_only' => 'false',
                'default_value' => '',
                'column_label' => ['value' => 'Orpheline', 'display_value' => 'Orpheline'],
            ],
            // internal_type sous forme de chaîne brute.
            [
                'name' => 'task',
                'element' => 'number',
                'internal_type' => 'string',
                'reference' => '',
                'max_length' => '40',
                'mandatory' => 'true',
                'read_only' => 'false',
                'default_value' => '',
                'column_label' => ['value' => 'Number', 'display_value' => 'Number'],
            ],
            [
                'name' => 'task',
                'element' => 'active',
                'internal_type' => 'boolean',
                'reference' => '',
                'max_length' => '40',
                'mandatory' => 'false',
                'read_only' => 'false',
                'default_value' => 'true',
                'column_label' => ['value' => 'Active', 'display_value' => 'Active'],
            ],
            [
                'name' => 'task',
                'element' => 'description',
                'internal_type' => 'journal_input',
                'reference' => '',
                'max_length' => '4000',
                'mandatory' => 'false',
                'read_only' => 'false',
                'default_value' => '',
                'column_label' => ['value' => 'Description', 'display_value' => 'Description'],
            ],
        ];
    }
}

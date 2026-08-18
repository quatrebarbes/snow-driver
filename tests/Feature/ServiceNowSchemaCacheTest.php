<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Quatrebarbes\SnowDriver\Schema\RefreshSchemaCacheJob;
use Quatrebarbes\SnowDriver\Tests\Fixtures\Incident;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-323, EX-337 à EX-341 : cache applicatif du schéma et du comptage, de bout
 * en bout au travers du query builder et du constructeur de schéma standards
 * — un outil hôte n'a rien à faire de particulier pour en bénéficier.
 */
class ServiceNowSchemaCacheTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.servicenow.base_url', 'https://dev12345.service-now.com');
        $app['config']->set('database.connections.servicenow.auth', [
            'mode' => 'basic',
            'username' => 'alice',
            'password' => 'secret',
        ]);
        $app['config']->set('servicenow.cache.ttl', 60);

        // servicenow.models.tables est volontairement laissé vide ici : le
        // renseigner à ce stade déclencherait la génération de modèles et le
        // réchauffement du cache (ServiceNowServiceProvider::boot()) contre
        // un client HTTP pas encore simulé par Http::fake(), qui n'a lieu que
        // dans chaque test. Chaque test le configure lui-même après le
        // démarrage de l'application.
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_second_count_within_the_ttl_does_not_call_the_aggregate_api_again(): void
    {
        // EX-337, EX-338
        config(['servicenow.models.tables' => ['incidents']]);

        Http::fake([
            '*/api/now/stats/*' => Http::response(['result' => ['stats' => ['count' => '42']]]),
            '*' => Http::response(['result' => []]),
        ]);

        $this->assertSame(42, Incident::count());
        $this->assertSame(42, Incident::count());

        Http::assertSentCount(1);
    }

    public function test_a_second_column_read_within_the_ttl_does_not_query_the_dictionary_again(): void
    {
        // EX-337, EX-338 : Schema::connection() reconstruit un constructeur de
        // schéma (et donc un DictionaryReader) à chaque appel — sans le cache
        // applicatif, chaque lecture réinterrogerait le dictionnaire.
        config(['servicenow.models.tables' => ['incidents']]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/api/now/table/sys_db_object')) {
                return Http::response(['result' => [
                    ['sys_id' => str_repeat('a', 32), 'name' => 'incidents', 'super_class' => ''],
                ]]);
            }

            if (str_contains($url, '/api/now/table/sys_dictionary')) {
                return Http::response(['result' => [[
                    'name' => 'incidents',
                    'element' => 'number',
                    'internal_type' => 'string',
                    'reference' => '',
                    'max_length' => '40',
                    'mandatory' => 'false',
                    'read_only' => 'false',
                    'default_value' => '',
                    'column_label' => 'Number',
                ]]]);
            }

            return Http::response(['result' => []]);
        });

        Schema::connection('servicenow')->getColumnListing('incidents');
        Schema::connection('servicenow')->getColumnListing('incidents');

        // sys_db_object (catalogue) + sys_dictionary (champs) : un seul
        // aller-retour de chacun, pas deux.
        Http::assertSentCount(2);
    }

    public function test_an_expired_count_is_served_stale_and_schedules_an_async_refresh(): void
    {
        // EX-340, EX-341 : la lecture qui déclenche l'expiration n'attend pas
        // le rafraîchissement, elle reçoit la valeur périmée.
        config(['servicenow.models.tables' => ['incidents']]);

        Bus::fake();
        Http::fake([
            '*/api/now/stats/*' => Http::response(['result' => ['stats' => ['count' => '42']]]),
            '*' => Http::response(['result' => []]),
        ]);

        $this->assertSame(42, Incident::count());

        Carbon::setTestNow(now()->addSeconds(61));

        $this->assertSame(42, Incident::count());

        Http::assertSentCount(1);
        Bus::assertDispatched(RefreshSchemaCacheJob::class);
    }

    public function test_the_cache_is_fully_disabled_when_the_ttl_is_zero(): void
    {
        // EX-323
        config(['servicenow.models.tables' => ['incidents']]);
        $this->app['config']->set('servicenow.cache.ttl', 0);

        Http::fake([
            '*/api/now/stats/*' => Http::response(['result' => ['stats' => ['count' => '42']]]),
            '*' => Http::response(['result' => []]),
        ]);

        Incident::count();
        Incident::count();

        Http::assertSentCount(2);
    }

    public function test_a_second_table_listing_within_the_ttl_does_not_query_the_dictionary_again(): void
    {
        // EX-322, EX-338 : la liste des tables de l'instance suit le même
        // mécanisme de cache que le schéma d'une table, sans dépendre de
        // servicenow.models.tables (laissé vide ici). DB::purge() force une
        // connexion neuve (donc un ServiceNowSchemaBuilder et un
        // DictionaryReader neufs) entre les deux lectures, afin de vérifier le
        // cache applicatif lui-même plutôt que la seule mémorisation en
        // mémoire d'un DictionaryReader partagé au sein d'une même connexion.
        Http::fake([
            '*/api/now/table/sys_db_object*' => Http::response(['result' => [
                ['sys_id' => str_repeat('a', 32), 'name' => 'incidents', 'super_class' => ''],
            ]]),
            '*' => Http::response(['result' => []]),
        ]);

        Schema::connection('servicenow')->getTableListing();
        DB::purge('servicenow');
        Schema::connection('servicenow')->getTableListing();

        Http::assertSentCount(1);
    }

    public function test_a_second_hasTable_check_across_connections_does_not_query_the_dictionary_again(): void
    {
        // EX-322, EX-338 : hasTable() est résolue depuis la même liste de
        // tables mise en cache que getTableListing(), et non par une
        // interrogation dédiée du dictionnaire — sans quoi une connexion
        // neuve (DB::purge(), donc un DictionaryReader vierge) redéclenchait
        // un aller-retour sys_db_object complet à chaque appel, quelle que
        // soit la fraîcheur du cache applicatif (bug constaté en production
        // le 2026-08-18).
        Http::fake([
            '*/api/now/table/sys_db_object*' => Http::response(['result' => [
                ['sys_id' => str_repeat('a', 32), 'name' => 'incidents', 'super_class' => ''],
            ]]),
            '*' => Http::response(['result' => []]),
        ]);

        $this->assertTrue(Schema::connection('servicenow')->hasTable('incidents'));
        DB::purge('servicenow');
        $this->assertTrue(Schema::connection('servicenow')->hasTable('incidents'));

        Http::assertSentCount(1);
    }

    public function test_a_second_foreign_key_lookup_across_connections_does_not_recheck_the_reference_table(): void
    {
        // EX-313, EX-322 : la vérification d'existence de la table référencée
        // par un champ reference doit elle aussi passer par la liste de
        // tables mise en cache, et non par une interrogation dédiée du
        // dictionnaire à chaque connexion neuve — même bug que pour
        // hasTable() ci-dessus.
        config(['servicenow.models.tables' => ['incidents']]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/api/now/table/sys_db_object')) {
                return Http::response(['result' => [
                    ['sys_id' => str_repeat('a', 32), 'name' => 'incidents', 'super_class' => ''],
                    ['sys_id' => str_repeat('b', 32), 'name' => 'core_company', 'super_class' => ''],
                ]]);
            }

            if (str_contains($url, '/api/now/table/sys_dictionary')) {
                return Http::response(['result' => [[
                    'name' => 'incidents',
                    'element' => 'company',
                    'internal_type' => 'reference',
                    'reference' => 'core_company',
                    'max_length' => '32',
                    'mandatory' => 'false',
                    'read_only' => 'false',
                    'default_value' => '',
                    'column_label' => 'Company',
                ]]]);
            }

            return Http::response(['result' => []]);
        });

        Schema::connection('servicenow')->getForeignKeys('incidents');
        DB::purge('servicenow');
        Schema::connection('servicenow')->getForeignKeys('incidents');

        // sys_db_object (catalogue) + sys_dictionary (champs) : un seul
        // aller-retour de chacun, pas deux.
        Http::assertSentCount(2);
    }

    public function test_an_expired_table_list_is_served_stale_and_schedules_an_async_refresh(): void
    {
        // EX-340, EX-341
        Bus::fake();
        Http::fake([
            '*/api/now/table/sys_db_object*' => Http::response(['result' => [
                ['sys_id' => str_repeat('a', 32), 'name' => 'incidents', 'super_class' => ''],
            ]]),
            '*' => Http::response(['result' => []]),
        ]);

        $this->assertSame(['incidents'], Schema::connection('servicenow')->getTableListing());

        Carbon::setTestNow(now()->addSeconds(61));
        DB::purge('servicenow');

        $this->assertSame(['incidents'], Schema::connection('servicenow')->getTableListing());

        Http::assertSentCount(1);
        Bus::assertDispatched(RefreshSchemaCacheJob::class);
    }

    public function test_the_table_list_cache_is_fully_disabled_when_the_ttl_is_zero(): void
    {
        // EX-323
        $this->app['config']->set('servicenow.cache.ttl', 0);

        Http::fake([
            '*/api/now/table/sys_db_object*' => Http::response(['result' => [
                ['sys_id' => str_repeat('a', 32), 'name' => 'incidents', 'super_class' => ''],
            ]]),
            '*' => Http::response(['result' => []]),
        ]);

        Schema::connection('servicenow')->getTableListing();
        DB::purge('servicenow');
        Schema::connection('servicenow')->getTableListing();

        Http::assertSentCount(2);
    }

    public function test_an_unfiltered_listing_opportunistically_feeds_the_count_cache(): void
    {
        // EX-339 : l'en-tête X-Total-Count d'un listing sans filtre alimente
        // le cache de comptage, sans appel dédié à l'API d'agrégation.
        config(['servicenow.models.tables' => ['incidents']]);

        Http::fake([
            '*/api/now/table/incidents*' => Http::response(
                ['result' => [['sys_id' => 'abc123']]],
                200,
                ['X-Total-Count' => '7']
            ),
        ]);

        Incident::all();

        $this->assertSame(7, Incident::count());

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/now/stats/'));
    }
}

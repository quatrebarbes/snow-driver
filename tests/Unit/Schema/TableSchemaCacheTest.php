<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Schema;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;
use Quatrebarbes\SnowDriver\Schema\RefreshSchemaCacheJob;
use Quatrebarbes\SnowDriver\Schema\TableSchemaCache;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-323, EX-337 à EX-341 : cache applicatif du schéma et du comptage des
 * tables configurées, servi en lecture-attente (stale-while-revalidate) —
 * une entrée absente charge en direct et mémorise, une entrée expirée est
 * rendue telle quelle pendant qu'un rafraîchissement est différé après la
 * réponse en cours (RefreshSchemaCacheJob).
 */
class TableSchemaCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_is_not_eligible_when_the_table_is_not_configured(): void
    {
        config(['servicenow.cache.ttl' => 3600, 'servicenow.models.tables' => ['incident']]);

        $this->assertTrue($this->cache()->eligible('incident'));
        $this->assertFalse($this->cache()->eligible('problem'));
    }

    public function test_it_is_not_eligible_when_the_ttl_is_zero(): void
    {
        // EX-323 : une durée nulle désactive le cache.
        config(['servicenow.cache.ttl' => 0, 'servicenow.models.tables' => ['incident']]);

        $this->assertFalse($this->cache()->eligible('incident'));
    }

    public function test_a_missing_entry_is_loaded_live_and_memorized(): void
    {
        config(['servicenow.cache.ttl' => 3600, 'servicenow.models.tables' => ['incident']]);

        $calls = 0;
        $live = function () use (&$calls) {
            $calls++;

            return [['element' => 'number']];
        };

        $first = $this->cache()->fields('incident', $live);
        $second = $this->cache()->fields('incident', $live);

        $this->assertSame([['element' => 'number']], $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    public function test_count_is_memorized_independently_from_fields(): void
    {
        config(['servicenow.cache.ttl' => 3600, 'servicenow.models.tables' => ['incident']]);

        $fieldsCalls = 0;
        $countCalls = 0;

        $this->cache()->fields('incident', function () use (&$fieldsCalls) {
            $fieldsCalls++;

            return [];
        });

        $count = $this->cache()->count('incident', function () use (&$countCalls) {
            $countCalls++;

            return 42;
        });

        $this->assertSame(42, $count);
        $this->assertSame(1, $fieldsCalls);
        $this->assertSame(1, $countCalls);
    }

    public function test_a_stale_entry_is_served_as_is_and_schedules_an_async_refresh(): void
    {
        // EX-340, EX-341
        Bus::fake();
        config(['servicenow.cache.ttl' => 60, 'servicenow.models.tables' => ['incident']]);

        $this->cache()->storeCount('incident', 10);

        Carbon::setTestNow(now()->addSeconds(61));

        $calls = 0;
        $value = $this->cache()->count('incident', function () use (&$calls) {
            $calls++;

            return 999;
        });

        $this->assertSame(10, $value);
        $this->assertSame(0, $calls);

        Bus::assertDispatched(RefreshSchemaCacheJob::class, fn (RefreshSchemaCacheJob $job) => $this->jobCountTables($job) === ['incident']
            && $this->jobFieldsTables($job) === []);
    }

    public function test_a_fresh_entry_never_schedules_a_refresh(): void
    {
        Bus::fake();
        config(['servicenow.cache.ttl' => 3600, 'servicenow.models.tables' => ['incident']]);

        $this->cache()->storeCount('incident', 10);

        $this->cache()->count('incident', fn () => 999);

        Bus::assertNotDispatched(RefreshSchemaCacheJob::class);
    }

    public function test_remembering_a_count_is_a_noop_for_an_ineligible_table(): void
    {
        // EX-339 : la mise à jour opportuniste reste bornée aux tables couvertes par EX-337.
        config(['servicenow.cache.ttl' => 3600, 'servicenow.models.tables' => ['incident']]);

        $this->cache()->rememberCount('problem', 5);

        $calls = 0;
        $this->cache()->count('problem', function () use (&$calls) {
            $calls++;

            return 123;
        });

        $this->assertSame(1, $calls);
    }

    public function test_warm_schedules_a_single_batched_refresh_for_missing_or_stale_entries(): void
    {
        // EX-338 : vérification de fraîcheur au démarrage, sans appel réseau.
        // Un seul job pour tout le lot (et non un par table et par volet) :
        // RefreshSchemaCacheJob ne partage son DictionaryReader qu'au sein
        // d'un même job, cf. bug constaté en production le 2026-08-18 (autant
        // d'appels sys_db_object redondants que de tables à rafraîchir).
        Bus::fake();
        config(['servicenow.cache.ttl' => 60, 'servicenow.models.tables' => ['incident', 'problem']]);

        $this->cache()->storeFields('incident', []);
        $this->cache()->storeCount('incident', 1);

        $this->cache()->warm(['incident', 'problem']);

        Bus::assertDispatchedTimes(RefreshSchemaCacheJob::class, 1);
        Bus::assertDispatched(RefreshSchemaCacheJob::class, fn (RefreshSchemaCacheJob $job) => $this->jobFieldsTables($job) === ['problem']
            && $this->jobCountTables($job) === ['problem']);
    }

    public function test_warm_does_nothing_when_the_cache_is_disabled(): void
    {
        Bus::fake();
        config(['servicenow.cache.ttl' => 0, 'servicenow.models.tables' => ['incident']]);

        $this->cache()->warm(['incident']);

        Bus::assertNothingDispatched();
    }

    public function test_table_names_are_not_eligible_when_the_ttl_is_zero(): void
    {
        // EX-322, EX-323 : durée nulle désactive aussi la liste des tables.
        config(['servicenow.cache.ttl' => 0]);

        $this->assertFalse($this->cache()->tableNamesEligible());
    }

    public function test_table_names_are_eligible_without_any_configured_table(): void
    {
        // EX-322 : contrairement à eligible(), aucune table n'a besoin d'être
        // déclarée dans servicenow.models.tables.
        config(['servicenow.cache.ttl' => 3600, 'servicenow.models.tables' => []]);

        $this->assertTrue($this->cache()->tableNamesEligible());
    }

    public function test_a_missing_table_list_entry_is_loaded_live_and_memorized(): void
    {
        config(['servicenow.cache.ttl' => 3600]);

        $calls = 0;
        $live = function () use (&$calls) {
            $calls++;

            return ['incident', 'problem'];
        };

        $first = $this->cache()->tableNames($live);
        $second = $this->cache()->tableNames($live);

        $this->assertSame(['incident', 'problem'], $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    public function test_a_stale_table_list_entry_is_served_as_is_and_schedules_an_async_refresh(): void
    {
        // EX-340, EX-341
        Bus::fake();
        config(['servicenow.cache.ttl' => 60]);

        $this->cache()->storeTableNames(['incident']);

        Carbon::setTestNow(now()->addSeconds(61));

        $calls = 0;
        $value = $this->cache()->tableNames(function () use (&$calls) {
            $calls++;

            return ['incident', 'problem'];
        });

        $this->assertSame(['incident'], $value);
        $this->assertSame(0, $calls);

        Bus::assertDispatched(RefreshSchemaCacheJob::class, fn (RefreshSchemaCacheJob $job) => $this->jobRefreshTableList($job)
            && $this->jobFieldsTables($job) === []
            && $this->jobCountTables($job) === []);
    }

    public function test_a_fresh_table_list_entry_never_schedules_a_refresh(): void
    {
        Bus::fake();
        config(['servicenow.cache.ttl' => 3600]);

        $this->cache()->storeTableNames(['incident']);

        $this->cache()->tableNames(fn () => ['incident', 'problem']);

        Bus::assertNotDispatched(RefreshSchemaCacheJob::class);
    }

    public function test_warm_also_schedules_a_refresh_of_a_missing_table_list(): void
    {
        // EX-322, EX-338 : la liste des tables est vérifiée au même titre que
        // le schéma et le comptage des tables configurées, y compris sans
        // aucune table configurée.
        Bus::fake();
        config(['servicenow.cache.ttl' => 60, 'servicenow.models.tables' => []]);

        $this->cache()->warm([]);

        Bus::assertDispatched(RefreshSchemaCacheJob::class, fn (RefreshSchemaCacheJob $job) => $this->jobRefreshTableList($job));
    }

    public function test_warm_does_not_refresh_a_fresh_table_list(): void
    {
        Bus::fake();
        config(['servicenow.cache.ttl' => 3600, 'servicenow.models.tables' => []]);

        $this->cache()->storeTableNames(['incident']);

        $this->cache()->warm([]);

        Bus::assertNotDispatched(RefreshSchemaCacheJob::class);
    }

    private function cache(): TableSchemaCache
    {
        return new TableSchemaCache(new ServiceNowConnection('', '', [
            'driver' => 'servicenow',
            'name' => 'servicenow',
            'base_url' => 'https://dev12345.service-now.com',
            'timeout' => 5,
            'auth' => ['mode' => 'basic', 'username' => 'alice', 'password' => 'secret'],
        ]));
    }

    private function jobRefreshTableList(RefreshSchemaCacheJob $job): bool
    {
        return (fn () => $this->refreshTableList)->call($job);
    }

    /**
     * @return array<int, string>
     */
    private function jobFieldsTables(RefreshSchemaCacheJob $job): array
    {
        return (fn () => $this->fieldsTables)->call($job);
    }

    /**
     * @return array<int, string>
     */
    private function jobCountTables(RefreshSchemaCacheJob $job): array
    {
        return (fn () => $this->countTables)->call($job);
    }
}

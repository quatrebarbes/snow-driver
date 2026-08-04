<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowApiException;
use Quatrebarbes\SnowDriver\Exceptions\ServiceNowUnsupportedQueryException;
use Quatrebarbes\SnowDriver\Tests\Fixtures\Incident;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-112 à EX-115, EX-123, EX-124, EX-131 : création, modification et
 * suppression des enregistrements au travers des méthodes standards Eloquent
 * (save(), update(), delete()), via l'API Table de ServiceNow.
 */
class ServiceNowWriteTest extends TestCase
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

    public function test_saving_a_new_model_sends_a_post_request_and_refreshes_it_from_the_response(): void
    {
        // EX-112, EX-115
        Http::fake([
            '*/api/now/table/incidents' => Http::response(['result' => [
                'sys_id' => 'abc123',
                'short_description' => 'Network outage',
                'sys_created_on' => '2026-08-04 10:00:00',
                'sys_updated_on' => '2026-08-04 10:00:00',
            ]], 201),
        ]);

        $incident = new Incident(['short_description' => 'Network outage']);
        $incident->save();

        $this->assertSame('abc123', $incident->sys_id);
        $this->assertTrue($incident->exists);
        $this->assertTrue($incident->wasRecentlyCreated);
        $this->assertFalse($incident->isDirty());

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/api/now/table/incidents')
            && $request['short_description'] === 'Network outage');
    }

    public function test_saving_an_existing_dirty_model_sends_a_patch_request_with_only_the_dirty_attributes(): void
    {
        // EX-113, EX-115
        $incident = $this->existingIncident(['short_description' => 'Network outage', 'priority' => '3']);

        Http::fake([
            '*/api/now/table/incidents/abc123' => Http::response(['result' => [
                'sys_id' => 'abc123',
                'short_description' => 'Network outage',
                'priority' => '1',
                'sys_updated_on' => '2026-08-04 11:00:00',
            ]], 200),
        ]);

        $incident->priority = '1';
        $incident->save();

        $this->assertSame('1', $incident->priority);
        $this->assertSame('2026-08-04 11:00:00', $incident->sys_updated_on->toDateTimeString());
        $this->assertFalse($incident->isDirty());

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/api/now/table/incidents/abc123')
            && $request['priority'] === '1'
            && ! array_key_exists('short_description', $request->data()));
    }

    public function test_the_update_method_also_issues_a_patch_request(): void
    {
        // EX-113 : update() sur une instance existante
        $incident = $this->existingIncident(['short_description' => 'Network outage', 'priority' => '3']);

        Http::fake([
            '*/api/now/table/incidents/abc123' => Http::response(['result' => [
                'sys_id' => 'abc123',
                'short_description' => 'Network outage',
                'priority' => '1',
            ]], 200),
        ]);

        $incident->update(['priority' => '1']);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request['priority'] === '1');
    }

    public function test_saving_an_existing_model_without_changes_does_not_send_any_request(): void
    {
        $incident = $this->existingIncident(['short_description' => 'Network outage']);

        Http::fake();

        $incident->save();

        Http::assertNothingSent();
    }

    public function test_deleting_a_model_sends_a_delete_request_to_the_table_api(): void
    {
        // EX-114
        $incident = $this->existingIncident([]);

        Http::fake([
            '*/api/now/table/incidents/abc123' => Http::response('', 204),
        ]);

        $incident->delete();

        $this->assertFalse($incident->exists);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/now/table/incidents/abc123'));
    }

    public function test_save_many_processes_each_model_independently_and_reports_partial_failures(): void
    {
        // EX-123 : un échec au milieu du lot ne bloque pas les enregistrements suivants.
        Http::fake([
            '*/api/now/table/incidents' => function ($request) {
                $description = $request['short_description'];

                if ($description === 'fails') {
                    return Http::response(['error' => ['message' => 'Invalid record']], 400);
                }

                return Http::response(['result' => [
                    'sys_id' => 'sys-'.$description,
                    'short_description' => $description,
                ]], 201);
            },
        ]);

        $models = [
            new Incident(['short_description' => 'ok-1']),
            new Incident(['short_description' => 'fails']),
            new Incident(['short_description' => 'ok-2']),
        ];

        $result = Incident::saveMany($models);

        $this->assertCount(2, $result->successes);
        $this->assertCount(1, $result->failures);
        $this->assertTrue($result->hasFailures());
        $this->assertSame('fails', $result->failures[0]->model->short_description);
        $this->assertInstanceOf(ServiceNowApiException::class, $result->failures[0]->exception);

        Http::assertSentCount(3);
    }

    public function test_concurrent_updates_carry_no_conflict_detection_mechanism(): void
    {
        // EX-124 : documentation + non-régression — le driver n'ajoute aucun
        // en-tête conditionnel ; le comportement observé en cas d'écritures
        // concurrentes est celui, natif, du dernier écrivain gagnant.
        $incident = $this->existingIncident(['priority' => '3']);

        Http::fake(['*' => Http::response(['result' => ['sys_id' => 'abc123', 'priority' => '1']], 200)]);

        $incident->priority = '1';
        $incident->save();

        Http::assertSent(fn ($request) => ! $request->hasHeader('If-Match')
            && ! $request->hasHeader('If-Unmodified-Since'));
    }

    public function test_mass_update_via_the_query_builder_is_rejected_without_any_request(): void
    {
        // EX-131 : Model::where(...)->update([...]) sans instance chargée.
        Http::fake();

        $this->expectException(ServiceNowUnsupportedQueryException::class);

        try {
            Incident::where('priority', '3')->update(['priority' => '1']);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_mass_delete_via_the_query_builder_is_rejected_without_any_request(): void
    {
        // EX-131 : Model::where(...)->delete() sans instance chargée.
        Http::fake();

        $this->expectException(ServiceNowUnsupportedQueryException::class);

        try {
            Incident::where('priority', '3')->delete();
        } finally {
            Http::assertNothingSent();
        }
    }

    private function existingIncident(array $attributes): Incident
    {
        $incident = new Incident(array_merge(['sys_id' => 'abc123'], $attributes));
        $incident->exists = true;
        $incident->syncOriginal();

        return $incident;
    }
}

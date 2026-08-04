<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;
use Quatrebarbes\SnowDriver\Generator\ModelFileGenerator;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-201 à EX-211, EX-312, EX-325 à EX-327 : génération automatique de
 * modèles Eloquent pour les tables ServiceNow configurées, avec leurs
 * relations belongsTo/hasMany et leurs champs modifiables/conversions.
 *
 * Ces tests appellent ModelFileGenerator directement (plutôt qu'au travers
 * du démarrage complet de l'application hôte, couvert séparément par
 * ServiceNowModelGenerationBootTest) : le chemin de génération dépend de
 * app_path(), redirigé ici vers un dossier temporaire propre à chaque test.
 */
class ServiceNowModelGenerationTest extends TestCase
{
    private string $appPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appPath = sys_get_temp_dir().'/snow-driver-tests/models-'.uniqid('', true);
        mkdir($this->appPath, 0755, true);

        $this->app->useAppPath($this->appPath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->appPath);

        parent::tearDown();
    }

    public function test_it_generates_a_missing_model_with_its_table_fillable_fields_and_casts(): void
    {
        // EX-202, EX-203, EX-325, EX-326, EX-327
        $this->fakeDictionary([
            'incident' => [
                $this->field('short_description', 'string'),
                $this->field('number', 'string', readOnly: true),
                $this->field('active', 'boolean'),
            ],
        ]);

        (new ModelFileGenerator($this->connection()))->generate(['incident'], 'App\\Models');

        $content = $this->generatedContent('Incident');

        $this->assertStringContainsString('namespace App\\Models;', $content);
        $this->assertStringContainsString('class Incident extends ServiceNowModel', $content);
        $this->assertStringContainsString("protected \$table = 'incident';", $content);
        // EX-325, EX-326 : champ inscriptible présent, champ en lecture seule exclu.
        $this->assertStringContainsString("'short_description'", $content);
        $this->assertStringNotContainsString("'number'", $content);
        // EX-327 : conversion déclarée pour le champ booléen.
        $this->assertStringContainsString("'active' => 'boolean'", $content);
    }

    public function test_it_does_not_overwrite_an_existing_model_file(): void
    {
        // Limite SFD : préserve toute personnalisation manuelle.
        mkdir($this->appPath.'/Models', 0755, true);
        file_put_contents($this->appPath.'/Models/Incident.php', "<?php\n// CUSTOM MARKER\n");

        Http::fake();

        (new ModelFileGenerator($this->connection()))->generate(['incident'], 'App\\Models');

        $this->assertStringContainsString('CUSTOM MARKER', $this->generatedContent('Incident'));
        Http::assertNothingSent();
    }

    public function test_an_empty_configuration_has_no_effect(): void
    {
        // EX-201, limite SFD
        Http::fake();

        (new ModelFileGenerator($this->connection()))->generate([], 'App\\Models');

        Http::assertNothingSent();
        $this->assertDirectoryDoesNotExist($this->appPath.'/Models');
    }

    public function test_a_write_failure_is_reported_without_blocking_generation(): void
    {
        // Limite SFD : filesystem en lecture seule (simulé ici par un fichier
        // occupant l'emplacement attendu du dossier de destination).
        file_put_contents($this->appPath.'/Models', 'not a directory');

        $this->fakeDictionary(['incident' => [$this->field('short_description', 'string')]]);

        Log::spy();

        (new ModelFileGenerator($this->connection()))->generate(['incident'], 'App\\Models');

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_unresolvable_namespace_is_reported_without_any_request(): void
    {
        // Limite SFD : namespace non enraciné sous App\.
        Http::fake();
        Log::spy();

        (new ModelFileGenerator($this->connection()))->generate(['incident'], 'Modules\\ServiceNow\\Models');

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_belongs_to_relation_is_generated_towards_an_existing_manually_declared_model(): void
    {
        // EX-207, EX-208, EX-312
        require_once __DIR__.'/../Fixtures/Generated/CoreCompanyFixture.php';

        $this->fakeDictionary([
            'incident' => [$this->field('company', 'reference', 'core_company')],
        ]);

        (new ModelFileGenerator($this->connection()))->generate(['incident'], 'App\\Models');

        $content = $this->generatedContent('Incident');

        $this->assertStringContainsString('use Illuminate\Database\Eloquent\Relations\BelongsTo;', $content);
        $this->assertStringContainsString('public function companyRecord(): BelongsTo', $content);
        $this->assertStringContainsString("\$this->belongsTo(\\App\\Models\\CoreCompany::class, 'company', 'sys_id')", $content);
    }

    public function test_a_reference_field_is_ignored_when_its_target_table_has_no_resolvable_model(): void
    {
        // EX-208
        $this->fakeDictionary([
            'incident' => [$this->field('assigned_group', 'reference', 'sys_user_group')],
        ]);

        (new ModelFileGenerator($this->connection()))->generate(['incident'], 'App\\Models');

        $content = $this->generatedContent('Incident');

        $this->assertStringNotContainsString('assignedGroupRecord', $content);
        $this->assertStringNotContainsString('BelongsTo', $content);
    }

    public function test_has_many_relations_are_generated_between_mutually_configured_tables(): void
    {
        // EX-206, EX-207, EX-209, EX-210 : génération en deux passes, la
        // résolvabilité d'Incident/CoreCompany/Task ne dépendant pas de
        // l'ordre de traitement au sein de ce même cycle.
        $this->fakeDictionary([
            'incident' => [$this->field('company', 'reference', 'core_company')],
            'task' => [$this->field('incident', 'reference', 'incident')],
            'core_company' => [],
        ]);

        (new ModelFileGenerator($this->connection()))->generate(['incident', 'task', 'core_company'], 'App\\Models');

        $incident = $this->generatedContent('Incident');
        $this->assertStringContainsString('public function companyRecord(): BelongsTo', $incident);
        $this->assertStringContainsString("belongsTo(\\App\\Models\\CoreCompany::class, 'company', 'sys_id')", $incident);
        $this->assertStringContainsString('public function tasks(): HasMany', $incident);
        $this->assertStringContainsString("hasMany(\\App\\Models\\Task::class, 'incident', 'sys_id')", $incident);

        $task = $this->generatedContent('Task');
        $this->assertStringContainsString('public function incidentRecord(): BelongsTo', $task);

        $company = $this->generatedContent('CoreCompany');
        $this->assertStringContainsString('public function incidents(): HasMany', $company);
        $this->assertStringContainsString("hasMany(\\App\\Models\\Incident::class, 'company', 'sys_id')", $company);
    }

    public function test_ambiguous_has_many_relations_are_disambiguated_by_field_name(): void
    {
        // EX-211 : task.incident et task.parent_incident pointent tous deux
        // vers incident -> tasksIncident() et tasksParentIncident().
        $this->fakeDictionary([
            'incident' => [],
            'task' => [
                $this->field('incident', 'reference', 'incident'),
                $this->field('parent_incident', 'reference', 'incident'),
            ],
        ]);

        (new ModelFileGenerator($this->connection()))->generate(['incident', 'task'], 'App\\Models');

        $content = $this->generatedContent('Incident');

        $this->assertStringContainsString('public function tasksIncident(): HasMany', $content);
        $this->assertStringContainsString("hasMany(\\App\\Models\\Task::class, 'incident', 'sys_id')", $content);
        $this->assertStringContainsString('public function tasksParentIncident(): HasMany', $content);
        $this->assertStringContainsString("hasMany(\\App\\Models\\Task::class, 'parent_incident', 'sys_id')", $content);
    }

    private function connection(): ServiceNowConnection
    {
        return new ServiceNowConnection('', '', [
            'driver' => 'servicenow',
            'name' => 'servicenow',
            'base_url' => 'https://dev12345.service-now.com',
            'timeout' => 5,
            'auth' => ['mode' => 'basic', 'username' => 'alice', 'password' => 'secret'],
        ]);
    }

    private function generatedContent(string $class): string
    {
        $path = $this->appPath.'/Models/'.$class.'.php';

        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $element, string $internalType, string $reference = '', bool $readOnly = false): array
    {
        return [
            'element' => $element,
            'internal_type' => $internalType,
            'reference' => $reference,
            'max_length' => '40',
            'mandatory' => 'false',
            'read_only' => $readOnly ? 'true' : 'false',
            'default_value' => '',
            'column_label' => ucfirst(str_replace('_', ' ', $element)),
        ];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $tableFields
     */
    private function fakeDictionary(array $tableFields): void
    {
        Http::fake(function ($request) use ($tableFields) {
            $url = $request->url();
            $query = $request['sysparm_query'] ?? '';

            if (str_contains($url, '/api/now/table/sys_db_object')) {
                foreach (array_keys($tableFields) as $table) {
                    if ($query === 'name='.$table) {
                        return Http::response(['result' => [['name' => $table, 'super_class' => '']]]);
                    }
                }

                return Http::response(['result' => []]);
            }

            if (str_contains($url, '/api/now/table/sys_dictionary')) {
                foreach ($tableFields as $table => $fields) {
                    if ($query === 'nameIN'.$table.'^elementISNOTEMPTY^active=true') {
                        return Http::response(['result' => array_map(
                            fn (array $field) => $field + ['name' => $table],
                            $fields
                        )]);
                    }
                }

                return Http::response(['result' => []]);
            }

            return Http::response(['result' => []]);
        });
    }

    private function removeDirectory(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeDirectory($path.'/'.$entry);
        }

        rmdir($path);
    }
}

<?php

namespace Quatrebarbes\SnowDriver\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-202 : vérifie que ServiceNowServiceProvider déclenche bien la génération
 * de modèles au démarrage de l'application hôte, à partir de la configuration
 * servicenow.models (contrairement à ServiceNowModelGenerationTest, qui
 * appelle ModelFileGenerator directement pour couvrir les nuances de
 * génération sans dépendre du cycle de démarrage complet).
 */
class ServiceNowModelGenerationBootTest extends TestCase
{
    private static string $appPath;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.servicenow.base_url', 'https://dev12345.service-now.com');
        $app['config']->set('database.connections.servicenow.auth', [
            'mode' => 'basic',
            'username' => 'alice',
            'password' => 'secret',
        ]);
        $app['config']->set('servicenow.models.tables', ['incident']);

        self::$appPath = sys_get_temp_dir().'/snow-driver-tests/boot-'.uniqid('', true);
        mkdir(self::$appPath, 0755, true);
        $app->useAppPath(self::$appPath);

        // Aucune table héritée, aucun champ : suffit à prouver que le
        // ServiceProvider invoque bien la génération au démarrage.
        Http::fake(['*' => Http::response(['result' => []])]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory(self::$appPath);

        parent::tearDown();
    }

    public function test_the_configured_model_is_generated_when_the_application_boots(): void
    {
        $this->assertFileExists(self::$appPath.'/Models/Incident.php');

        $content = file_get_contents(self::$appPath.'/Models/Incident.php');

        $this->assertStringContainsString('class Incident extends ServiceNowModel', $content);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.'/'.$entry;

            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }
}

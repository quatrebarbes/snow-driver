<?php

namespace Quatrebarbes\SnowDriver\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Quatrebarbes\SnowDriver\ServiceNowServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ServiceNowServiceProvider::class,
        ];
    }

    /**
     * EX-132 : une lecture d'enregistrements interroge désormais aussi le
     * dictionnaire (sys_db_object) pour convertir les champs booléens,
     * entiers et décimaux. À appeler avant tout Http::fake() propre au test
     * qui exerce une lecture, pour qu'une table sans dictionnaire connu (donc
     * sans conversion) n'entraîne ni appel réseau réel ni décompte de
     * requêtes faussé — le premier Http::fake() enregistré étant celui qui
     * l'emporte sur une même URL.
     */
    protected function fakeEmptyDictionary(): void
    {
        Http::fake(['*/api/now/table/sys_db_object*' => Http::response(['result' => []], 200)]);
    }
}

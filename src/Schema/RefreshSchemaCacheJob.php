<?php

namespace Quatrebarbes\SnowDriver\Schema;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;
use Throwable;

/**
 * EX-340 : rafraîchissement différé du cache de schéma d'un lot de tables,
 * dispatché par TableSchemaCache via dispatch()->afterResponse() — s'exécute
 * donc une fois la réponse HTTP de la lecture qui l'a déclenché déjà envoyée,
 * sans jamais en pénaliser le temps de réponse.
 *
 * Un seul job porte potentiellement plusieurs tables (`$fieldsTables`,
 * `$countTables`), plutôt qu'un job par table et par volet : les champs de
 * plusieurs tables sont lus avec un unique `DictionaryReader`, dont la
 * mémorisation du catalogue des tables (`sys_db_object`, cf.
 * DictionaryReader::tableCatalog()) n'a alors lieu qu'une seule fois pour tout
 * le lot — un `DictionaryReader` neuf par table, comme lors de la première
 * implémentation de ce job, réinterrogeait ce catalogue autant de fois que de
 * tables à rafraîchir (bug constaté au démarrage d'une application hôte
 * configurant une centaine de tables : autant d'appels `sys_db_object`
 * redondants que de tables, cf. `TableSchemaCache::warm()`).
 *
 * Classe auto-porteuse (pas de ShouldQueue) : dispatch()->afterResponse()
 * l'exécute directement à la terminaison de la requête, sans dépendre d'un
 * worker de file d'attente.
 *
 * EX-322 : $refreshTableList porte le rafraîchissement de la liste des tables
 * de l'instance, partageant le même `DictionaryReader` (et donc le même appel
 * à `sys_db_object`) que $fieldsTables lorsque les deux sont demandés dans le
 * même lot.
 */
class RefreshSchemaCacheJob
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $fieldsTables
     * @param  array<int, string>  $countTables
     */
    public function __construct(
        private readonly string $connectionName,
        private readonly array $fieldsTables,
        private readonly array $countTables,
        private readonly bool $refreshTableList = false,
    ) {
    }

    public function handle(): void
    {
        $connection = DB::connection($this->connectionName);

        if (! $connection instanceof ServiceNowConnection) {
            return;
        }

        $schemaCache = $connection->schemaCache();

        if ($this->fieldsTables !== [] || $this->refreshTableList) {
            $dictionary = new DictionaryReader($connection);

            foreach ($this->fieldsTables as $table) {
                try {
                    $schemaCache->storeFields($table, $dictionary->fields($table));
                } catch (Throwable $e) {
                    Log::warning("snow-driver: échec du rafraîchissement du cache de schéma (fields) pour la table \"{$table}\" : {$e->getMessage()}");
                }
            }

            if ($this->refreshTableList) {
                try {
                    $schemaCache->storeTableNames($dictionary->tableNames());
                } catch (Throwable $e) {
                    Log::warning("snow-driver: échec du rafraîchissement du cache de la liste des tables : {$e->getMessage()}");
                }
            }
        }

        foreach ($this->countTables as $table) {
            try {
                $schemaCache->storeCount($table, $connection->countLive($table));
            } catch (Throwable $e) {
                Log::warning("snow-driver: échec du rafraîchissement du cache de schéma (count) pour la table \"{$table}\" : {$e->getMessage()}");
            }
        }
    }
}

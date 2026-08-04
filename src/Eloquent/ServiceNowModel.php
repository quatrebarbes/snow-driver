<?php

namespace Quatrebarbes\SnowDriver\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Quatrebarbes\SnowDriver\Http\TableApiClient;
use RuntimeException;

/**
 * Classe de base pour tout modèle Eloquent mappé à une table ServiceNow.
 *
 * EX-105 : le nom de la table est résolu selon la convention Eloquent
 * standard héritée de Model::getTable() (nom dérivé de la classe, ou
 * surcharge explicite via la propriété $table) ; aucune surcharge n'est
 * nécessaire ici pour la conserver.
 */
abstract class ServiceNowModel extends Model
{
    /**
     * EX-106 : sys_id est une chaîne de 32 caractères hexadécimaux générée
     * par ServiceNow, jamais auto-incrémentée.
     */
    protected $primaryKey = 'sys_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * EX-107 : mapping sur les champs ServiceNow natifs, pour conserver la
     * gestion automatique des timestamps par Eloquent (renseignement à la
     * création/modification, cast en date).
     */
    const CREATED_AT = 'sys_created_on';

    const UPDATED_AT = 'sys_updated_on';

    /**
     * Connexion ServiceNow par défaut (config/servicenow.php), sauf
     * surcharge explicite de $connection par le modèle applicatif.
     */
    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('servicenow.default', 'servicenow');
    }

    /**
     * Client HTTP Table API de la connexion ServiceNow de ce modèle.
     */
    public function tableApi(): TableApiClient
    {
        return $this->getConnection()->tableApi();
    }

    /**
     * EX-112 : création via POST sur l'API Table, au travers de save() sur
     * une nouvelle instance.
     *
     * On ne renseigne pas les timestamps localement (pas d'appel à
     * updateTimestamps()) : sys_created_on/sys_updated_on sont calculés côté
     * serveur et récupérés depuis la réponse (EX-115), plutôt que de risquer
     * une valeur locale divergente.
     */
    protected function performInsert(Builder $query)
    {
        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        $response = $this->tableApi()->post(
            '/api/now/table/'.$this->getTable(),
            $this->getAttributesForInsert()
        );

        // EX-115 : le modèle est actualisé avec les valeurs retournées par
        // ServiceNow (sys_id généré, timestamps, valeurs par défaut serveur...).
        $this->setRawAttributes($response, true);

        $this->exists = true;
        $this->wasRecentlyCreated = true;

        $this->fireModelEvent('created', false);

        return true;
    }

    /**
     * EX-113 : modification via PATCH (mise à jour partielle) sur l'API
     * Table, au travers de save() sur une instance existante ou update().
     *
     * EX-124 : aucune détection de conflit de modification concurrente
     * n'est effectuée ici (pas de comparaison de version, pas d'en-tête
     * conditionnel) ; le comportement observé en cas d'écritures
     * concurrentes sur le même sys_id est celui, nativement appliqué par
     * l'API ServiceNow, du dernier écrivain gagnant.
     */
    protected function performUpdate(Builder $query)
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        $dirty = $this->getDirtyForUpdate();

        if (count($dirty) > 0) {
            $response = $this->tableApi()->patch(
                '/api/now/table/'.$this->getTable().'/'.$this->getKeyForSaveQuery(),
                $dirty
            );

            // EX-115 : le modèle est actualisé avec les valeurs retournées
            // par ServiceNow. syncChanges() doit être appelé avant, tant que
            // getDirty() reflète encore l'écart pré-modification.
            $this->syncChanges();

            $this->setRawAttributes($response, true);

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * EX-114 : suppression via DELETE sur l'API Table, au travers de
     * delete().
     */
    protected function performDeleteOnModel()
    {
        $this->tableApi()->delete(
            '/api/now/table/'.$this->getTable().'/'.$this->getKeyForSaveQuery()
        );

        $this->exists = false;
    }

    /**
     * EX-123 : traitement best-effort d'une opération groupée. Chaque modèle
     * est sauvegardé indépendamment des autres : l'échec de l'un ne remet
     * pas en cause les enregistrements du même lot déjà sauvegardés avec
     * succès (aucun rollback applicatif, l'API Table ServiceNow ne proposant
     * pas de transaction multi-requêtes native), et le détail des
     * succès/échecs est retourné à l'appelant.
     *
     * @param  iterable<static>  $models
     */
    public static function saveMany(iterable $models): SaveManyResult
    {
        $successes = [];
        $failures = [];

        foreach ($models as $model) {
            try {
                $model->save();
                $successes[] = $model;
            } catch (RuntimeException $e) {
                $failures[] = new SaveManyFailure($model, $e);
            }
        }

        return new SaveManyResult($successes, $failures);
    }
}

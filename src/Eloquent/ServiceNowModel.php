<?php

namespace Quatrebarbes\SnowDriver\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Quatrebarbes\SnowDriver\Http\TableApiClient;

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
}

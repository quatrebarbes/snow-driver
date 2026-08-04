<?php

namespace Quatrebarbes\SnowDriver\Tests\Fixtures;

use Quatrebarbes\SnowDriver\Eloquent\ServiceNowModel;

/**
 * Fixture cible d'une relation belongsTo (EX-116) : table référencée par le
 * champ reference "company" de Incident.
 */
class Company extends ServiceNowModel
{
    protected $guarded = [];
}

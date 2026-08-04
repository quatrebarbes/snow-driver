<?php

namespace Quatrebarbes\SnowDriver\Tests\Fixtures;

use Quatrebarbes\SnowDriver\Eloquent\ServiceNowModel;

/**
 * Fixture sans surcharge de $table : sert à vérifier la convention Eloquent
 * standard de résolution du nom de table (EX-105).
 */
class Incident extends ServiceNowModel
{
}

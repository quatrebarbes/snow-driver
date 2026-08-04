<?php

namespace Quatrebarbes\SnowDriver\Tests\Fixtures;

use Quatrebarbes\SnowDriver\Eloquent\ServiceNowModel;

/**
 * Fixture pointant vers une table absente/inaccessible côté ServiceNow,
 * utilisée pour vérifier EX-127.
 */
class NonexistentTableModel extends ServiceNowModel
{
    protected $table = 'nonexistent_table';
}

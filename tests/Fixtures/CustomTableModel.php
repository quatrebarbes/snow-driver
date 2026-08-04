<?php

namespace Quatrebarbes\SnowDriver\Tests\Fixtures;

use Quatrebarbes\SnowDriver\Eloquent\ServiceNowModel;

/**
 * Fixture avec surcharge explicite de $table (EX-105).
 */
class CustomTableModel extends ServiceNowModel
{
    protected $table = 'u_custom_table';
}

<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Eloquent\Casts;

use Quatrebarbes\SnowDriver\Eloquent\Casts\ServiceNowBoolean;
use Quatrebarbes\SnowDriver\Tests\Fixtures\Incident;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-327 : conversion des champs booléens ServiceNow, renvoyés par l'API
 * Table sous forme de chaîne ("true"/"false") plutôt que de booléen JSON
 * natif.
 */
class ServiceNowBooleanTest extends TestCase
{
    public function test_the_string_false_is_read_as_false(): void
    {
        // Le cast natif 'boolean' d'Eloquent ferait `(bool) "false"`, qui
        // vaut true en PHP : toute chaîne non vide est vraie.
        $this->assertFalse((new ServiceNowBoolean)->get(new Incident, 'active', 'false', []));
    }

    public function test_the_string_true_is_read_as_true(): void
    {
        $this->assertTrue((new ServiceNowBoolean)->get(new Incident, 'active', 'true', []));
    }

    public function test_a_null_value_is_read_as_null(): void
    {
        $this->assertNull((new ServiceNowBoolean)->get(new Incident, 'active', null, []));
    }

    public function test_a_native_boolean_value_is_returned_unchanged(): void
    {
        $this->assertFalse((new ServiceNowBoolean)->get(new Incident, 'active', false, []));
        $this->assertTrue((new ServiceNowBoolean)->get(new Incident, 'active', true, []));
    }

    public function test_it_serializes_back_to_the_string_expected_by_servicenow(): void
    {
        $cast = new ServiceNowBoolean;

        $this->assertSame('false', $cast->set(new Incident, 'active', false, []));
        $this->assertSame('true', $cast->set(new Incident, 'active', true, []));
    }

    public function test_a_null_value_is_written_back_as_null(): void
    {
        $this->assertNull((new ServiceNowBoolean)->set(new Incident, 'active', null, []));
    }
}

<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Eloquent\Casts;

use Quatrebarbes\SnowDriver\Eloquent\Casts\ServiceNowBoolean;
use Quatrebarbes\SnowDriver\Eloquent\ServiceNowModel;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-332 : conversion booléenne ServiceNow (chaîne "true"/"false") partagée
 * par l'accessor/mutator que ModelFileGenerator::renderBooleanAccessors()
 * génère pour chaque champ booléen (get{Champ}Attribute()/set{Champ}
 * Attribute(), qui y délègue via read()/write()) et par un usage direct via
 * $casts sur un modèle déclaré manuellement (implémentation CastsAttributes,
 * exercée ici sur un modèle anonyme déclarant le champ "deleted" — un nom
 * choisi à dessein car il coïncide avec Model::deleted($callback), une
 * méthode statique native d'Eloquent, sans que cela n'affecte cet usage via
 * $casts, qui ne porte aucun nom de méthode dérivé du champ).
 */
class ServiceNowBooleanTest extends TestCase
{
    public function test_the_string_false_is_read_as_false(): void
    {
        $this->assertFalse($this->modelWith(['deleted' => 'false'])->deleted);
    }

    public function test_the_string_true_is_read_as_true(): void
    {
        $this->assertTrue($this->modelWith(['deleted' => 'true'])->deleted);
    }

    public function test_a_null_value_is_read_as_null(): void
    {
        $this->assertNull($this->modelWith(['deleted' => null])->deleted);
    }

    public function test_a_native_boolean_value_is_returned_unchanged(): void
    {
        $this->assertFalse($this->modelWith(['deleted' => false])->deleted);
        $this->assertTrue($this->modelWith(['deleted' => true])->deleted);
    }

    public function test_it_serializes_back_to_the_string_expected_by_servicenow(): void
    {
        $model = $this->modelWith([]);

        $model->deleted = false;
        $this->assertSame('false', $model->getAttributes()['deleted']);

        $model->deleted = true;
        $this->assertSame('true', $model->getAttributes()['deleted']);
    }

    public function test_a_null_value_is_written_back_as_null(): void
    {
        $model = $this->modelWith(['deleted' => true]);

        $model->deleted = null;

        $this->assertNull($model->getAttributes()['deleted']);
    }

    public function test_the_static_helpers_match_the_cast_behavior(): void
    {
        $this->assertFalse(ServiceNowBoolean::read('false'));
        $this->assertTrue(ServiceNowBoolean::read('true'));
        $this->assertNull(ServiceNowBoolean::read(null));

        $this->assertSame('false', ServiceNowBoolean::write(false));
        $this->assertSame('true', ServiceNowBoolean::write(true));
        $this->assertNull(ServiceNowBoolean::write(null));
    }

    private function modelWith(array $attributes): ServiceNowModel
    {
        $model = new class extends ServiceNowModel
        {
            protected $table = 'sys_email';

            protected $casts = ['deleted' => ServiceNowBoolean::class];
        };

        $model->setRawAttributes($attributes, true);

        return $model;
    }
}

<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Eloquent;

use Quatrebarbes\SnowDriver\Eloquent\Casts\ServiceNowBoolean;
use Quatrebarbes\SnowDriver\Eloquent\ServiceNowModel;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-327, EX-332, EX-336 : accessor/mutator booléen généré par
 * ModelFileGenerator pour chaque champ booléen ServiceNow (Generator\
 * ModelFileGenerator::renderBooleanAccessors()), reproduit ici sur un modèle
 * anonyme pour en vérifier le comportement : l'API Table de ServiceNow
 * renvoie ces champs sous forme de chaîne ("true"/"false") plutôt que de
 * booléen JSON natif.
 *
 * EX-336 : l'accessor/mutator généré est nommé get{Champ}Attribute()/
 * set{Champ}Attribute() (convention historique d'Eloquent), plutôt que par
 * une méthode portant exactement le nom du champ (Attribute::make()) : ce
 * dernier nom entrerait en collision, pour un champ nommé comme l'une des
 * méthodes statiques natives d'enregistrement d'événement d'Eloquent (ex.
 * "deleted"), avec une méthode statique héritée que PHP interdit de
 * redéclarer en méthode non statique dans la classe fille.
 *
 * Chaque accessor/mutator généré délègue à Eloquent\Casts\ServiceNowBoolean::
 * read()/write() plutôt que de répéter la conversion :
 * test_several_boolean_fields_on_the_same_model_share_the_same_conversion()
 * vérifie qu'un modèle à plusieurs champs booléens n'a besoin d'écrire cette
 * logique qu'une seule fois.
 */
class BooleanAccessorTest extends TestCase
{
    public function test_the_string_false_is_read_as_false(): void
    {
        // Le cast natif 'boolean' seul ferait `(bool) "false"`, qui vaut
        // true en PHP : toute chaîne non vide est vraie.
        $this->assertFalse($this->modelWith(['active' => 'false'])->active);
    }

    public function test_the_string_true_is_read_as_true(): void
    {
        $this->assertTrue($this->modelWith(['active' => 'true'])->active);
    }

    public function test_a_null_value_is_read_as_null(): void
    {
        $this->assertNull($this->modelWith(['active' => null])->active);
    }

    public function test_a_native_boolean_value_is_returned_unchanged(): void
    {
        $this->assertFalse($this->modelWith(['active' => false])->active);
        $this->assertTrue($this->modelWith(['active' => true])->active);
    }

    public function test_it_serializes_back_to_the_string_expected_by_servicenow(): void
    {
        $model = $this->modelWith([]);

        $model->active = false;
        $this->assertSame('false', $model->getAttributes()['active']);

        $model->active = true;
        $this->assertSame('true', $model->getAttributes()['active']);
    }

    public function test_a_null_value_is_written_back_as_null(): void
    {
        $model = $this->modelWith(['active' => true]);

        $model->active = null;

        $this->assertNull($model->getAttributes()['active']);
    }

    public function test_several_boolean_fields_on_the_same_model_share_the_same_conversion(): void
    {
        $model = new class extends ServiceNowModel
        {
            protected $table = 'incidents';

            protected $casts = ['active' => 'boolean', 'do_not_notify' => 'boolean'];

            protected function getActiveAttribute($value)
            {
                return ServiceNowBoolean::read($value);
            }

            protected function setActiveAttribute($value)
            {
                $this->attributes['active'] = ServiceNowBoolean::write($value);
            }

            protected function getDoNotNotifyAttribute($value)
            {
                return ServiceNowBoolean::read($value);
            }

            protected function setDoNotNotifyAttribute($value)
            {
                $this->attributes['do_not_notify'] = ServiceNowBoolean::write($value);
            }
        };

        $model->setRawAttributes(['active' => 'false', 'do_not_notify' => 'true'], true);

        $this->assertFalse($model->active);
        $this->assertTrue($model->do_not_notify);
    }

    public function test_a_field_named_like_a_reserved_eloquent_event_method_uses_the_same_convention_without_conflict(): void
    {
        // EX-336 : "deleted" coïncide avec Model::deleted($callback), une
        // méthode statique native d'Eloquent -- get/setDeletedAttribute()
        // n'y entre pas en collision, contrairement à une méthode qui se
        // serait appelée deleted().
        $model = new class extends ServiceNowModel
        {
            protected $table = 'sys_email';

            protected $casts = ['deleted' => 'boolean'];

            protected function getDeletedAttribute($value)
            {
                return ServiceNowBoolean::read($value);
            }

            protected function setDeletedAttribute($value)
            {
                $this->attributes['deleted'] = ServiceNowBoolean::write($value);
            }
        };

        $model->setRawAttributes(['deleted' => 'false'], true);

        $this->assertFalse($model->deleted);
    }

    private function modelWith(array $attributes): ServiceNowModel
    {
        $model = new class extends ServiceNowModel
        {
            protected $table = 'incidents';

            protected $casts = ['active' => 'boolean'];

            protected function getActiveAttribute($value)
            {
                return ServiceNowBoolean::read($value);
            }

            protected function setActiveAttribute($value)
            {
                $this->attributes['active'] = ServiceNowBoolean::write($value);
            }
        };

        $model->setRawAttributes($attributes, true);

        return $model;
    }
}

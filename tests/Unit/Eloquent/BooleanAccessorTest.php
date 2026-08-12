<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Eloquent;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Quatrebarbes\SnowDriver\Eloquent\ServiceNowModel;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-327 : accessor/mutator booléen généré par ModelFileGenerator pour
 * chaque champ booléen ServiceNow (Generator\ModelFileGenerator::
 * renderBooleanAccessors()), reproduit ici sur un modèle anonyme pour en
 * vérifier le comportement : l'API Table de ServiceNow renvoie ces champs
 * sous forme de chaîne ("true"/"false") plutôt que de booléen JSON natif.
 *
 * Chaque accessor/mutator généré délègue à
 * ServiceNowModel::serviceNowBooleanAttribute() plutôt que de répéter la
 * conversion : test_several_boolean_fields_on_the_same_model_share_the_same_conversion()
 * vérifie qu'un modèle à plusieurs champs booléens n'a besoin d'écrire
 * cette logique qu'une seule fois.
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

            protected function active(): Attribute
            {
                return static::serviceNowBooleanAttribute();
            }

            protected function doNotNotify(): Attribute
            {
                return static::serviceNowBooleanAttribute();
            }
        };

        $model->setRawAttributes(['active' => 'false', 'do_not_notify' => 'true'], true);

        $this->assertFalse($model->active);
        $this->assertTrue($model->do_not_notify);
    }

    private function modelWith(array $attributes): ServiceNowModel
    {
        $model = new class extends ServiceNowModel
        {
            protected $table = 'incidents';

            protected $casts = ['active' => 'boolean'];

            protected function active(): Attribute
            {
                return static::serviceNowBooleanAttribute();
            }
        };

        $model->setRawAttributes($attributes, true);

        return $model;
    }
}

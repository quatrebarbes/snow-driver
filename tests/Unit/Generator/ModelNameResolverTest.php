<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Generator;

use Quatrebarbes\SnowDriver\Generator\ModelNameResolver;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-203, EX-205, EX-207, EX-210, EX-211 : dérivations de noms pures utilisées
 * par la génération automatique de modèles (module 2).
 */
class ModelNameResolverTest extends TestCase
{
    public function test_class_name_is_derived_from_the_table_name_in_studly_case(): void
    {
        // EX-203
        $this->assertSame('Incident', ModelNameResolver::className('incident'));
        $this->assertSame('CoreCompany', ModelNameResolver::className('core_company'));
        $this->assertSame('ScTask', ModelNameResolver::className('sc_task'));
    }

    public function test_belongs_to_method_suffixes_the_camel_cased_field_name_with_record(): void
    {
        // EX-207
        $this->assertSame('companyRecord', ModelNameResolver::belongsToMethod('company'));
        $this->assertSame('parentIncidentRecord', ModelNameResolver::belongsToMethod('parent_incident'));
    }

    public function test_has_many_method_is_the_plural_of_the_source_table_when_unambiguous(): void
    {
        // EX-210
        $this->assertSame('tasks', ModelNameResolver::hasManyMethod('task', 'incident', false));
        $this->assertSame('scTasks', ModelNameResolver::hasManyMethod('sc_task', 'incident', false));
    }

    public function test_has_many_method_is_disambiguated_by_field_name_when_ambiguous(): void
    {
        // EX-211 : deux champs reference d'une même table source vers la
        // même cible (task.incident et task.parent_incident -> Incident).
        $this->assertSame('tasksIncident', ModelNameResolver::hasManyMethod('task', 'incident', true));
        $this->assertSame('tasksParentIncident', ModelNameResolver::hasManyMethod('task', 'parent_incident', true));
    }

    public function test_reference_fields_keeps_only_reference_fields_with_a_known_target_table(): void
    {
        // EX-206, EX-208 : les champs non-reference et les références sans
        // table cible résolue par le dictionnaire (EX-311) sont écartés.
        $fields = [
            ['element' => 'company', 'internal_type' => 'reference', 'reference_table' => 'core_company'],
            ['element' => 'short_description', 'internal_type' => 'string', 'reference_table' => null],
            ['element' => 'watch_list', 'internal_type' => 'glide_list', 'reference_table' => null],
            ['element' => 'orphan_reference', 'internal_type' => 'Reference', 'reference_table' => null],
        ];

        $this->assertSame(
            [['element' => 'company', 'internal_type' => 'reference', 'reference_table' => 'core_company']],
            ModelNameResolver::referenceFields($fields)
        );
    }

    public function test_namespace_path_resolves_a_namespace_rooted_under_app(): void
    {
        // EX-205, EX-202
        $this->assertSame(app_path(), ModelNameResolver::namespacePath('App'));
        $this->assertSame(app_path('Models'), ModelNameResolver::namespacePath('App\\Models'));
        $this->assertSame(app_path('Models/ServiceNow'), ModelNameResolver::namespacePath('App\\Models\\ServiceNow'));
    }

    public function test_namespace_path_is_unresolvable_outside_of_app(): void
    {
        // Limite SFD : namespace non enraciné sous App\.
        $this->assertNull(ModelNameResolver::namespacePath('Modules\\ServiceNow\\Models'));
        $this->assertNull(ModelNameResolver::namespacePath('AppModels'));
    }
}

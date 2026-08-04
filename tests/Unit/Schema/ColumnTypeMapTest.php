<?php

namespace Quatrebarbes\SnowDriver\Tests\Unit\Schema;

use Quatrebarbes\SnowDriver\Schema\ColumnTypeMap;
use Quatrebarbes\SnowDriver\Tests\TestCase;

/**
 * EX-306 à EX-309 : correspondance des types internes ServiceNow vers le
 * vocabulaire de types reconnu par les outils d'introspection Laravel.
 */
class ColumnTypeMapTest extends TestCase
{
    public function test_it_maps_a_boolean_field(): void
    {
        // EX-306
        $this->assertSame('boolean', ColumnTypeMap::typeName('boolean'));
    }

    public function test_it_maps_numeric_fields(): void
    {
        // EX-306
        $this->assertSame('integer', ColumnTypeMap::typeName('integer'));
        $this->assertSame('decimal', ColumnTypeMap::typeName('decimal'));
        $this->assertSame('decimal', ColumnTypeMap::typeName('currency'));
        $this->assertSame('decimal', ColumnTypeMap::typeName('float'));
    }

    public function test_it_maps_date_and_time_fields(): void
    {
        // EX-306
        $this->assertSame('date', ColumnTypeMap::typeName('glide_date'));
        $this->assertSame('datetime', ColumnTypeMap::typeName('glide_date_time'));
        $this->assertSame('datetime', ColumnTypeMap::typeName('due_date'));
        $this->assertSame('time', ColumnTypeMap::typeName('glide_time'));
        $this->assertSame('time', ColumnTypeMap::typeName('glide_duration'));
    }

    public function test_it_maps_json_fields(): void
    {
        // EX-306
        $this->assertSame('json', ColumnTypeMap::typeName('json'));
    }

    public function test_it_maps_long_text_fields_distinctly_from_short_strings(): void
    {
        // EX-308 : le texte long doit rester distinguable d'une chaîne courte.
        $this->assertSame('text', ColumnTypeMap::typeName('journal_input'));
        $this->assertSame('text', ColumnTypeMap::typeName('html'));
        $this->assertSame('text', ColumnTypeMap::typeName('script'));
        $this->assertSame('varchar', ColumnTypeMap::typeName('string'));
    }

    public function test_it_is_case_insensitive(): void
    {
        // EX-306 : la casse du type interne renvoyé par le dictionnaire ne
        // doit pas décider de la correspondance.
        $this->assertSame('boolean', ColumnTypeMap::typeName('BOOLEAN'));
        $this->assertSame('datetime', ColumnTypeMap::typeName('Glide_Date_Time'));
    }

    public function test_an_unknown_internal_type_falls_back_to_a_string(): void
    {
        // EX-307 : un type inédit ne doit pas faire échouer l'introspection.
        $this->assertSame('varchar', ColumnTypeMap::typeName('un_type_inedit_de_future_version'));
        $this->assertSame('varchar', ColumnTypeMap::typeName('reference'));
        $this->assertSame('varchar', ColumnTypeMap::typeName('choice'));
    }

    public function test_the_full_type_carries_the_max_length_of_a_short_string(): void
    {
        // EX-309
        $this->assertSame('varchar(40)', ColumnTypeMap::type('string', 40));
    }

    public function test_the_full_type_omits_a_length_without_meaning(): void
    {
        // EX-309 : la longueur déclarée n'est portée que pour une chaîne
        // courte, seul cas où elle renseigne un outil hôte.
        $this->assertSame('text', ColumnTypeMap::type('journal_input', 4000));
        $this->assertSame('integer', ColumnTypeMap::type('integer', 40));
        $this->assertSame('varchar', ColumnTypeMap::type('string', null));
        $this->assertSame('varchar', ColumnTypeMap::type('string', 0));
    }
}

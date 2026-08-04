<?php

namespace Quatrebarbes\SnowDriver\Schema;

/**
 * EX-306 à EX-308 : correspondance entre le type interne d'un champ
 * ServiceNow (sys_dictionary.internal_type) et le vocabulaire de types que les
 * outils d'introspection standards de Laravel savent reconnaître.
 *
 * Le vocabulaire produit ici est volontairement celui des noms de types SQL
 * (`boolean`, `integer`, `decimal`, `date`, `datetime`, `time`, `json`, `text`,
 * `varchar`) et non celui de ServiceNow : un outil hôte générique compare le
 * nom de type à ces valeurs, sans connaître le driver.
 */
class ColumnTypeMap
{
    /**
     * EX-306 : types internes ServiceNow ayant un équivalent explicite. Tout
     * type absent de cette table relève du repli en chaîne (EX-307).
     *
     * @var array<string, string>
     */
    private const TYPES = [
        // Booléens
        'boolean' => 'boolean',

        // Entiers
        'integer' => 'integer',
        'sysrule_field_count' => 'integer',
        'order_index' => 'integer',

        // Décimaux
        'decimal' => 'decimal',
        'float' => 'decimal',
        'currency' => 'decimal',
        'price' => 'decimal',
        'percent_complete' => 'decimal',

        // Dates et horodatages
        'glide_date' => 'date',
        'glide_date_time' => 'datetime',
        'glide_utc_time' => 'datetime',
        'due_date' => 'datetime',
        'glide_precise_time' => 'datetime',
        'glide_time' => 'time',
        'glide_duration' => 'time',
        'timer' => 'time',

        // Documents structurés
        'json' => 'json',

        // EX-308 : textes longs, distingués d'une chaîne courte pour qu'un
        // outil hôte leur réserve un rendu et une saisie multilignes.
        'journal' => 'text',
        'journal_input' => 'text',
        'journal_list' => 'text',
        'html' => 'text',
        'translated_html' => 'text',
        'script' => 'text',
        'script_plain' => 'text',
        'script_server' => 'text',
        'xml' => 'text',
        'wide_text' => 'text',
        'conditions' => 'text',
    ];

    /**
     * EX-306, EX-307 : nom de type exposé pour un type interne ServiceNow,
     * `varchar` par défaut pour tout type sans correspondance connue (choice,
     * reference, email, url, GUID, table_name, type inédit d'une future
     * version d'instance...), afin de ne jamais faire échouer l'introspection
     * d'une table sur un champ inattendu.
     */
    public static function typeName(string $internalType): string
    {
        return self::TYPES[strtolower($internalType)] ?? 'varchar';
    }

    /**
     * EX-309 : type complet de la colonne, longueur maximale déclarée au
     * dictionnaire comprise lorsqu'elle a un sens pour le type (chaînes
     * courtes uniquement : la longueur d'un texte long ou d'un entier
     * n'apporte rien à un outil hôte, qui ne s'en sert que pour dimensionner
     * une saisie).
     */
    public static function type(string $internalType, ?int $maxLength): string
    {
        $typeName = self::typeName($internalType);

        if ($typeName === 'varchar' && $maxLength !== null && $maxLength > 0) {
            return 'varchar('.$maxLength.')';
        }

        return $typeName;
    }
}

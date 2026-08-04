<?php

namespace Quatrebarbes\SnowDriver\Generator;

use Illuminate\Support\Str;

/**
 * Dérivations de noms pures (aucun accès réseau ni disque) utilisées par
 * ModelFileGenerator pour la génération automatique de modèles (module 2).
 */
class ModelNameResolver
{
    /**
     * EX-203 : nom de classe dérivé du nom technique de la table en casse
     * Pascal (StudlyCase).
     */
    public static function className(string $table): string
    {
        return Str::studly($table);
    }

    /**
     * EX-207 : méthode belongsTo() nommée d'après le nom du champ reference
     * suffixé de "Record" (ex : champ company -> companyRecord()).
     */
    public static function belongsToMethod(string $field): string
    {
        return Str::camel($field).'Record';
    }

    /**
     * EX-210, EX-211 : méthode hasMany() nommée d'après le pluriel StudlyCase
     * du nom technique de la table source (ex : table task -> tasks()),
     * désambiguïsée par le nom du champ reference en casse Pascal lorsque
     * plusieurs champs d'une même table source pointent vers la même cible
     * (ex : tasksIncident(), tasksParentIncident()).
     */
    public static function hasManyMethod(string $sourceTable, string $field, bool $ambiguous): string
    {
        $base = Str::plural(Str::camel($sourceTable));

        return $ambiguous ? $base.Str::studly($field) : $base;
    }

    /**
     * EX-206, EX-207, EX-209 : parmi les champs d'une table (au format
     * retourné par DictionaryReader::fields()), ceux de type reference dont
     * la table cible est connue. Les champs référençant indirectement
     * d'autres tables sans être de type reference strict (glide_list,
     * choice) sont exclus de fait, seul le type interne "reference" étant
     * retenu (même exclusion qu'EX-208/EX-313).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    public static function referenceFields(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn (array $field) => strtolower($field['internal_type'] ?? '') === 'reference'
                && ($field['reference_table'] ?? null) !== null
        ));
    }

    /**
     * EX-202, limite SFD : résout le dossier de destination des modèles
     * générés à partir du namespace configuré, selon les règles PSR-4
     * standards d'une application Laravel (namespace enraciné sous App\,
     * mappé sur le dossier app/). Retourne null si le namespace n'est pas
     * résoluble ainsi, plutôt que de deviner ou de forcer un chemin
     * arbitraire.
     */
    public static function namespacePath(string $namespace): ?string
    {
        $namespace = trim($namespace, '\\');

        if ($namespace !== 'App' && ! str_starts_with($namespace, 'App\\')) {
            return null;
        }

        $relative = ltrim(substr($namespace, strlen('App')), '\\');
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);

        return $relative === '' ? app_path() : app_path($relative);
    }
}

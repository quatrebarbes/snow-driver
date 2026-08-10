<?php

namespace Quatrebarbes\SnowDriver\Generator;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Quatrebarbes\SnowDriver\Connection\ServiceNowConnection;
use Quatrebarbes\SnowDriver\Schema\ColumnTypeMap;
use Quatrebarbes\SnowDriver\Schema\DictionaryReader;
use Throwable;

/**
 * EX-202, EX-203 : génère, au démarrage de l'application hôte, un fichier de
 * modèle Eloquent pour chaque table ServiceNow configurée dont le fichier
 * n'existe pas encore, avec ses relations belongsTo (EX-206 à EX-208), ses
 * relations hasMany réciproques (EX-209 à EX-211), ses champs modifiables et
 * ses conversions (EX-325 à EX-327).
 */
class ModelFileGenerator
{
    /**
     * EX-326 : champs techniques gérés par ServiceNow, exclus des champs
     * modifiables des modèles générés (leur modification est de toute façon
     * rejetée par l'instance).
     */
    private const TECHNICAL_FIELDS = [
        'sys_id',
        'sys_created_on',
        'sys_updated_on',
        'sys_created_by',
        'sys_updated_by',
        'sys_mod_count',
    ];

    /**
     * EX-327 : types internes ServiceNow disposant d'un équivalent de
     * conversion Eloquent, sous la forme du fragment PHP à placer tel quel
     * après le "=>" dans $casts.
     *
     * Le type booléen utilise le cast natif 'boolean' d'Eloquent, complété
     * d'un accessor/mutator dédié généré pour chaque champ concerné (cf.
     * renderBooleanAccessors()) : ServiceNow renvoie ces champs sous forme
     * de chaîne ("true"/"false"), et `(bool) "false"` vaut toujours `true`
     * en PHP, toute chaîne non vide étant vraie — l'accessor/mutator prend
     * le pas sur le cast natif pour la lecture et l'écriture réelles.
     *
     * @var array<string, string>
     */
    private const CASTS = [
        'boolean' => "'boolean'",
        'integer' => "'integer'",
        'decimal' => "'decimal:2'",
        'date' => "'date'",
        'datetime' => "'datetime'",
    ];

    public function __construct(private readonly ServiceNowConnection $connection)
    {
    }

    /**
     * @param  array<int, string>  $tables
     */
    public function generate(array $tables, string $namespace): void
    {
        // EX-201, limite SFD : tableau vide -> aucun effet.
        if ($tables === []) {
            return;
        }

        $basePath = ModelNameResolver::namespacePath($namespace);

        if ($basePath === null) {
            // Limite SFD : namespace non enraciné sous App\ -> signalement
            // explicite, sans bloquer le démarrage de l'application hôte.
            Log::warning("snow-driver: impossible de résoudre un chemin de fichier pour le namespace de modèles \"{$namespace}\" (doit être enraciné sous App\\) ; génération de modèles ignorée.");

            return;
        }

        // Passe 1 (EX-208, limite SFD) : détermine, sans rien écrire sur le
        // disque, les tables dont le fichier de modèle n'existe pas encore.
        // La résolvabilité des relations en passe 2 se fonde sur cet
        // ensemble plutôt que sur class_exists() au fil du traitement, afin
        // de ne jamais dépendre de l'ordre de traitement des tables au sein
        // d'un même cycle de démarrage.
        $descriptors = [];

        foreach (array_unique($tables) as $table) {
            $class = ModelNameResolver::className($table);
            $path = rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.$class.'.php';

            if (file_exists($path)) {
                continue;
            }

            $descriptors[$table] = ['table' => $table, 'class' => $class, 'path' => $path];
        }

        if ($descriptors === []) {
            return;
        }

        $dictionary = new DictionaryReader($this->connection);
        $generatedTables = array_keys($descriptors);

        // Passe 2 : relations puis écriture.
        foreach ($descriptors as $descriptor) {
            $content = $this->buildContent($descriptor, $namespace, $tables, $generatedTables, $dictionary);

            $this->write($descriptor['path'], $content);
        }
    }

    /**
     * @param  array{table: string, class: string, path: string}  $descriptor
     * @param  array<int, string>  $configuredTables
     * @param  array<int, string>  $generatedTables
     */
    private function buildContent(array $descriptor, string $namespace, array $configuredTables, array $generatedTables, DictionaryReader $dictionary): string
    {
        $fields = $dictionary->fields($descriptor['table']);

        $belongsTo = $this->belongsToRelations($fields, $namespace, $generatedTables);
        $hasMany = $this->hasManyRelations($descriptor['table'], $configuredTables, $namespace, $dictionary);
        $relations = array_merge($belongsTo, $hasMany);
        $booleanFields = $this->booleanFields($fields);

        $stub = file_get_contents(__DIR__.'/stubs/model.stub');

        return strtr($stub, [
            '{{ namespace }}' => $namespace,
            '{{ class }}' => $descriptor['class'],
            '{{ table }}' => $descriptor['table'],
            '{{ uses }}' => $this->renderUses($relations, $booleanFields !== []),
            '{{ fillable }}' => $this->renderFillable($this->fillableFields($fields)),
            '{{ casts }}' => $this->renderCasts($this->castFields($fields)),
            '{{ methods }}' => $this->renderMethods($relations).$this->renderBooleanAccessors($booleanFields),
        ]);
    }

    /**
     * EX-206, EX-207, EX-208 : relations belongsTo vers les champs reference
     * de la table elle-même, uniquement pour les tables cibles résolvables
     * (générées dans ce même cycle, ou disposant déjà d'un modèle Eloquent
     * dans le namespace configuré) ; ignorées silencieusement sinon.
     *
     * EX-312 : la clé étrangère (champ reference) et la clé référencée
     * (sys_id) sont déclarées explicitement dans l'appel à belongsTo().
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<int, string>  $generatedTables
     * @return array<int, array{method: string, type: string, target: string, field: string}>
     */
    private function belongsToRelations(array $fields, string $namespace, array $generatedTables): array
    {
        $relations = [];

        foreach (ModelNameResolver::referenceFields($fields) as $field) {
            $targetTable = $field['reference_table'];
            $targetClass = $namespace.'\\'.ModelNameResolver::className($targetTable);

            $resolvable = in_array($targetTable, $generatedTables, true) || class_exists($targetClass);

            if (! $resolvable) {
                continue;
            }

            $relations[] = [
                'method' => ModelNameResolver::belongsToMethod($field['element']),
                'type' => 'belongsTo',
                'target' => $targetClass,
                'field' => $field['element'],
            ];
        }

        return $relations;
    }

    /**
     * EX-209, EX-210, EX-211 : relations hasMany réciproques, recherchées
     * parmi les seules autres tables déclarées en configuration (limite
     * SFD), pour chaque champ reference pointant vers la table en cours de
     * génération.
     *
     * @param  array<int, string>  $configuredTables
     * @return array<int, array{method: string, type: string, target: string, field: string}>
     */
    private function hasManyRelations(string $targetTable, array $configuredTables, string $namespace, DictionaryReader $dictionary): array
    {
        $relations = [];

        foreach (array_unique($configuredTables) as $sourceTable) {
            if ($sourceTable === $targetTable) {
                continue;
            }

            $referencing = array_values(array_filter(
                ModelNameResolver::referenceFields($dictionary->fields($sourceTable)),
                fn (array $field) => $field['reference_table'] === $targetTable
            ));

            if ($referencing === []) {
                continue;
            }

            $sourceClass = $namespace.'\\'.ModelNameResolver::className($sourceTable);
            $ambiguous = count($referencing) > 1;

            foreach ($referencing as $field) {
                $relations[] = [
                    'method' => ModelNameResolver::hasManyMethod($sourceTable, $field['element'], $ambiguous),
                    'type' => 'hasMany',
                    'target' => $sourceClass,
                    'field' => $field['element'],
                ];
            }
        }

        return $relations;
    }

    /**
     * EX-325, EX-326 : champs modifiables par assignation de masse, limités
     * aux champs inscriptibles (non read_only) et hors champs techniques.
     *
     * EX-330 : un champ virtual (ex. sys_user.name, calculé par un script
     * plutôt que stocké dans une colonne propre) est exclu même s'il n'est
     * pas marqué read_only par le dictionnaire : sans stockage propre, une
     * valeur écrite n'aurait nulle part où être persistée.
     *
     * EX-328, EX-329 : le champ display (s'il figure parmi les champs
     * modifiables) est placé en tête, suivi des champs mandatory, puis des
     * autres champs modifiables — dans chaque groupe, l'ordre d'EX-304 est
     * préservé. En l'absence de champ display modifiable, la liste commence
     * directement par les champs mandatory. Si plusieurs champs sont marqués
     * display (dictionnaire non conforme aux conventions ServiceNow), seul le
     * premier rencontré dans l'ordre d'EX-304 est placé en tête ; les autres
     * restent classés selon leur seul caractère mandatory.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, string>
     */
    private function fillableFields(array $fields): array
    {
        $writable = array_values(array_filter(
            $fields,
            fn (array $field) => ! $field['read_only'] && ! $field['virtual'] && ! in_array($field['element'], self::TECHNICAL_FIELDS, true)
        ));

        $displayIndex = null;

        foreach ($writable as $index => $field) {
            if ($field['display']) {
                $displayIndex = $index;
                break;
            }
        }

        $display = [];

        if ($displayIndex !== null) {
            $display[] = $writable[$displayIndex];
            unset($writable[$displayIndex]);
        }

        $mandatory = array_filter($writable, fn (array $field) => $field['mandatory']);
        $rest = array_filter($writable, fn (array $field) => ! $field['mandatory']);

        $ordered = array_merge($display, $mandatory, $rest);

        return array_values(array_map(fn (array $field) => $field['element'], $ordered));
    }

    /**
     * EX-327 : conversions Eloquent pour les champs dont le type ServiceNow
     * admet un équivalent (booléen, entier, décimal, horodatage), hors
     * champs techniques (déjà gérés nativement par le mapping CREATED_AT /
     * UPDATED_AT pour sys_created_on / sys_updated_on).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, string>
     */
    private function castFields(array $fields): array
    {
        $casts = [];

        foreach ($fields as $field) {
            if (in_array($field['element'], self::TECHNICAL_FIELDS, true)) {
                continue;
            }

            $typeName = ColumnTypeMap::typeName($field['internal_type']);

            if (isset(self::CASTS[$typeName])) {
                $casts[$field['element']] = self::CASTS[$typeName];
            }
        }

        return $casts;
    }

    /**
     * EX-327 : champs booléens pour lesquels un accessor/mutator dédié est
     * généré (cf. renderBooleanAccessors()), en complément du cast natif
     * 'boolean' déclaré par castFields() dans $casts.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, string>
     */
    private function booleanFields(array $fields): array
    {
        return array_values(array_map(
            fn (array $field) => $field['element'],
            array_filter(
                $fields,
                fn (array $field) => ! in_array($field['element'], self::TECHNICAL_FIELDS, true)
                    && ColumnTypeMap::typeName($field['internal_type']) === 'boolean'
            )
        ));
    }

    /**
     * @param  array<int, array{method: string, type: string, target: string, field: string}>  $relations
     */
    private function renderUses(array $relations, bool $hasBooleanAccessors): string
    {
        $types = array_unique(array_column($relations, 'type'));

        $uses = [];

        if (in_array('belongsTo', $types, true)) {
            $uses[] = 'use Illuminate\Database\Eloquent\Relations\BelongsTo;';
        }

        if (in_array('hasMany', $types, true)) {
            $uses[] = 'use Illuminate\Database\Eloquent\Relations\HasMany;';
        }

        if ($hasBooleanAccessors) {
            $uses[] = 'use Illuminate\Database\Eloquent\Casts\Attribute;';
        }

        if ($uses === []) {
            return '';
        }

        return implode("\n", $uses)."\n";
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function renderFillable(array $fields): string
    {
        if ($fields === []) {
            return '';
        }

        $items = implode(', ', array_map(fn (string $field) => "'{$field}'", $fields));

        return "\n    protected \$fillable = [{$items}];\n";
    }

    /**
     * @param  array<string, string>  $casts
     */
    private function renderCasts(array $casts): string
    {
        if ($casts === []) {
            return '';
        }

        $lines = [];

        foreach ($casts as $field => $cast) {
            $lines[] = "        '{$field}' => {$cast},";
        }

        return "\n    protected \$casts = [\n".implode("\n", $lines)."\n    ];\n";
    }

    /**
     * @param  array<int, array{method: string, type: string, target: string, field: string}>  $relations
     */
    private function renderMethods(array $relations): string
    {
        if ($relations === []) {
            return '';
        }

        $blocks = array_map(function (array $relation): string {
            $returnType = $relation['type'] === 'belongsTo' ? 'BelongsTo' : 'HasMany';

            return "\n    public function {$relation['method']}(): {$returnType}\n".
                "    {\n".
                "        return \$this->{$relation['type']}(\\{$relation['target']}::class, '{$relation['field']}', 'sys_id');\n".
                "    }\n";
        }, $relations);

        return "\n".implode('', $blocks);
    }

    /**
     * EX-327 : accessor/mutator dédié à chaque champ booléen, qui prend le
     * pas sur le cast natif 'boolean' déclaré dans $casts pour la lecture
     * (une chaîne "true"/"false" comparée explicitement, plutôt que
     * `(bool) $value`, toujours vrai pour "false") et l'écriture (valeur
     * PHP reconvertie vers la chaîne attendue par l'API Table ServiceNow).
     *
     * @param  array<int, string>  $fields
     */
    private function renderBooleanAccessors(array $fields): string
    {
        if ($fields === []) {
            return '';
        }

        $blocks = array_map(function (string $field): string {
            $method = Str::camel($field);

            return "\n    protected function {$method}(): Attribute\n".
                "    {\n".
                "        return Attribute::make(\n".
                "            get: fn (\$value) => \$value === null ? null : (is_bool(\$value) ? \$value : strtolower((string) \$value) === 'true'),\n".
                "            set: fn (\$value) => \$value === null ? null : (\$value ? 'true' : 'false'),\n".
                "        );\n".
                "    }\n";
        }, $fields);

        return "\n".implode('', $blocks);
    }

    /**
     * Limite SFD : un échec d'écriture (filesystem en lecture seule au
     * démarrage) est signalé sans bloquer le démarrage de l'application
     * hôte.
     */
    private function write(string $path, string $content): void
    {
        try {
            $directory = dirname($path);

            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new \RuntimeException("impossible de créer le dossier {$directory}");
            }

            if (@file_put_contents($path, $content) === false) {
                throw new \RuntimeException("impossible d'écrire le fichier {$path}");
            }
        } catch (Throwable $e) {
            Log::warning("snow-driver: échec de génération du modèle {$path} : {$e->getMessage()}");
        }
    }
}

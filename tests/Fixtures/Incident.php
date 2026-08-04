<?php

namespace Quatrebarbes\SnowDriver\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Quatrebarbes\SnowDriver\Eloquent\ServiceNowModel;

/**
 * Fixture sans surcharge de $table : sert à vérifier la convention Eloquent
 * standard de résolution du nom de table (EX-105).
 */
class Incident extends ServiceNowModel
{
    protected $guarded = [];

    /**
     * EX-116, EX-117 : champ reference "company" exposé comme relation
     * belongsTo Eloquent standard vers la table companies.
     *
     * Nommée différemment du champ "company" lui-même (comme on nommerait
     * "user_id" -> user()) : sinon, l'accès dynamique $model->company
     * renverrait l'attribut brut (le sys_id) plutôt que la relation, la
     * clé "company" existant déjà dans $attributes — un comportement
     * standard d'Eloquent, sans rapport avec ce driver.
     */
    public function companyRecord(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company', 'sys_id');
    }
}

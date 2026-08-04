<?php

// Fixture chargée explicitement via require_once par
// ServiceNowModelGenerationTest::test_belongs_to_relation_is_generated_towards_an_existing_manually_declared_model() :
// simule un modèle App\Models\CoreCompany déjà déclaré manuellement par
// l'application hôte, sans dépendre de l'autoloader PSR-4 réel de
// l'application de test (découplé du dossier temporaire utilisé par le test).

namespace App\Models;

use Quatrebarbes\SnowDriver\Eloquent\ServiceNowModel;

if (! class_exists(__NAMESPACE__.'\\CoreCompany', false)) {
    class CoreCompany extends ServiceNowModel
    {
        protected $table = 'core_company';
    }
}

<?php

/*
|--------------------------------------------------------------------------
| Tables ServiceNow de la démo
|--------------------------------------------------------------------------
|
| Liste curatée des tables affichées dans le menu de l'application de
| démo (Phase 5 de la roadmap). Chaque entrée définit un libellé et les
| colonnes affichées dans la liste des enregistrements ; à défaut de
| colonnes déclarées, les premiers champs retournés par l'API sont utilisés.
|
*/

return [

    'tables' => [
        'incident' => [
            'label' => 'Incidents',
            'columns' => ['number', 'short_description', 'priority', 'state', 'sys_created_on'],
        ],
        'problem' => [
            'label' => 'Problèmes',
            'columns' => ['number', 'short_description', 'state', 'sys_created_on'],
        ],
        'change_request' => [
            'label' => 'Demandes de changement',
            'columns' => ['number', 'short_description', 'state', 'sys_created_on'],
        ],
        'sc_request' => [
            'label' => 'Demandes de service',
            'columns' => ['number', 'short_description', 'stage', 'sys_created_on'],
        ],
        'sys_user' => [
            'label' => 'Utilisateurs',
            'columns' => ['user_name', 'name', 'email', 'active'],
        ],
        'cmdb_ci' => [
            'label' => 'Éléments de configuration (CMDB)',
            'columns' => ['name', 'sys_class_name', 'operational_status'],
        ],
        'kb_knowledge' => [
            'label' => 'Articles de connaissance',
            'columns' => ['number', 'short_description', 'workflow_state'],
        ],
        'core_company' => [
            'label' => 'Sociétés',
            'columns' => ['name', 'phone', 'country'],
        ],
    ],

    'page_size' => 20,

];

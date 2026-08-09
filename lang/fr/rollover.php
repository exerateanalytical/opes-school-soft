<?php

declare(strict_types=1);

// Libellés des énumérations de l'assistant de bascule d'année
// (docs/specs/08-operations.md §6.2).
return [
    'step' => [
        '0' => 'Vérifications préalables',
        '1' => 'Créer la nouvelle année',
        '2' => 'Niveaux et classes',
        '3' => 'Attributions de matières et coefficients',
        '4' => 'Périodes d\'évaluation',
        '5' => 'Structures de frais et échéanciers',
        '6' => 'Promouvoir les élèves',
        '7' => 'Reporter les soldes',
        '8' => 'Diplômés et partants',
        '9' => 'Réaffectation des enseignants',
        '10' => 'Basculer l\'année active',
    ],
    'run_status' => [
        'running' => 'En cours',
        'completed' => 'Terminée',
        'undone' => 'Annulée',
        'failed' => 'Échouée',
    ],
];

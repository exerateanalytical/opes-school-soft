<?php

declare(strict_types=1);

return [
    'breadcrumb_dashboard' => 'Tableau de bord',
    'breadcrumb_discipline' => 'Discipline',
    'title' => 'Gestion de la discipline',
    'tabs_label' => 'Statut du dossier',
    'tab_all' => 'Tous',

    'kpi_total' => 'Dossiers au total',
    'kpi_open' => 'Dossiers ouverts',
    'kpi_investigating' => 'En enquête',
    'kpi_positive' => 'Entrées positives',
    'kpi_unacknowledged' => 'En attente de signature du tuteur',

    'open_case' => 'Ouvrir un dossier',
    'save_case' => 'Enregistrer le dossier',
    'view_case' => 'Voir le dossier',
    'case_title' => 'Dossier disciplinaire',
    'positive_entry_title' => 'Entrée de comportement positif',
    'case_ref' => 'Dossier n° :id',
    'incident_title' => 'Incident',

    'search_label' => 'Rechercher des dossiers',
    'search_placeholder' => 'Nom de l\'élève ou matricule…',
    'student_search_placeholder' => 'Saisir un nom, matricule ou n° d\'admission…',
    'all_categories' => 'Toutes les catégories',
    'select_category' => 'Choisir une catégorie…',
    'empty' => 'Aucun dossier disciplinaire enregistré.',

    'field_student' => 'Élève',
    'field_category' => 'Catégorie de faute',
    'field_occurred_on' => 'Date de l\'incident',
    'field_description' => 'Description',
    'field_visibility' => 'Visibilité tuteur',
    'field_is_positive' => 'Ceci est une entrée de comportement positif',
    'field_sanction_type' => 'Sanction',
    'field_starts_on' => 'Débute le',
    'field_ends_on' => 'Se termine le',
    'field_notes' => 'Remarques',
    'field_outcome' => 'Issue',
    'field_resolution_note' => 'Note de résolution',

    'col_date' => 'Date',
    'col_student' => 'Élève',
    'col_class' => 'Classe',
    'col_category' => 'Catégorie',
    'col_kind' => 'Entrée',
    'col_status' => 'Statut',
    'severity' => 'Gravité',
    'kind_positive' => 'Positif',
    'kind_incident' => 'Incident',

    'status' => [
        'open' => 'Ouvert',
        'under_investigation' => 'En enquête',
        'resolved' => 'Résolu',
        'dismissed' => 'Classé sans suite',
    ],

    'visibility' => [
        'internal' => 'Interne uniquement',
        'guardian' => 'Visible par le tuteur',
    ],
    'visibility_hint' => 'Les dossiers impliquant un autre mineur nommé restent internes.',
    'visibility_guardian_note' => 'Le tuteur peut consulter le récit de ce dossier sur le portail.',
    'visibility_internal_note' => 'Les tuteurs ne voient que la date, la catégorie et l\'issue — jamais le récit.',

    'sanction' => [
        'warning' => 'Avertissement',
        'detention' => 'Retenue',
        'consigne' => 'Consigne',
        'suspension' => 'Suspension',
        'exclusion' => 'Exclusion',
        'community_service' => 'Travail d\'intérêt général',
        'guardian_summons' => 'Convocation du tuteur',
    ],

    'apply_sanction' => 'Appliquer une sanction',
    'save_sanction' => 'Enregistrer la sanction',
    'sanctions_title' => 'Sanctions',
    'no_sanctions' => 'Aucune sanction n\'a été appliquée à ce dossier.',
    'unknown_sanction_type' => 'Type de sanction inconnu.',
    'sanction_not_on_case' => 'Cette sanction n\'appartient pas à ce dossier.',
    'ladder_suggestion' => 'L\'échelle des sanctions suggère : :type.',
    'ladder_advisory' => 'À titre indicatif seulement — la décision vous appartient.',

    'acknowledgement' => 'Accusé de réception du tuteur',
    'record_acknowledgement' => 'Enregistrer l\'accusé de réception',
    'acknowledged_on' => 'Accusé le :date',
    'unacknowledged' => 'En attente de signature',

    'start_investigation' => 'Ouvrir une enquête',
    'close_case' => 'Clôturer le dossier',
    'confirm_close' => 'Confirmer',
    'dismiss_hint' => 'Un dossier classé sans suite ne compte jamais contre l\'élève.',
    'lifecycle_title' => 'Cycle de vie',
    'resolved_at' => 'Clôturé le',
    'reported_by' => 'Signalé par',
];

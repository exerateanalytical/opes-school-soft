<?php

declare(strict_types=1);

// Chaînes du module Anciens élèves. Garder la structure identique à
// lang/en/alumni.php.

return [
    'title' => 'Anciens élèves',
    'breadcrumb_dashboard' => 'Tableau de bord',
    'detail_title' => "Dossier d'ancien élève",

    'kpi_total' => 'Total anciens élèves',
    'kpi_cohort' => "Promotion de l'année",
    'kpi_engagements' => "Interactions cette année",
    'kpi_reachable' => 'Joignables',

    'filter_year' => "Année d'obtention",
    'filter_occupation' => 'Profession',
    'filter_search' => 'Recherche',
    'filter_search_placeholder' => 'Rechercher un nom ou un matricule...',
    'all_years' => 'Toutes les années',

    'empty' => "Aucun ancien élève ne correspond à ces filtres. Les diplômés apparaissent ici une fois convertis depuis le registre des élèves.",

    'col_name' => 'Nom',
    'col_matricule' => 'Matricule',
    'col_year' => 'Année',
    'col_final_class' => 'Classe finale',
    'col_occupation' => 'Profession',
    'col_contact' => 'Contact',
    'col_engagements' => 'Interactions',
    'col_status' => 'Statut',
    'col_actions' => 'Actions',
    'view' => 'Voir',

    'status_deceased' => 'Décédé(e)',
    'status_reachable' => 'Joignable',
    'status_unreachable' => 'Sans contact',

    'convert_action' => 'Convertir les diplômés',
    'convert_hide' => 'Masquer le panneau',
    'convert_title' => 'Convertir les diplômés en anciens élèves',
    'convert_intro' => "Élèves diplômés sans dossier d'ancien élève. La conversion fige l'année d'obtention et le nom de la classe finale tels qu'ils sont aujourd'hui.",
    'convert_empty' => "Chaque élève diplômé a déjà un dossier d'ancien élève.",
    'convert_selected_button' => 'Convertir la sélection',
    'convert_none_selected' => 'Sélectionnez au moins un diplômé à convertir.',
    'converted_count' => '{1} :count diplômé converti en ancien élève.|[2,*] :count diplômés convertis en anciens élèves.',

    'profile' => 'Profil',
    'graduation' => 'Diplôme',
    'graduation_year' => "Année d'obtention",
    'final_class' => 'Classe finale',
    'academic_year' => 'Année scolaire',
    'occupation' => 'Profession',
    'organisation' => 'Organisation',
    'email' => 'Email',
    'phone' => 'Téléphone',
    'notes' => 'Notes',
    'none' => '—',

    'engagement_timeline' => 'Historique des interactions',
    'engagement_empty' => "Aucune interaction enregistrée. Les dons, visites, interventions et mentorats apparaissent ici au fur et à mesure.",
    'engagement_type' => [
        'donation' => 'Don',
        'visit' => 'Visite',
        'talk' => 'Intervention',
        'mentorship' => 'Mentorat',
        'other' => 'Autre',
    ],

    'record_engagement' => 'Enregistrer une interaction',
    'form_type' => 'Type',
    'form_date' => 'Date',
    'form_note' => 'Note',
    'engagement_recorded' => 'Interaction enregistrée.',

    'update_contact' => 'Mettre à jour le contact',
    'form_occupation' => 'Profession actuelle',
    'form_organisation' => 'Organisation actuelle',
    'form_email' => 'Email de contact',
    'form_phone' => 'Téléphone de contact',
    'form_notes' => 'Notes',
    'contact_updated' => 'Coordonnées mises à jour.',

    'mark_deceased' => 'Déclarer le décès',
    'confirm_deceased' => "Déclarer cet ancien élève décédé ? Cette action est irréversible depuis les écrans.",
    'marked_deceased' => "L'ancien élève a été déclaré décédé.",

    'save' => 'Enregistrer',
    'cancel' => 'Annuler',
    'back_to_list' => 'Retour aux anciens élèves',
];

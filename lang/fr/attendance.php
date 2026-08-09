<?php

declare(strict_types=1);

return [
    'title' => 'Gestion des présences',
    'take_title' => "Faire l'appel",
    'coverage_title' => 'Couverture des registres',

    'breadcrumb_dashboard' => 'Tableau de bord',
    'breadcrumb_attendance' => 'Présences',

    'status' => [
        'present' => 'Présent',
        'absent' => 'Absent',
        'late' => 'En retard',
        'excused' => 'Dispensé',
        'sick' => 'Malade',
        'suspended' => 'Suspendu',
    ],

    'register_status' => [
        'open' => 'Ouvert',
        'submitted' => 'Soumis',
        'amended' => 'Modifié',
    ],

    'session' => [
        'morning' => 'Matin',
        'afternoon' => 'Après-midi',
        'full_day' => 'Journée entière',
    ],

    'mode' => [
        'daily' => 'Journalier',
        'per_lesson' => 'Par cours',
    ],

    'justification' => [
        'medical' => 'Médical',
        'family' => 'Familial',
        'administrative' => 'Administratif',
        'transport' => 'Transport',
        'other' => 'Autre',
    ],

    'filter_class' => 'Classe',
    'filter_date' => 'Date',
    'filter_session' => 'Session',
    'filter_slot' => 'Créneau de cours',
    'filter_period' => 'Période',
    'select_class' => 'Choisir une classe…',
    'select_slot' => 'Choisir un créneau…',

    'pick_class' => "Choisissez une classe pour faire l'appel.",
    'empty_roster' => "Aucun élève n'est inscrit dans cette classe à la date choisie.",
    'no_year' => "Aucune année scolaire courante n'est configurée.",
    'no_periods' => "Aucune période d'évaluation n'est définie pour l'année en cours.",
    'no_class_groups' => "Aucune classe n'existe pour l'année scolaire en cours.",
    'no_calendar' => "Le calendrier scolaire n'a pas été généré pour ce mois.",

    'taking_for' => "Appel — :class",
    'date_label' => 'Date',
    'total_students' => 'Effectif total',
    'suspended_note' => 'Enregistré comme suspendu',
    'minutes' => 'min',

    'mark_all_present' => 'Tous présents',
    'clear_all' => 'Tout effacer',
    'save' => "Enregistrer l'appel",
    'saved' => 'Appel enregistré.',
    'save_amendment' => 'Enregistrer la modification',
    'amended' => 'Registre modifié.',
    'amend_reason_placeholder' => 'Motif de la modification…',
    'amend_reason_required' => 'Une modification doit indiquer pourquoi le registre soumis était erroné.',
    'already_taken' => 'Ce registre a déjà été :status. Toute correction passe par une modification motivée.',

    'summary_title' => 'Résumé des présences',
    'no_register_yet' => "Aucun registre n'a été fait pour cette classe, cette date et cette session.",
    'quick_actions' => 'Actions rapides',

    'kpi_total' => 'Effectif total',
    'kpi_present_today' => "Présents aujourd'hui",
    'kpi_absent_today' => "Absents aujourd'hui",
    'kpi_late_today' => "Retards aujourd'hui",
    'kpi_month_rate' => 'Taux du mois',

    'todays_registers' => "Registres du jour",
    'no_registers_today' => "Aucun registre n'a été fait aujourd'hui.",
    'no_registers_yet' => "Aucun registre n'a été fait ce mois-ci.",
    'overview_title' => 'Aperçu des présences',
    'legend_not_present' => 'Absents / dispensés / suspendus',
    'calendar_title' => 'Calendrier de classe',
    'legend_school_days' => 'Jours de classe',
    'legend_holidays' => 'Jours fériés',
    'legend_events' => 'Événements',

    'dow_mo' => 'Lu',
    'dow_tu' => 'Ma',
    'dow_we' => 'Me',
    'dow_th' => 'Je',
    'dow_fr' => 'Ve',
    'dow_sa' => 'Sa',
    'dow_su' => 'Di',

    'col_admission_no' => "N° d'admission",
    'col_full_name' => 'Nom complet',
    'col_status' => 'Statut',
    'col_class' => 'Classe',
    'col_session' => 'Session',
    'col_taken_by' => 'Fait par',
    'col_mode' => 'Mode',
    'col_teaching_days' => 'Jours de classe',
    'col_days_taken' => 'Jours couverts',
    'col_coverage' => 'Couverture',
    'expected' => 'Attendus',

    'coverage_explainer' => 'Registres faits ÷ jours de classe par classe, du :from au :to. Une classe sans registre n\'a PAS de taux de présence — le manque apparaît ici, jamais comme un 100 % silencieux.',
    'coverage_ok' => 'À jour',
    'coverage_partial' => 'Partiel',
    'coverage_poor' => 'Non couvert',
    'coverage_no_calendar' => 'Pas de calendrier',
];

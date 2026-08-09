<?php

declare(strict_types=1);

// Gestion de l'emploi du temps (09-ui §8.6) + vocabulaire du calendrier
// scolaire. Parité structurelle avec lang/en/timetable.php, vérifiée par
// TimetableTest.
return [
    'title' => 'Gestion de l\'emploi du temps',
    'breadcrumb_dashboard' => 'Tableau de bord',
    'breadcrumb_academics' => 'Scolarité',
    'breadcrumb_timetable' => 'Gestion de l\'emploi du temps',

    'tab_class' => 'Classe',
    'tab_teacher' => 'Enseignant',
    'tab_room' => 'Salle',
    'tab_exam' => 'Examen',

    'year_label' => 'Année académique',
    'class_label' => 'Classe',
    'teacher_label' => 'Enseignant',
    'teacher_placeholder' => 'Choisir un enseignant…',
    'room_label' => 'Salle',
    'room_placeholder' => 'Choisir une salle…',
    'room_none' => 'Sans salle',

    'assign_subject' => 'Affecter une matière',
    'generate' => 'Générer l\'emploi du temps',
    'generate_unavailable' => 'La génération automatique de l\'emploi du temps n\'est pas disponible dans cette version. Affectez les matières aux périodes manuellement.',
    'dismiss' => 'Fermer',

    'field_day' => 'Jour',
    'field_period' => 'Période',
    'field_subject' => 'Matière',
    'field_teacher' => 'Enseignant',
    'field_room' => 'Salle',
    'save' => 'Enregistrer',
    'cancel' => 'Annuler',
    'assigned' => 'Le créneau a été affecté.',
    'removed' => 'Le créneau a été supprimé.',
    'remove_slot' => 'Supprimer ce créneau',

    'grid_heading' => 'Emploi du temps hebdomadaire',
    'column_time' => 'Heure',
    'no_year' => 'Aucune année académique courante n\'est définie. Les emplois du temps sont annuels — définissez d\'abord l\'année courante dans les paramètres de scolarité.',
    'no_classes' => 'Aucune classe n\'existe encore pour l\'année académique courante.',
    'no_periods' => 'Cette section n\'a pas encore de grille horaire. Définissez ses périodes (Ajouter une période / Définir les pauses) avant d\'affecter des matières.',
    'pick_teacher' => 'Choisissez un enseignant pour voir sa charge hebdomadaire.',
    'pick_room' => 'Choisissez une salle pour voir son occupation hebdomadaire.',

    'details_heading' => 'Détails de la classe',
    'details_class' => 'Classe',
    'details_students' => 'Élèves',
    'details_mode' => 'Mode d\'appel',

    'legend_heading' => 'Matières',
    'legend_empty' => 'Aucune matière affectée pour le moment.',

    'exam_heading' => 'Épreuves — année courante',
    'exam_empty' => 'Aucune épreuve n\'est programmée pour l\'année académique courante.',
    'exam_date' => 'Date',
    'exam_time' => 'Heure',
    'exam_class' => 'Classe',
    'exam_subject' => 'Matière',
    'exam_room' => 'Salle',
    'exam_status' => 'Statut',

    'day' => [
        'monday' => 'Lundi',
        'tuesday' => 'Mardi',
        'wednesday' => 'Mercredi',
        'thursday' => 'Jeudi',
        'friday' => 'Vendredi',
        'saturday' => 'Samedi',
    ],

    'day_type' => [
        'teaching' => 'Jour de cours',
        'weekend' => 'Week-end',
        'public_holiday' => 'Jour férié',
        'school_holiday' => 'Congé scolaire',
        'exam' => 'Jour d\'examen',
        'staff_day' => 'Journée pédagogique',
        'closure' => 'Fermeture',
    ],

    'attendance_mode' => [
        'daily' => 'Appel journalier',
        'per_lesson' => 'Appel par cours',
    ],
];

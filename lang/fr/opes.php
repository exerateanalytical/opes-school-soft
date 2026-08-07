<?php

declare(strict_types=1);

// NEEDS VERIFICATION: Secrétaire Général, Économe and Responsable Internat et
// Transport follow common Cameroonian secondary-school usage but should be
// confirmed against a real school's organigram before the pilot.

return [
    'auth' => [
        // Un SEUL message pour tous les cas d'échec - adresse inconnue, mot de
        // passe erroné, compte suspendu. Préciser la cause permettrait
        // d'énumérer les comptes existants.
        'failed' => 'Ces identifiants ne correspondent à aucun compte.',
        'throttled' => 'Trop de tentatives. Réessayez dans :seconds secondes.',
        'email' => 'Adresse e-mail',
        'password' => 'Mot de passe',
        'remember' => 'Rester connecté',
        'sign_in' => 'Se connecter',
        'forgot' => 'Mot de passe oublié ?',
        'forgot_help' => 'Demandez à un administrateur de réinitialiser votre mot de passe. Ce système n\'envoie pas d\'e-mails de mot de passe.',
    ],
    'roles' => [
        'super_admin' => 'Super Administrateur',
        'administrator' => 'Administrateur',
        'principal' => 'Proviseur',
        'vice_principal' => 'Censeur',
        'registrar' => 'Secrétaire Général',
        'bursar' => 'Économe',
        'accountant' => 'Comptable',
        'hr_officer' => 'Responsable des Ressources Humaines',
        'payroll_officer' => 'Responsable de la Paie',
        'exams_officer' => 'Chef du Service des Examens',
        'class_master' => 'Professeur Principal',
        'teacher' => 'Enseignant',
        'discipline_master' => 'Surveillant Général',
        'librarian' => 'Bibliothécaire',
        'store_keeper' => 'Magasinier',
        'nurse' => 'Infirmier / Infirmière',
        'welfare_officer' => 'Responsable Internat et Transport',
        'front_desk' => 'Accueil',
        'guardian' => 'Parent / Tuteur',
        'staff_portal' => 'Personnel',
    ],
    'permissions' => [
        'user.view' => 'Consulter les utilisateurs',
        'user.manage' => 'Gérer les utilisateurs',
        'user.set_password' => 'Définir le mot de passe d\'un utilisateur',
        'role.assign' => 'Attribuer les rôles',
        'permission.grant' => 'Accorder des permissions individuelles',
        'audit.view' => 'Consulter le journal d\'audit',
        'audit.export' => 'Exporter le journal d\'audit',
        'setting.view' => 'Consulter les paramètres',
        'setting.edit' => 'Modifier les paramètres',
        'setting.edit_engine' => 'Modifier les paramètres de calcul',
        'fee.view' => 'Consulter les frais',
        'fee.collect' => 'Encaisser les paiements',
        'fee.void' => 'Annuler les paiements',
        'ledger.view' => 'Consulter le grand livre',
        'ledger.post' => 'Enregistrer une écriture',
        'backup.run' => 'Lancer une sauvegarde',
        'backup.restore' => 'Restaurer une sauvegarde',
        'licence.manage' => 'Gérer la licence',
    ],
];

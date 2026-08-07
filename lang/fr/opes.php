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
    // Le shell : docs/specs/09-ui.md section 2. Chaque clé de la barre latérale
    // déclarée dans App\Modules\Identity\Support\Navigation possède ici un
    // libellé, y compris les modules des phases ultérieures : ils s'affichent
    // désactivés, et un élément désactivé doit tout de même se nommer.
    'nav' => [
        'dashboard' => 'Tableau de bord',
        'admissions' => 'Admissions',
        'students' => 'Élèves',
        'guardians' => 'Parents / Tuteurs',
        'staff' => 'Personnel',
        'academics' => 'Paramètres académiques',
        'classes' => 'Classes',
        'subjects' => 'Matières',
        'timetable' => 'Emploi du temps',
        'attendance' => 'Présences',
        'examinations' => 'Examens',
        'results' => 'Résultats',
        'finance' => 'Finances',
        'library' => 'Bibliothèque',
        'inventory' => 'Stocks',
        'transport' => 'Transport',
        'hostel' => 'Internat',
        'reports' => 'Rapports',
        'users' => 'Utilisateurs',
        'settings' => 'Paramètres',
        'nav_disabled_title' => 'Disponible dans une phase ultérieure',
    ],
    'shell' => [
        'brand' => 'OPES',
        'brand_suffix' => 'SCHOOL',
        'tagline' => "Excellence dans l'éducation",
        'primary_navigation' => 'Navigation principale',
        'open_menu' => 'Ouvrir le menu',
        'close_menu' => 'Fermer le menu',
        'search' => 'Rechercher',
        'search_disabled' => 'La recherche arrive dans une phase ultérieure.',
        'language' => 'Langue',
        'switch_to_english' => 'Afficher l\'interface en anglais',
        'switch_to_french' => 'Afficher l\'interface en français',
        'signed_in_as' => 'Connecté en tant que',
        'sign_out' => 'Se déconnecter',
        'user' => 'Utilisateur',
        'role' => 'Rôle',
        'no_role' => 'Aucun rôle',
        'connected' => 'Connecté',
        'status_strip' => 'État du système',
    ],
    'dashboard' => [
        'title' => 'Tableau de bord',
        'tile_users' => 'Utilisateurs actifs',
        'tile_roles' => 'Rôles configurés',
        'tile_health' => 'État du système',
        'tile_backup' => 'Dernière sauvegarde',
        'alerts' => 'À traiter',
        'no_alerts' => 'Rien ne requiert votre attention pour le moment.',
        'remedy' => 'Que faire',
        'quick_actions' => 'Actions rapides',
        'action_add_user' => 'Ajouter un utilisateur',
        'action_take_backup' => 'Lancer une sauvegarde',
        'action_check_health' => 'Vérifier l\'état du système',
    ],
    'ui' => [
        'no_data' => 'Non encore renseigné',
        'status_ok' => 'OK',
        'status_amber' => 'À surveiller',
        'status_red' => 'Action requise',
        // Le contrat d'écran de liste (09-ui 4). Chaque écran de module compose
        // x-list-screen : ces chaînes se lisent sur une vingtaine d'écrans.
        'breadcrumb' => 'Fil d\'Ariane',
        'filters' => 'Filtres',
        'filter' => 'Filtrer',
        'reset' => 'Réinitialiser',
        'showing' => 'Affichage de :first à :last sur :total',
        'per_page' => 'Par page',
        'pagination' => 'Pagination',
        'previous' => 'Précédent',
        'next' => 'Suivant',
        'go_to_page' => 'Aller à la page :page',
        'no_results' => 'Aucun résultat ne correspond à ces filtres.',
        'all' => 'Tous',
    ],
    // Gestion des utilisateurs, docs/specs/09-ui.md section 8.10.
    'users' => [
        'title' => 'Utilisateurs',
        'breadcrumb_dashboard' => 'Tableau de bord',
        'breadcrumb_users' => 'Utilisateurs',
        'add_user' => 'Ajouter un utilisateur',
        'column_user' => 'Utilisateur',
        'column_role' => 'Rôle',
        'column_status' => 'Statut',
        'column_last_login' => 'Dernière connexion',
        'column_actions' => 'Actions',
        'never_logged_in' => 'Jamais',
        'search_label' => 'Recherche',
        'search_placeholder' => 'Rechercher par nom ou email',
        'role_label' => 'Rôle',
        'status_label' => 'Statut',
        'status_active' => 'Actif',
        'status_suspended' => 'Suspendu',
        'empty' => 'Aucun utilisateur ne correspond à ces filtres.',
        'form_title' => 'Ajouter un utilisateur',
        'name_label' => 'Nom complet',
        'email_label' => 'Adresse email',
        'role_field_label' => 'Rôle',
        'password_label' => 'Mot de passe',
        'role_placeholder' => 'Choisir un rôle',
        'save' => 'Enregistrer',
        'cancel' => 'Annuler',
        'created' => 'Utilisateur créé.',
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
    // Contrôles d'état opérationnel, app/Modules/Operations/Health/Checks/*.
    // Ces chaînes apparaissent sur le tableau de bord authentifié ET (le
    // libellé et le détail seulement, jamais le remède) sur le point de
    // terminaison /up non authentifié : à traduire, sans rien y ajouter de
    // sensible.
    'health' => [
        'app_version' => [
            'label' => 'Version du logiciel',
            'detail' => 'OPES SCHOOL :version, environnement :environment.',
        ],
        'database' => [
            'label' => 'Base de données',
            'ok_detail' => 'Accessible, :tables tables.',
            'red_detail' => 'La base de données n\'a pas répondu : :reason',
            'red_remedy' => 'Le service de base de données ne fonctionne pas ou n\'accepte '
                .'plus de connexions. Demandez à la personne qui a installé le système de '
                .'redémarrer le service MySQL, puis rechargez cette page. N\'encaissez '
                .'aucun paiement tant qu\'il n\'est pas rétabli.',
        ],
        'migrations' => [
            'label' => 'Mises à jour de la base de données',
            'never_prepared_detail' => 'La base de données n\'a jamais été préparée pour ce logiciel.',
            'never_prepared_remedy' => 'Exécutez : php artisan migrate --force',
            'pending_detail_one' => 'Une mise à jour de la base de données n\'a pas encore été appliquée.',
            'pending_detail_many' => ':count mises à jour de la base de données n\'ont pas encore été appliquées.',
            'pending_remedy' => 'Faites d\'abord une sauvegarde (php artisan opes:backup:run), '
                .'puis exécutez : php artisan migrate --force. Tant que ce n\'est pas fait, le '
                .'logiciel et la base de données ne s\'accordent pas sur la structure de vos données.',
            'ok_detail' => 'Les :count mises à jour sont toutes appliquées.',
        ],
        'mysql_durability' => [
            'label' => 'Sécurité en cas de coupure de courant',
            'problem_flush' => 'innodb_flush_log_at_trx_commit vaut :value, et non 1',
            'problem_binlog' => 'sync_binlog vaut :value, et non 1',
            'amber_detail' => 'La base de données n\'est pas réglée pour écrire chaque '
                .'transaction confirmée directement sur le disque (:problems).',
            'amber_remedy' => 'Demandez à la personne qui a installé le système de régler '
                .'innodb_flush_log_at_trx_commit = 1 et sync_binlog = 1 dans la configuration '
                .'MySQL, puis de redémarrer le service. Tant que ce n\'est pas fait, une '
                .'coupure de courant peut effacer un paiement déjà quittancé, et le parent '
                .'aura le reçu papier alors que vous n\'aurez pas la trace informatique.',
            'ok_detail' => 'Chaque transaction confirmée est écrite directement sur le disque.',
        ],
        'backup_recency' => [
            'label' => 'Dernière sauvegarde',
            'never_detail' => 'Aucune sauvegarde ne s\'est jamais terminée avec succès.',
            'never_remedy' => 'Exécutez : php artisan opes:backup:run. Tant que cela n\'a pas '
                .'réussi, une panne de disque cet après-midi ferait perdre tous les '
                .'dossiers de l\'école.',
            'red_detail' => 'La dernière bonne sauvegarde s\'est terminée il y a :age.',
            'red_remedy' => 'Exécutez : php artisan opes:backup:run. Vérifiez ensuite que la '
                .'planification automatique fonctionne toujours, car c\'est elle qui aurait '
                .'dû effectuer cette sauvegarde et ne l\'a pas fait.',
            'amber_detail' => 'La dernière bonne sauvegarde s\'est terminée il y a :age, ce qui est plus tard que prévu.',
            'amber_remedy' => 'Exécutez : php artisan opes:backup:run pour la remettre à jour, '
                .'et signalez à la personne qui a installé le système que la sauvegarde '
                .'automatique de cette nuit était en retard.',
            'ok_detail' => 'Terminée il y a :age et vérifiée.',
            'age_less_than_hour' => 'moins d\'une heure',
            'age_hour' => '1 heure',
            'age_hours' => ':hours heures',
            'age_days' => ':days jours',
        ],
        'backup_second_target' => [
            'label' => 'Destination de sauvegarde',
            'amber_detail' => 'Les sauvegardes ne sont écrites qu\'à un seul endroit.',
            'amber_remedy' => 'Configurez une seconde destination de sauvegarde, par exemple '
                .'une clé USB changée chaque semaine. Une sauvegarde sur le même disque que '
                .'la base de données est perdue en même temps que le disque.',
            'ok_detail' => 'Une seconde destination de sauvegarde est configurée.',
        ],
        'restore_drill' => [
            'label' => 'Test de restauration',
            'never_detail' => 'Aucune sauvegarde n\'a jamais été restaurée avec succès : aucune '
                .'sauvegarde n\'est donc encore prouvée fonctionnelle.',
            'run_remedy' => 'Exécutez : php artisan opes:backup:drill',
            'red_detail' => 'Le dernier test de restauration réussi remonte à :age.',
            'amber_detail' => 'Le dernier test de restauration réussi remonte à :age, et un nouveau test est dû.',
            'ok_detail' => 'Une sauvegarde a été restaurée et vérifiée il y a :age.',
            'age_today' => 'aujourd\'hui',
            'age_one_day' => '1 jour',
            'age_days' => ':days jours',
        ],
        'audit_chain' => [
            'label' => 'Journal d\'audit',
            'ok_detail' => 'Intact, :count entrées vérifiées.',
            'red_detail' => 'Le journal d\'audit a été modifié en dehors de l\'application : :reason',
            'red_remedy' => 'Ne réinitialisez pas ceci vous-même. Le registre de qui a fait quoi '
                .'a été altéré, ce qui signifie que quelqu\'un a eu un accès direct à la base '
                .'de données. Informez la direction de l\'établissement dès aujourd\'hui, '
                .'conservez les sauvegardes actuelles et contactez le support.',
        ],
        'disk_space' => [
            'label' => 'Espace disque',
            'detail' => 'Le disque de sauvegarde est plein à :percent %, avec :free Go libres.',
            'red_remedy' => 'Le disque est presque plein : la sauvegarde de cette nuit risque '
                .'d\'échouer. Copiez les sauvegardes les plus anciennes sur la clé USB, puis '
                .'exécutez : php artisan opes:backup:prune',
            'amber_remedy' => 'Libérez de l\'espace cette semaine. Exécutez : '
                .'php artisan opes:backup:prune, et retirez tout autre fichier volumineux du '
                .'disque. Un disque plein arrête les sauvegardes sans arrêter l\'école, ce '
                .'qui passe inaperçu.',
        ],
        'queue_heartbeat' => [
            'label' => 'Tâches de fond',
            'never_detail' => 'L\'exécuteur de tâches de fond ne s\'est jamais signalé.',
            'never_remedy' => 'Rien de planifié ne fonctionne, y compris la sauvegarde '
                .'nocturne. Demandez à la personne qui a installé le système de démarrer le '
                .'service de planification OPES (php artisan schedule:work). Faites une '
                .'sauvegarde manuelle dès aujourd\'hui : php artisan opes:backup:run',
            'red_detail' => 'L\'exécuteur de tâches de fond s\'est signalé pour la dernière fois il y a :age.',
            'red_remedy' => 'La planification s\'est arrêtée. Demandez à la personne qui a '
                .'installé le système de la redémarrer, puis faites une sauvegarde manuelle '
                .'dès aujourd\'hui : php artisan opes:backup:run',
            'amber_detail' => 'L\'exécuteur de tâches de fond s\'est signalé pour la dernière fois il y a :age, ce qui est tardif.',
            'amber_remedy' => 'Rechargez cette page dans dix minutes. Si cela ne s\'est pas '
                .'résolu, la planification doit être redémarrée - et la sauvegarde nocturne '
                .'en dépend.',
            'ok_detail' => 'En fonctionnement ; dernier signal il y a :age.',
            'age_minute' => 'une minute',
            'age_minutes' => ':minutes minutes',
        ],
        'failed_jobs' => [
            'label' => 'Tâches échouées',
            'detail_one' => 'Une tâche de fond a échoué et n\'a pas été relancée.',
            'detail_many' => ':count tâches de fond ont échoué et n\'ont pas été relancées.',
            'red_remedy' => 'Quelque chose échoue de façon répétée plutôt qu\'une seule fois. '
                .'Envoyez le dossier de diagnostic au support avant de vider la liste, puis '
                .'exécutez : php artisan queue:retry all',
            'amber_remedy' => 'Exécutez : php artisan queue:retry all. Si la même tâche échoue à '
                .'nouveau, envoyez le dossier de diagnostic au support plutôt que de relancer '
                .'une troisième fois.',
            'ok_detail' => 'Aucune tâche de fond en échec.',
        ],
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
        'academics.view' => 'Consulter le volet académique',
        'academics.manage' => 'Gérer le volet académique',
        'students.view' => 'Consulter les élèves',
        'students.manage' => 'Gérer les élèves',
        'students.finalise_matricule' => 'Rendre définitif un matricule',
        'guardians.manage' => 'Gérer les parents et tuteurs',
        'admissions.manage' => 'Gérer les admissions',
        'fee.view' => 'Consulter les frais',
        'fee.collect' => 'Encaisser les paiements',
        'fee.void' => 'Annuler les paiements',
        'ledger.view' => 'Consulter le grand livre',
        'ledger.post' => 'Enregistrer une écriture',
        'backup.run' => 'Lancer une sauvegarde',
        'backup.restore' => 'Restaurer une sauvegarde',
        'licence.manage' => 'Gérer la licence',
    ],
    // Écran Paramètres académiques, Phase 1 (frontend images/accademic setting.png).
    'academics' => [
        'title' => 'Paramètres académiques',
        'breadcrumb_dashboard' => 'Tableau de bord',
        'breadcrumb_settings' => 'Paramètres',
        'breadcrumb_academic' => 'Paramètres académiques',
        'save_all' => 'Tout enregistrer',

        // Sous-navigation des paramètres. Seul « Académique » est actif en
        // Phase 1 ; les autres s'affichent désactivés, comme dans la barre
        // latérale.
        'subnav_general' => 'Paramètres généraux',
        'subnav_academic' => 'Paramètres académiques',
        'subnav_admission' => 'Paramètres d\'admission',
        'subnav_examination' => 'Paramètres des examens',
        'subnav_grading' => 'Système de notation',
        'subnav_promotion' => 'Critères de passage',
        'subnav_subjects' => 'Gestion des matières',
        'subnav_classes' => 'Paramètres des classes',
        'subnav_term' => 'Trimestre / Session',
        'subnav_holidays' => 'Gestion des congés',

        // Carte Session académique.
        'session_title' => 'Session académique',
        'session_subtitle' => 'Configurez la session académique et la période de notation.',
        'session_label' => 'Session académique',
        'active_session' => 'Session active',
        'start_date' => 'Date de début',
        'end_date' => 'Date de fin',
        'current_term' => 'Trimestre en cours',
        'no_current_term' => 'Aucun trimestre en cours pour le moment',
        'no_year' => 'Aucune année académique n\'a encore été créée.',
        'no_year_hint' => 'Créez la première année académique ci-dessous pour débloquer les classes, les matières et les inscriptions.',
        'create_year_title' => 'Créer une année académique',
        'code_label' => 'Code',
        'code_placeholder' => 'ex. 2026-2027',
        'name_label' => 'Nom',
        'name_placeholder' => 'ex. Année académique 2026/2027',
        'create_year' => 'Créer l\'année',
        'year_created' => 'Année académique créée.',
        'set_current' => 'Définir comme session en cours',
        'current_set' => 'Année académique en cours mise à jour.',
        'other_years' => 'Autres sessions',
        'status_planned' => 'Planifiée',
        'status_active' => 'Active',
        'status_closed' => 'Clôturée',

        // Carte Structure des trimestres.
        'terms_title' => 'Structure des trimestres',
        'terms_subtitle' => 'Définissez le nombre de trimestres et leurs durées.',
        'number_of_terms' => 'Nombre de trimestres',
        'terms_option' => ':count trimestres',
        'term_n' => 'Trimestre :number',
        'current_term_chip' => 'Trimestre en cours',
        'duration' => 'Durée',
        'duration_weeks' => ':weeks semaines',
        'save_terms' => 'Enregistrer la structure des trimestres',
        'terms_saved' => 'Structure des trimestres enregistrée.',
        'terms_need_year' => 'Créez une année académique avant d\'en définir les trimestres.',

        // Colonne de droite.
        'grading_title' => 'Barème de notation',
        'grading_empty' => 'Le barème de notation se configure dans la phase d\'évaluation.',
        'subjects_overview' => 'Aperçu des matières',
        'total_subjects' => 'Total des matières',
        'active_subjects' => 'Matières actives',
        'manage_subjects' => 'Gérer les matières',
    ],
    // Écran Gestion des matières, Phase 1 (frontend images/subject management.png).
    'subjects_screen' => [
        'title' => 'Gestion des matières',
        'breadcrumb_dashboard' => 'Tableau de bord',
        'breadcrumb_subjects' => 'Matières',
        'add_subject' => 'Ajouter une matière',
        'kpi_total' => 'Total des matières',
        'search_label' => 'Rechercher une matière',
        'search_placeholder' => 'Rechercher par nom ou par code...',
        'status_label' => 'Statut',
        'status_active' => 'Active',
        'status_inactive' => 'Inactive',
        'column_code' => 'Code',
        'column_name' => 'Matière',
        'column_name_fr' => 'Nom (français)',
        'column_department' => 'Département',
        'column_status' => 'Statut',
        'column_actions' => 'Actions',
        'no_department' => 'Aucun',
        'empty' => 'Aucune matière ne correspond à ces filtres.',
        'form_create_title' => 'Ajouter une matière',
        'form_edit_title' => 'Modifier la matière',
        'code_field' => 'Code de la matière',
        'code_placeholder' => 'ex. MATH101',
        'name_field' => 'Nom de la matière (anglais)',
        'name_fr_field' => 'Nom de la matière (français)',
        'save' => 'Enregistrer',
        'cancel' => 'Annuler',
        'edit' => 'Modifier',
        'created' => 'Matière créée.',
        'updated' => 'Matière mise à jour.',
    ],
    // Écran Classes, Phase 1.
    'classes_screen' => [
        'title' => 'Classes',
        'breadcrumb_dashboard' => 'Tableau de bord',
        'breadcrumb_classes' => 'Classes',
        'add_class' => 'Ajouter une classe',
        'kpi_total' => 'Total des classes',
        'search_label' => 'Recherche',
        'search_placeholder' => 'Rechercher par nom de classe...',
        'column_name' => 'Classe',
        'column_level' => 'Niveau',
        'column_stream' => 'Série',
        'column_capacity' => 'Capacité',
        'column_status' => 'Statut',
        'status_active' => 'Active',
        'status_inactive' => 'Inactive',
        'no_stream' => 'Aucune',
        'empty' => 'Aucune classe ne correspond à ces filtres.',
        'no_year' => 'Aucune année académique n\'est encore définie comme session en cours. Créez et activez une session dans les Paramètres académiques, puis revenez ouvrir les classes correspondantes.',
        'go_to_settings' => 'Ouvrir les paramètres académiques',
        'form_title' => 'Ajouter une classe',
        'name_field' => 'Nom de la classe',
        'name_placeholder' => 'ex. 6e A',
        'level_field' => 'Niveau',
        'level_placeholder' => 'Choisir un niveau',
        'stream_field' => 'Série',
        'stream_placeholder' => 'Aucune série',
        'capacity_field' => 'Capacité',
        'save' => 'Enregistrer',
        'cancel' => 'Annuler',
        'created' => 'Classe créée.',
    ],
];

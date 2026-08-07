<?php

declare(strict_types=1);

return [
    'auth' => [
        // ONE message for every failure mode - unknown email, wrong password,
        // suspended account. Naming the cause would let an attacker enumerate
        // which addresses are real accounts.
        'failed' => 'Those credentials do not match our records.',
        'throttled' => 'Too many attempts. Try again in :seconds seconds.',
        'email' => 'Email address',
        'password' => 'Password',
        'remember' => 'Keep me signed in',
        'sign_in' => 'Sign in',
        'forgot' => 'Forgotten your password?',
        // 00-core 9.3: most Cameroonian schools have no SMTP server, so there
        // is deliberately no self-service email reset. A human resets it.
        'forgot_help' => 'Ask an administrator to reset your password. This system does not send password emails.',
    ],
    // The shell: docs/specs/09-ui.md section 2. Every sidebar key in
    // App\Modules\Identity\Support\Navigation has a label here, including the
    // items whose modules arrive in later phases - they render disabled, and a
    // disabled item still has to say what it is.
    'nav' => [
        'dashboard' => 'Dashboard',
        'admissions' => 'Admissions',
        'students' => 'Students',
        'guardians' => 'Guardians',
        'staff' => 'Staff',
        'classes' => 'Classes',
        'subjects' => 'Subjects',
        'timetable' => 'Timetable',
        'attendance' => 'Attendance',
        'examinations' => 'Examinations',
        'results' => 'Results',
        'finance' => 'Finance',
        'library' => 'Library',
        'inventory' => 'Inventory',
        'transport' => 'Transport',
        'hostel' => 'Hostel',
        'reports' => 'Reports',
        'users' => 'Users',
        'settings' => 'Settings',
        'nav_disabled_title' => 'Arrives in a later phase',
    ],
    'shell' => [
        'brand' => 'OPES',
        'brand_suffix' => 'SCHOOL',
        'tagline' => 'Excellence in Education',
        'primary_navigation' => 'Primary navigation',
        'open_menu' => 'Open the menu',
        'close_menu' => 'Close the menu',
        'search' => 'Search',
        'search_disabled' => 'Search arrives in a later phase.',
        'language' => 'Language',
        'switch_to_english' => 'Switch the interface to English',
        'switch_to_french' => 'Switch the interface to French',
        'signed_in_as' => 'Signed in as',
        'sign_out' => 'Sign out',
        'user' => 'User',
        'role' => 'Role',
        'no_role' => 'No role',
        'connected' => 'Connected',
        'status_strip' => 'System status',
    ],
    'dashboard' => [
        'title' => 'Dashboard',
        'tile_users' => 'Active users',
        'tile_roles' => 'Roles configured',
        'tile_health' => 'System health',
        'tile_backup' => 'Last backup',
        'alerts' => 'Needs attention',
        'no_alerts' => 'Nothing needs your attention right now.',
        'remedy' => 'What to do',
        'quick_actions' => 'Quick actions',
        'action_add_user' => 'Add user',
        'action_take_backup' => 'Take a backup',
        'action_check_health' => 'Check health',
    ],
    'ui' => [
        'no_data' => 'Not yet recorded',
        'status_ok' => 'OK',
        'status_amber' => 'Attention',
        'status_red' => 'Action needed',
        // The list-screen contract (09-ui 4). Every module screen composes
        // x-list-screen, so these strings are read on roughly twenty screens.
        'breadcrumb' => 'Breadcrumb',
        'filters' => 'Filters',
        'filter' => 'Filter',
        'reset' => 'Reset',
        'showing' => 'Showing :first to :last of :total',
        'per_page' => 'Per page',
        'pagination' => 'Pagination',
        'previous' => 'Previous',
        'next' => 'Next',
        'go_to_page' => 'Go to page :page',
        'no_results' => 'Nothing matches these filters.',
        'all' => 'All',
    ],
    // User Management, docs/specs/09-ui.md section 8.10.
    'users' => [
        'title' => 'Users',
        'breadcrumb_dashboard' => 'Dashboard',
        'breadcrumb_users' => 'Users',
        'add_user' => 'Add user',
        'column_user' => 'User',
        'column_role' => 'Role',
        'column_status' => 'Status',
        'column_last_login' => 'Last login',
        'column_actions' => 'Actions',
        'never_logged_in' => 'Never',
        'search_label' => 'Search',
        'search_placeholder' => 'Search by name or email',
        'role_label' => 'Role',
        'status_label' => 'Status',
        'status_active' => 'Active',
        'status_suspended' => 'Suspended',
        'empty' => 'No users match these filters.',
        'form_title' => 'Add user',
        'name_label' => 'Full name',
        'email_label' => 'Email address',
        'role_field_label' => 'Role',
        'password_label' => 'Password',
        'role_placeholder' => 'Choose a role',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'created' => 'User created.',
    ],
    'roles' => [
        'super_admin' => 'Super Administrator',
        'administrator' => 'Administrator',
        'principal' => 'Principal',
        'vice_principal' => 'Vice-Principal',
        'registrar' => 'Registrar',
        'bursar' => 'Bursar',
        'accountant' => 'Accountant',
        'hr_officer' => 'HR Officer',
        'payroll_officer' => 'Payroll Officer',
        'exams_officer' => 'Examinations Officer',
        'class_master' => 'Class Master',
        'teacher' => 'Teacher',
        'discipline_master' => 'Discipline Master',
        'librarian' => 'Librarian',
        'store_keeper' => 'Store Keeper',
        'nurse' => 'Nurse',
        'welfare_officer' => 'Welfare Officer',
        'front_desk' => 'Front Desk',
        'guardian' => 'Guardian',
        'staff_portal' => 'Staff',
    ],
    // Operational health checks, app/Modules/Operations/Health/Checks/*. These
    // strings land on an authenticated dashboard AND (detail/label only, never
    // remedy) on the unauthenticated /up endpoint, so translate but do not add
    // anything sensitive.
    'health' => [
        'app_version' => [
            'label' => 'Software version',
            'detail' => 'OPES SCHOOL :version, running in the :environment environment.',
        ],
        'database' => [
            'label' => 'Database',
            'ok_detail' => 'Reachable, :tables tables.',
            'red_detail' => 'The database did not answer: :reason',
            'red_remedy' => 'The database service is not running or has stopped accepting '
                .'connections. Ask whoever installed the system to restart the MySQL '
                .'service, then reload this page. Do not take any payments until it is back.',
        ],
        'migrations' => [
            'label' => 'Database upgrades',
            'never_prepared_detail' => 'The database has never been prepared for this software.',
            'never_prepared_remedy' => 'Run: php artisan migrate --force',
            'pending_detail_one' => 'One database upgrade has not been applied yet.',
            'pending_detail_many' => ':count database upgrades have not been applied yet.',
            'pending_remedy' => 'Take a backup first (php artisan opes:backup:run), then run: '
                .'php artisan migrate --force. Until this is done the software and the '
                .'database disagree about the shape of your records.',
            'ok_detail' => 'All :count upgrades applied.',
        ],
        'mysql_durability' => [
            'label' => 'Power-cut safety',
            'problem_flush' => 'innodb_flush_log_at_trx_commit is :value, not 1',
            'problem_binlog' => 'sync_binlog is :value, not 1',
            'amber_detail' => 'The database is not set to write every confirmed transaction '
                .'straight to disk (:problems).',
            'amber_remedy' => 'Ask whoever installed the system to set '
                .'innodb_flush_log_at_trx_commit = 1 and sync_binlog = 1 in the MySQL '
                .'configuration and restart the service. Until then, a power cut can '
                .'erase a payment that was already receipted, and the parent will have '
                .'the paper and you will not have the record.',
            'ok_detail' => 'Every confirmed transaction is written straight to disk.',
        ],
        'backup_recency' => [
            'label' => 'Last backup',
            'never_detail' => 'No backup has ever completed successfully.',
            'never_remedy' => 'Run: php artisan opes:backup:run. Until that succeeds, a disk '
                .'failure this afternoon would lose every record the school has.',
            'red_detail' => 'The last good backup finished :age ago.',
            'red_remedy' => 'Run: php artisan opes:backup:run. Then check that the nightly '
                .'schedule is still running, because it should have taken this backup '
                .'for you and did not.',
            'amber_detail' => 'The last good backup finished :age ago, which is later than expected.',
            'amber_remedy' => 'Run: php artisan opes:backup:run to bring it up to date, and tell '
                .'whoever installed the system that last night\'s automatic backup was late.',
            'ok_detail' => 'Completed :age ago and verified.',
            'age_less_than_hour' => 'less than an hour',
            'age_hour' => '1 hour',
            'age_hours' => ':hours hours',
            'age_days' => ':days days',
        ],
        'backup_second_target' => [
            'label' => 'Backup target',
            'amber_detail' => 'Backups are written to one location only.',
            'amber_remedy' => 'Set a second backup target, such as a USB drive rotated weekly. '
                .'A backup on the same disk as the database is lost with the disk.',
            'ok_detail' => 'A second backup location is configured.',
        ],
        'restore_drill' => [
            'label' => 'Restore drill',
            'never_detail' => 'No backup has ever been restored successfully, so no backup is '
                .'yet known to work.',
            'run_remedy' => 'Run: php artisan opes:backup:drill',
            'red_detail' => 'The last successful restore drill was :age.',
            'amber_detail' => 'The last successful restore drill was :age, and one is due.',
            'ok_detail' => 'A backup was restored and checked :age.',
            'age_today' => 'today',
            'age_one_day' => '1 day ago',
            'age_days' => ':days days ago',
        ],
        'audit_chain' => [
            'label' => 'Audit log',
            'ok_detail' => 'Intact, :count entries verified.',
            'red_detail' => 'The audit log has been changed outside the application: :reason',
            'red_remedy' => 'Do not clear this yourself. The record of who did what has been '
                .'altered, which means someone had direct access to the database. Tell the '
                .'head of school today, keep the current backups, and contact support.',
        ],
        'disk_space' => [
            'label' => 'Disk space',
            'detail' => 'The backup drive is :percent% full, with :free GB free.',
            'red_remedy' => 'The drive is nearly full, so tonight\'s backup will probably fail. '
                .'Copy the oldest backup files onto the USB drive and then run: '
                .'php artisan opes:backup:prune',
            'amber_remedy' => 'Free some space this week. Run: php artisan opes:backup:prune, and '
                .'move anything else large off the drive. A full drive stops backups '
                .'without stopping the school, so nobody notices.',
        ],
        'queue_heartbeat' => [
            'label' => 'Background tasks',
            'never_detail' => 'The background task runner has never reported in.',
            'never_remedy' => 'Nothing scheduled is running, which includes the nightly backup. '
                .'Ask whoever installed the system to start the OPES scheduler service '
                .'(php artisan schedule:work). Take a backup by hand today: '
                .'php artisan opes:backup:run',
            'red_detail' => 'The background task runner last reported in :age ago.',
            'red_remedy' => 'The scheduler has stopped. Ask whoever installed the system to '
                .'restart it, then take a backup by hand today: php artisan opes:backup:run',
            'amber_detail' => 'The background task runner last reported in :age ago, which is late.',
            'amber_remedy' => 'Reload this page in ten minutes. If it has not cleared, the '
                .'scheduler needs restarting - and the nightly backup depends on it.',
            'ok_detail' => 'Running; last reported in :age ago.',
            'age_minute' => 'a minute',
            'age_minutes' => ':minutes minutes',
        ],
        'failed_jobs' => [
            'label' => 'Failed tasks',
            'detail_one' => 'One background task has failed and was not retried.',
            'detail_many' => ':count background tasks have failed and were not retried.',
            'red_remedy' => 'Something is failing repeatedly rather than once. Send the '
                .'diagnostics bundle to support before clearing the list, then run: '
                .'php artisan queue:retry all',
            'amber_remedy' => 'Run: php artisan queue:retry all. If the same task fails again, '
                .'send the diagnostics bundle to support rather than retrying a third time.',
            'ok_detail' => 'No failed background tasks.',
        ],
    ],
    'permissions' => [
        'user.view' => 'View users',
        'user.manage' => 'Manage users',
        'user.set_password' => 'Set another user\'s password',
        'role.assign' => 'Assign roles',
        'permission.grant' => 'Grant individual permissions',
        'audit.view' => 'View the audit log',
        'audit.export' => 'Export the audit log',
        'setting.view' => 'View settings',
        'setting.edit' => 'Edit settings',
        'setting.edit_engine' => 'Edit engine-behaviour settings',
        'fee.view' => 'View fees',
        'fee.collect' => 'Collect payments',
        'fee.void' => 'Void payments',
        'ledger.view' => 'View the ledger',
        'ledger.post' => 'Post to the ledger',
        'backup.run' => 'Run a backup',
        'backup.restore' => 'Restore from a backup',
        'licence.manage' => 'Manage the licence',
    ],
];

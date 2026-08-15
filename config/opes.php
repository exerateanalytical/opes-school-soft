<?php

declare(strict_types=1);

return [
    /*
     * Paths to the MySQL client binaries. Configurable rather than hardcoded:
     * Laragon puts them under C:\laragon\bin, a Linux VPS under /usr/bin, and
     * the installer will set these per machine (08-operations 1.2).
     */
    'mysql' => [
        // `?:` for the same reason as the backup path below: an empty-but-present
        // env key would otherwise resolve to "" and the process would fail with
        // an opaque "command not found" instead of falling back to PATH.
        'dump_binary' => env('OPES_MYSQLDUMP') ?: 'mysqldump',
        'client_binary' => env('OPES_MYSQL_CLIENT') ?: 'mysql',
    ],

    'backup' => [
        // Primary target. The second physical target is configured per school;
        // the health check goes AMBER when only one target is configured,
        // because a backup on the same disk as the database is not a backup.
        /*
         * `?:` not env()'s second argument. A key that EXISTS but is empty -
         * `OPES_BACKUP_PATH=` in .env, which is exactly what .env.example
         * prescribes - makes env() return "" rather than the default, so the
         * default never applies. Verified the hard way: a real backup landed at
         * C:\opes-full-....sql, the filesystem root, outside any rotation or
         * backup target. Same reasoning for every path-like key below.
         */
        'path' => env('OPES_BACKUP_PATH') ?: storage_path('opes-backups'),

        // Empty genuinely means "not configured" here, and the health check
        // reports amber for it, so a null is the correct resting state.
        'second_target' => env('OPES_BACKUP_SECOND_TARGET') ?: null,

        // GFS retention (08-operations 3.3).
        'keep_daily' => (int) env('OPES_KEEP_DAILY', 7),
        'keep_weekly' => (int) env('OPES_KEEP_WEEKLY', 4),
        'keep_monthly' => (int) env('OPES_KEEP_MONTHLY', 12),
        'keep_yearly' => (int) env('OPES_KEEP_YEARLY', 10),

        // One file verified per run, per 08-operations 3.4: an unbounded
        // verification sweep on a timer was a real shipped bug in the
        // reference implementation.
        'verify_budget_per_run' => (int) env('OPES_VERIFY_BUDGET', 1),
    ],

    /*
     * One-click demo sign-in. This is an authentication BYPASS: it signs a
     * visitor in as an administrator with no credential at all. On a system
     * holding student records, guardian contact details and payroll it must
     * never be reachable outside a demo box, so it is guarded twice over:
     *
     *   1. this flag, which is off unless OPES_DEMO_LOGIN is explicitly set;
     *   2. an environment check in the component itself, which refuses in
     *      any environment except `local` even when the flag is on.
     *
     * Two independent guards because either alone is one mistake away from a
     * public bypass - a stray .env line copied to a server, or an environment
     * misread. Both must agree. Tests assert the refusal in `production`.
     */
    'demo_login' => [
        // env() returns "" - not the default - for a key that is present but
        // empty, so filter explicitly rather than trusting the second argument.
        'enabled' => filter_var(env('OPES_DEMO_LOGIN', false), FILTER_VALIDATE_BOOL),
        'email' => env('OPES_DEMO_LOGIN_EMAIL') ?: 'demo@opeschool.test',
        'name' => env('OPES_DEMO_LOGIN_NAME') ?: 'Demo Administrator',

        /*
         * The demo identities offered on the login page, keyed by the
         * Identity\Domain\Role enum VALUE. The point of offering several is
         * that a demo can show RBAC working live: sign in as the accountant,
         * see the finance view and nothing else; sign in as the teacher, see
         * neither the ledger nor the operations tiles.
         *
         * Each identity is a REAL user carrying a REAL Spatie role, so every
         * permission check in the product applies to it unchanged. There is
         * no bypass here beyond the credential itself, and that bypass is
         * still behind the two guards documented above.
         *
         * The `administrator` entry deliberately reuses the `email`/`name`
         * keys above so the original single-identity configuration keeps
         * working and the existing demo account is not duplicated.
         */
        'identities' => [
            [
                'role' => 'administrator',
                'email' => env('OPES_DEMO_LOGIN_EMAIL') ?: 'demo@opeschool.test',
                'name' => env('OPES_DEMO_LOGIN_NAME') ?: 'Demo Administrator',
            ],
            [
                'role' => 'principal',
                'email' => 'demo.principal@opeschool.test',
                'name' => 'Demo Principal',
            ],
            [
                'role' => 'accountant',
                'email' => 'demo.accountant@opeschool.test',
                'name' => 'Demo Accountant',
            ],
            [
                'role' => 'bursar',
                'email' => 'demo.bursar@opeschool.test',
                'name' => 'Demo Bursar',
            ],
            [
                'role' => 'registrar',
                'email' => 'demo.registrar@opeschool.test',
                'name' => 'Demo Registrar',
            ],
            [
                'role' => 'teacher',
                'email' => 'demo.teacher@opeschool.test',
                'name' => 'Demo Teacher',
            ],
            // The guardian portal (/portal) has existed since Phase 12 with
            // six real screens, but no demo button ever offered it - a
            // demo of the product genuinely could not show a parent's view.
            // demo.guardian1@opeschool.test is a real DemoDataSeeder
            // guardian with portal_user_id set on a real Guardian row.
            [
                'role' => 'guardian',
                'email' => 'demo.guardian1@opeschool.test',
                'name' => 'Demo Guardian',
            ],
            // The staff portal (/portal/staff) has existed since
            // Phase 12-13 with a real Show screen (profile, timetable,
            // leave, payslip PDF), but nothing ever granted any user the
            // staff_portal role - demo.staffportal@opeschool.test is
            // created by DemoDataSeeder2::seedStaffPortal() via the same
            // GrantStaffPortalAccess Action a real admin now uses from
            // /staff.
            [
                'role' => 'staff_portal',
                'email' => 'demo.staffportal@opeschool.test',
                'name' => 'Demo Staff',
            ],

            /*
             * The remaining twelve of the twenty roles in
             * Identity\Domain\Role. Eight were offered here and the other
             * twelve were not, which made the demo unable to show the thing
             * this product is most opinionated about: a Librarian who sees
             * the catalogue and nothing else, a Nurse who reaches the
             * medical record but not the roll, a Bursar who takes money but
             * cannot post to the ledger. RBAC that cannot be demonstrated
             * reads as RBAC that does not exist.
             *
             * demoLogin() provisions each account on first use and assigns
             * exactly the one role, so no seeder has to run first.
             */
            [
                'role' => 'vice_principal',
                'email' => 'demo.viceprincipal@opeschool.test',
                'name' => 'Demo Vice Principal',
            ],
            [
                'role' => 'exams_officer',
                'email' => 'demo.examsofficer@opeschool.test',
                'name' => 'Demo Exams Officer',
            ],
            [
                'role' => 'class_master',
                'email' => 'demo.classmaster@opeschool.test',
                'name' => 'Demo Class Master',
            ],
            [
                'role' => 'discipline_master',
                'email' => 'demo.disciplinemaster@opeschool.test',
                'name' => 'Demo Discipline Master',
            ],
            [
                'role' => 'hr_officer',
                'email' => 'demo.hrofficer@opeschool.test',
                'name' => 'Demo HR Officer',
            ],
            [
                'role' => 'payroll_officer',
                'email' => 'demo.payrollofficer@opeschool.test',
                'name' => 'Demo Payroll Officer',
            ],
            [
                'role' => 'librarian',
                'email' => 'demo.librarian2@opeschool.test',
                'name' => 'Demo Librarian',
            ],
            [
                'role' => 'store_keeper',
                'email' => 'demo.storekeeper@opeschool.test',
                'name' => 'Demo Store Keeper',
            ],
            [
                'role' => 'nurse',
                'email' => 'demo.nurse@opeschool.test',
                'name' => 'Demo Nurse',
            ],
            [
                'role' => 'welfare_officer',
                'email' => 'demo.welfareofficer@opeschool.test',
                'name' => 'Demo Welfare Officer',
            ],
            [
                'role' => 'front_desk',
                'email' => 'demo.frontdesk@opeschool.test',
                'name' => 'Demo Front Desk',
            ],

            /*
             * SuperAdmin is Permission::cases() - literally every right,
             * including the ones Administrator is deliberately refused, such
             * as backup.restore. It is here so the role set is complete and
             * the break-glass account can be demonstrated, but it is the one
             * identity worth removing before a demo URL is handed to people
             * you do not know: deleting this block is the whole change.
             */
            [
                'role' => 'super_admin',
                'email' => 'demo.superadmin@opeschool.test',
                'name' => 'Demo Super Admin',
            ],
        ],
    ],

    /*
     * Licensing (08-operations §4). Only PUBLIC key halves live here - no
     * private key of any kind exists in this repository or on a school
     * machine; tests generate throwaway pairs in memory.
     *
     * The two verification keys are DELIBERATELY SPLIT (§4.1): the ECDSA
     * P-256 key verifies offline .opeslic licence files, the RSA-2048 key
     * verifies activation-server responses, so a compromise of the
     * internet-facing activation server cannot forge offline licence files
     * and vice versa. An unset key makes that verification path fail closed
     * (Operations\Licensing\LicenceKeyType).
     */
    'licensing' => [
        // `?:` for the same present-but-empty reason as the backup path
        // above: an empty PEM must mean "not configured", never a key.
        'licence_file_public_key' => env('OPES_LICENCE_FILE_PUBLIC_KEY') ?: null,
        'activation_public_key' => env('OPES_LICENCE_ACTIVATION_PUBLIC_KEY') ?: null,

        // The ONLY network endpoint in the whole licensing stack: used by
        // ActivateOnline (once, on the operator's click) and the panel-only
        // opportunistic re-check. Null = offline school, both stay disabled;
        // no status check anywhere ever touches the network (§4.3).
        'activation_url' => env('OPES_LICENCE_ACTIVATION_URL') ?: null,

        // Machine-identity override for the activation fingerprint
        // (Operations\Licensing\MachineFingerprint). Semantics differ from
        // every key above ON PURPOSE: null (absent) = auto-detect from the
        // OS; SET, including set-to-empty, = use this exact value, which is
        // how the installer pins identity on machines where reading the OS
        // GUID needs elevation, and how tests model a machine with no
        // readable identity. So: plain env(), no `?:`.
        'fingerprint_source' => env('OPES_LICENCE_FINGERPRINT_SOURCE'),
    ],

    'health' => [
        'backup_amber_hours' => (int) env('OPES_BACKUP_AMBER_HOURS', 26),
        'backup_red_hours' => (int) env('OPES_BACKUP_RED_HOURS', 50),
        'drill_amber_days' => (int) env('OPES_DRILL_AMBER_DAYS', 40),
        'drill_red_days' => (int) env('OPES_DRILL_RED_DAYS', 60),
        'disk_amber_percent' => (int) env('OPES_DISK_AMBER_PERCENT', 85),
        'disk_red_percent' => (int) env('OPES_DISK_RED_PERCENT', 95),
        'queue_heartbeat_amber_minutes' => (int) env('OPES_QUEUE_AMBER_MINUTES', 10),
        'queue_heartbeat_red_minutes' => (int) env('OPES_QUEUE_RED_MINUTES', 30),
        'failed_jobs_amber' => (int) env('OPES_FAILED_JOBS_AMBER', 1),
        'failed_jobs_red' => (int) env('OPES_FAILED_JOBS_RED', 25),
    ],

    /*
     * Where to reach the people who supply the software - the strip along the
     * foot of the sign-in artwork.
     *
     * Config rather than a lang file, because none of it is TRANSLATABLE: a
     * phone number does not have a French rendering, and a reseller running
     * their own deployment needs to change these without editing two
     * translation files and hoping they stay in step. Env-overridable for
     * exactly that reason, with the artwork's values as the default.
     */
    'vendor' => [
        'city' => env('OPES_VENDOR_CITY', 'Douala, Cameroon'),
        'website' => env('OPES_VENDOR_WEBSITE', 'www.opesschoolsystem.com'),
        'phone' => env('OPES_VENDOR_PHONE', '+237 670 41 62 38'),
    ],
];

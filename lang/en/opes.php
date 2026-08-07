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

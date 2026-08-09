<?php

declare(strict_types=1);

// Licensing strings (docs/specs/08-operations.md §4). Binding rule from
// §4.3: every failure mode has a DISTINCT localized sentence, EN and FR,
// with a build-failing test (LicenceVerificationTest) if two collapse onto
// the same text. Do not "tidy" two of these into one wording. The licence
// key itself is never interpolated into any of them.
return [

    'state' => [
        'valid' => 'Licensed',
        'trial' => 'Trial',
        'expiring' => 'Expiring soon',
        'grace' => 'Expired — grace period',
        'enforced' => 'Expired',
        'revoked' => 'Revoked',
    ],

    'blocked' => [
        'enforced' => 'The licence has expired, so :operation is unavailable. Daily work — fee collection, receipts, attendance, marks, payroll and every export — continues without restriction. Renew the licence to continue.',
        'revoked' => 'The licence has been revoked, so :operation is unavailable. Daily work — fee collection, receipts, attendance, marks, payroll and every export — continues without restriction. Contact your vendor.',
    ],

    'operation' => [
        'academics' => [
            'create_year' => 'creating a new academic year',
        ],
        'assessment' => [
            'publish_period' => 'publishing report cards',
        ],
        'operations' => [
            'rollover' => 'the year rollover wizard',
        ],
        'documents' => [
            'bulk_generate' => 'bulk document generation',
        ],
    ],

    // Why the CACHED licence row failed offline verification (§4.3 order).
    'failure' => [
        'payload_unreadable' => 'The stored licence could not be read. Import your licence file again, or re-activate.',
        'file_signature_invalid' => 'The stored licence file no longer passes signature verification, so it is being ignored.',
        'activation_signature_invalid' => 'The stored activation no longer passes signature verification, so it is being ignored.',
        'wrong_product' => 'The stored licence was issued for a different product, so it is being ignored.',
        'fingerprint_mismatch' => 'The stored licence is bound to a different computer, so it is being ignored on this one.',
        'expiry_missing' => 'The stored licence carries no readable expiry date, so it is being ignored.',
    ],

    'import' => [
        'not_json' => 'That is not a licence file — it could not be read at all. Ask your vendor to resend the .opeslic file.',
        'malformed' => 'The licence file is incomplete: it is missing its payload or its signature.',
        'signature_invalid' => 'The licence file failed signature verification. It may have been altered in transit — ask your vendor to resend it.',
        'wrong_product' => 'This licence file was issued for a different product and cannot be imported here.',
        'expiry_missing' => 'The licence file carries no readable expiry date and cannot be imported.',
        'done' => 'Licence file imported. This school is licensed until :date.',
    ],

    'activate' => [
        'no_server' => 'No activation server is configured on this installation, so online activation is unavailable. Import a licence file instead.',
        'no_fingerprint' => 'This computer\'s identity could not be read, so it cannot be bound to a licence. No activation request was sent — import a licence file instead.',
        'unreachable' => 'The activation server could not be reached. Check the internet connection and try again, or import a licence file instead.',
        'invalid_key' => 'The activation server did not recognise that licence key. Check it for typing mistakes.',
        'no_seats' => 'That licence key has no seats left. Deactivate an old computer in your vendor account, then try again.',
        'rejected' => 'The activation server declined the request. Contact your vendor.',
        'malformed_response' => 'The activation server\'s reply was incomplete and has been discarded.',
        'signature_invalid' => 'The activation server\'s reply failed signature verification and has been discarded.',
        'wrong_product' => 'The activation server replied with a licence for a different product, which has been discarded.',
        'fingerprint_mismatch' => 'The activation server replied with a licence bound to a different computer, which has been discarded.',
        'expiry_missing' => 'The activation server\'s reply carries no readable expiry date and has been discarded.',
        'done' => 'Activated. This computer is licensed until :date.',
    ],

    'deactivate' => [
        'none' => 'There is no licence on this computer to remove.',
        'done' => 'The licence has been removed from this computer.',
        'seat_released' => 'The licence has been removed from this computer and its seat has been freed in your vendor account.',
        'seat_not_released' => 'The licence has been removed from this computer, but this computer still counts against your licence; deactivate it in your vendor account.',
    ],

    'panel' => [
        'title' => 'Licence',
        'subtitle' => 'How this installation is licensed, and how to renew it.',
        'breadcrumb_dashboard' => 'Dashboard',
        'breadcrumb_settings' => 'Settings',
        'breadcrumb_licence' => 'Licence status',
        'status_card' => 'Licence status',
        'expires_on' => 'Expires on',
        'days_left' => ':days day(s) remaining',
        'trial_intro' => 'This installation is running on the built-in trial: 30 days or 25 students, whichever comes first.',
        'trial_ends' => 'Trial window ends',
        'trial_clock_unset' => 'The trial clock starts when the installer completes first-run setup.',
        'students_on_books' => 'Students on the books',
        'grace_note' => 'Everything keeps working during the grace period. Renew now to avoid the year-end operations being paused.',
        'enforced_note' => 'Creating a new academic year, publishing report cards, the rollover wizard and bulk document generation are paused. Fee collection, receipts, attendance, marks, payroll and every export are never blocked.',
        'details_card' => 'Licence details',
        'holder' => 'Licensed to',
        'edition' => 'Edition',
        'student_cap' => 'Student cap',
        'source' => 'Source',
        'source_file' => 'Licence file (offline)',
        'source_activation' => 'Online activation (machine-bound)',
        'unlimited' => 'Unlimited',
        'import_card' => 'Import a licence file',
        'import_help' => 'Paste the contents of the .opeslic file your vendor sent. Nothing is sent over the network — the file is verified on this computer.',
        'import_placeholder' => 'Paste the .opeslic file contents here…',
        'import_button' => 'Import licence file',
        'activate_card' => 'Activate online',
        'activate_help' => 'Enter your licence key. This is the only step that needs the internet — exactly once. The signed licence is then verified offline forever after.',
        'activate_placeholder' => 'Licence key',
        'activate_button' => 'Activate this computer',
        'deactivate_card' => 'Remove the licence from this computer',
        'deactivate_help' => 'Moving to a new computer? Removing the licence here also frees your seat in the vendor account when the server can be reached.',
        'deactivate_button' => 'Remove licence',
        'never_blocked_title' => 'Never blocked, in any licence state',
        'never_blocked_body' => 'Fee collection and receipt printing, attendance, marks entry, payroll, the ledger, and every data export. This is a product commitment, stated in your contract.',
    ],
];

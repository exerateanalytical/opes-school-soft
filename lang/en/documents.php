<?php

declare(strict_types=1);

// docs/specs/10-documents.md 4.6 - every string a document Blade renders
// lives here (no literal in a document template; the sweep is an arch test).
// The DOCUMENT language selects this file, never the operator's UI locale.
return [
    'state_header' => [
        // 2.1: the bilingual state letterhead is bilingual BY DEFINITION -
        // both columns render whatever the document language, so both forms
        // exist in both files.
        'republic_fr' => 'RÉPUBLIQUE DU CAMEROUN',
        'motto_fr' => 'Paix – Travail – Patrie',
        'republic_en' => 'REPUBLIC OF CAMEROON',
        'motto_en' => 'Peace – Work – Fatherland',
    ],

    'school' => [
        'niu' => 'Taxpayer No. (NIU)',
        'rccm' => 'Trade Register (RCCM)',
        'accreditation' => 'Ministry accreditation No.',
    ],

    'subject' => [
        'name' => 'Name',
        'matricule' => 'Registration No.',
        'class_group' => 'Class',
        'section' => 'Section',
        'academic_year' => 'Academic year',
        'date_of_birth' => 'Date of birth',
    ],

    'signature' => [
        'date_line' => 'Signature and date',
    ],

    'signature_roles' => [
        'principal' => 'Principal',
        'vice_principal' => 'Vice-Principal',
        'registrar' => 'Registrar',
        'class_master' => 'Class Master',
        'bursar' => 'Bursar',
        'accountant' => 'Accountant',
        'librarian' => 'Librarian',
        'store_keeper' => 'Store Keeper',
        'discipline_master' => 'Discipline Master',
        'nurse' => 'Nurse',
        'guardian' => 'Parent / Guardian',
        'student' => 'Student',
        'staff' => 'Staff Member',
        'security' => 'Security',
        'teacher' => 'Teacher',
        'exams_officer' => 'Examinations Officer',
        'payroll_officer' => 'Payroll Officer',
        'hr_officer' => 'HR Officer',
        'hostel_warden' => 'Hostel Warden',
        'transport_officer' => 'Transport Officer',
        'gate_security' => 'Gate Security',
        'authorized_by' => 'Authorised by',
        'prepared_by' => 'Prepared by',
        'requested_by' => 'Requested by',
    ],

    'watermark' => [
        // 4.5: FR and EN forms both render DUPLICATA; the reference uses the
        // same word.
        'duplicata' => 'DUPLICATA',
        'void' => 'ANNULÉ / VOID',
        'specimen' => 'SPÉCIMEN / SPECIMEN',
    ],

    'footer' => [
        'series' => 'No.',
        'issued_on' => 'Issued on',
        'generated_on' => 'Generated on: :datetime by :user',
        'duplicate_note' => 'Duplicate No. :copy — printed on :date by :user',
        'page' => 'Page {PAGE_NUM} of {PAGE_COUNT}',
    ],

    'qr' => [
        'scan' => 'Scan to verify / Scanner pour vérifier',
    ],
];

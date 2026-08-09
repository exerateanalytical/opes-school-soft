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

    // phase-12-13 D3 - money documents (10-documents.md §10).
    'receipt' => [
        'title' => 'Payment Receipt',
        'receipt_no' => 'Receipt No.',
        'received_from' => 'Received from',
        'student' => 'Student',
        'matricule' => 'Matricule',
        'class' => 'Class',
        'date' => 'Date',
        'method' => 'Payment method',
        'reference' => 'Reference',
        'for' => 'For',
        'description' => 'Description',
        'amount' => 'Amount',
        'total' => 'Total received',
        'amount_words' => 'Amount in words',
        'paid_to_date' => 'Total received to date',
        'credit_balance' => 'Unallocated credit balance',
        'void_notice' => 'This receipt has been voided and is no longer valid.',
        'method_cash' => 'Cash',
        'method_mobile_money' => 'Mobile Money',
        'method_bank' => 'Bank Transfer',
        'currency_suffix' => 'CFA francs.',
    ],

    'invoice' => [
        'title' => 'Fee Invoice',
        'invoice_no' => 'Invoice No.',
        'to' => 'To',
        'student' => 'Student',
        'matricule' => 'Matricule',
        'class' => 'Class',
        'date' => 'Date',
        'due_date' => 'Due date',
        'description' => 'Description',
        'amount' => 'Amount',
        'tax' => 'Tax',
        'total' => 'Total',
        'total_due' => 'Total due',
        'balance_due' => 'Balance due',
        'amount_words' => 'Amount in words',
        'own_revenue' => 'School fees',
        'third_party' => 'Amounts collected on behalf of third parties / Sommes encaissées pour le compte de tiers',
        'thank_you' => 'Thank you for your prompt payment.',
        'currency_suffix' => 'CFA francs.',
    ],

    'statement' => [
        'title' => 'Student Account Statement',
        'student' => 'Student',
        'matricule' => 'Matricule',
        'class' => 'Class',
        'as_of' => 'As of',
        'date' => 'Date',
        'description' => 'Description',
        'reference' => 'Reference',
        'debit' => 'Debit',
        'credit' => 'Credit',
        'balance' => 'Balance',
        'brought_forward' => 'Balance brought forward',
        'closing_balance' => 'Closing balance',
        'no_lines' => 'No transactions recorded.',
    ],

    'attestation' => [
        'title' => 'Withholding Tax Attestation',
        'attestation_no' => 'Attestation No.',
        'supplier' => 'Supplier / Beneficiary',
        'niu' => 'Taxpayer No. (NIU)',
        'address' => 'Address',
        'period' => 'Period',
        'legal_basis' => 'Legal basis',
        'base_amount' => 'Base amount',
        'rate' => 'Rate applied',
        'withheld_amount' => 'Amount withheld',
        'related_document' => 'Related document',
        'body' => 'This is to certify that the school withheld tax at source on the payment described below, in accordance with the withholding rule named, and will remit it to the Public Treasury.',
    ],

    'voucher' => [
        'title' => 'Payment Voucher',
        'voucher_no' => 'Voucher No.',
        'paid_to' => 'Paid to',
        'niu' => 'Taxpayer No. (NIU)',
        'date' => 'Date',
        'method' => 'Payment method',
        'gross_amount' => 'Gross amount',
        'withholding_amount' => 'Withholding',
        'fee_amount' => 'Bank / transfer fee',
        'net_amount' => 'Net amount paid',
        'amount_words' => 'Amount in words',
        'allocations' => 'Applied to',
        'invoice' => 'Invoice',
        'amount' => 'Amount',
        'currency_suffix' => 'CFA francs.',
    ],
];

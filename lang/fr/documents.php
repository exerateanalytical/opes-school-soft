<?php

declare(strict_types=1);

// docs/specs/10-documents.md 4.6 - pendant français de lang/en/documents.php.
// La langue du DOCUMENT choisit ce fichier, jamais la locale de l'opérateur.
return [
    'state_header' => [
        'republic_fr' => 'RÉPUBLIQUE DU CAMEROUN',
        'motto_fr' => 'Paix – Travail – Patrie',
        'republic_en' => 'REPUBLIC OF CAMEROON',
        'motto_en' => 'Peace – Work – Fatherland',
    ],

    'school' => [
        'niu' => 'NIU',
        'rccm' => 'RCCM',
        'accreditation' => 'N° d\'agrément ministériel',
    ],

    'subject' => [
        'name' => 'Nom et prénom(s)',
        'matricule' => 'Matricule',
        'class_group' => 'Classe',
        'section' => 'Section',
        'academic_year' => 'Année scolaire',
        'date_of_birth' => 'Date de naissance',
    ],

    'signature' => [
        'date_line' => 'Signature et date',
    ],

    'signature_roles' => [
        'principal' => 'Proviseur',
        'vice_principal' => 'Censeur',
        'registrar' => 'Chef du secrétariat',
        'class_master' => 'Professeur principal',
        'bursar' => 'Intendant',
        'accountant' => 'Comptable',
        'librarian' => 'Bibliothécaire',
        'store_keeper' => 'Magasinier',
        'discipline_master' => 'Surveillant général',
        'nurse' => 'Infirmier(ère)',
        'guardian' => 'Parent / Tuteur',
        'student' => 'Élève',
        'staff' => 'Membre du personnel',
        'security' => 'Sécurité',
        'teacher' => 'Enseignant',
        'exams_officer' => 'Responsable des examens',
        'payroll_officer' => 'Responsable de la paie',
        'hr_officer' => 'Responsable RH',
        'hostel_warden' => 'Surveillant d\'internat',
        'transport_officer' => 'Responsable du transport',
        'gate_security' => 'Sécurité du portail',
        'authorized_by' => 'Autorisé par',
        'prepared_by' => 'Préparé par',
        'requested_by' => 'Demandé par',
    ],

    'watermark' => [
        'duplicata' => 'DUPLICATA',
        'void' => 'ANNULÉ / VOID',
        'specimen' => 'SPÉCIMEN / SPECIMEN',
    ],

    'footer' => [
        'series' => 'N°',
        'issued_on' => 'Délivré le',
        'generated_on' => 'Généré le : :datetime par :user',
        'duplicate_note' => 'Duplicata n° :copy — imprimé le :date par :user',
        'page' => 'Page {PAGE_NUM} sur {PAGE_COUNT}',
    ],

    'qr' => [
        'scan' => 'Scanner pour vérifier / Scan to verify',
    ],
];

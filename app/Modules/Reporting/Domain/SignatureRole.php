<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * docs/specs/10-documents.md 2.3 - the signature-role allow-list, as an enum
 * so "any other role fails template validation at save" is a parse failure,
 * not a review comment.
 *
 * The 2.3 paragraph names fourteen roles; the catalogue in 5-16 signs with a
 * handful more school-internal offices (teacher, exams officer, payroll
 * officer...). Those are included here because they are the school's OWN
 * staff signing the school's OWN documents - exactly what 13 permits. What
 * 13.2 forbids is signature blocks for STATE offices, and those are the
 * DENIED list below, refused by name with the message the spec requires.
 */
enum SignatureRole: string
{
    case Principal = 'principal';
    case VicePrincipal = 'vice_principal';
    case Registrar = 'registrar';
    case ClassMaster = 'class_master';
    case Bursar = 'bursar';
    case Accountant = 'accountant';
    case Librarian = 'librarian';
    case StoreKeeper = 'store_keeper';
    case DisciplineMaster = 'discipline_master';
    case Nurse = 'nurse';
    case Guardian = 'guardian';
    case Student = 'student';
    case Staff = 'staff';
    case Security = 'security';

    // Catalogue roles (5-16): school-internal signatories.
    case Teacher = 'teacher';
    case ExamsOfficer = 'exams_officer';
    case PayrollOfficer = 'payroll_officer';
    case HrOfficer = 'hr_officer';
    case HostelWarden = 'hostel_warden';
    case TransportOfficer = 'transport_officer';
    case GateSecurity = 'gate_security';
    case AuthorizedBy = 'authorized_by';
    case PreparedBy = 'prepared_by';
    case RequestedBy = 'requested_by';

    /**
     * 13.2's denied list - signature blocks for the Minister, the GCE Board
     * Chairman or the Director of the Office of the Baccalaureat make the
     * product a credential-forgery tool. Enumerated by name so the refusal
     * can quote the spec rather than say "unknown role".
     */
    public const DENIED = ['minister', 'gce_board_chairman', 'directeur_bac'];

    public static function isDenied(string $role): bool
    {
        return in_array(strtolower(trim($role)), self::DENIED, true);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * The fixed role set from docs/specs/00-core.md 9.1.
 *
 * Roles are a baseline, not a ceiling: every Permission is individually
 * grantable on top of a user's role. The French labels use the Cameroonian
 * titles (Proviseur, Censeur) rather than literal translations.
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Administrator = 'administrator';
    case Principal = 'principal';
    case VicePrincipal = 'vice_principal';
    case Registrar = 'registrar';
    case Bursar = 'bursar';
    case Accountant = 'accountant';
    case HrOfficer = 'hr_officer';
    case PayrollOfficer = 'payroll_officer';
    case ExamsOfficer = 'exams_officer';
    case ClassMaster = 'class_master';
    case Teacher = 'teacher';
    case DisciplineMaster = 'discipline_master';
    case Librarian = 'librarian';
    case StoreKeeper = 'store_keeper';
    case Nurse = 'nurse';
    case WelfareOfficer = 'welfare_officer';
    case FrontDesk = 'front_desk';
    case Guardian = 'guardian';
    case StaffPortal = 'staff_portal';

    public function label(string $locale = 'en'): string
    {
        return __('opes.roles.'.$this->value, [], $locale);
    }

    /** Portal roles are self-service and hold no operational permissions. */
    public function isPortal(): bool
    {
        return $this === self::Guardian || $this === self::StaffPortal;
    }

    /**
     * The seeded baseline. Portal roles get only the portal.access outer
     * gate (Phase 12): guardian access is decided per-child by
     * GuardianScopeMatrix (07-students 7.5), and granting anything more
     * through a role would create a second, contradictory source of truth
     * for the highest-risk boundary in the product.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::SuperAdmin => Permission::cases(),

            // array_values because array_filter keeps the original indexes,
            // and the withheld cases are no longer the last in the enum - the
            // gap they leave would break the list<Permission> contract.
            //
            // Phase 7: rollover.run rides this everything-but baseline, so
            // the Administrator can run the year rollover by default. The
            // phase-07 plan also suggested licence.manage here, but the
            // withhold below predates it and AuthorizationMatrixTest asserts
            // it as a security property - managing the licence stays a
            // SuperAdmin (or individually granted) right.
            self::Administrator => array_values(array_filter(
                Permission::cases(),
                static fn (Permission $p): bool => ! in_array(
                    $p,
                    [Permission::LicenceManage, Permission::BackupRestore],
                    true,
                ),
            )),

            // The Proviseur signs the bulletin, so publication is his; he does
            // not enter or validate marks himself.
            self::Principal => [
                Permission::UserView, Permission::AuditView,
                Permission::SettingView, Permission::FeeView, Permission::LedgerView,
                Permission::AcademicsView, Permission::StudentsView,
                Permission::ReportsPublish, Permission::ReportsView,
                // Phase 8: the Proviseur oversees the timetable and calendar,
                // reads attendance and discipline, and owns promotion - both
                // the evaluation and the irreversible apply (07-students
                // §10.6 puts the conseil de classe decision under his seal).
                Permission::TimetableView, Permission::TimetableManage,
                Permission::CalendarManage,
                Permission::AttendanceView, Permission::AttendanceAmend,
                Permission::DisciplineView,
                Permission::PromotionEvaluate, Permission::PromotionApply,
                // Phase 13: the Proviseur renders documents and holds the
                // clearance-gate override for Transfer/Leaving/Character
                // certificates (10-documents §19) - always with a recorded
                // reason. He does not hold the financial-reprint right.
                Permission::DocumentsPrint, Permission::DocumentsOverrideGate,
                // Phase 5 (03-tax-procurement): the Proviseur APPROVES the
                // spending chain - requisitions, purchase orders, payments -
                // and reads the tax position, while the money offices below
                // author it. The SoD pairs (create vs approve) are split
                // across Bursar/Accountant/Principal on purpose.
                Permission::ProcurementView, Permission::ProcurementRequisitionApprove,
                Permission::ProcurementOrderApprove, Permission::ProcurementPaymentApprove,
                Permission::TaxView,
                // Alumni: the Proviseur reads the register (his graduates,
                // his ceremonies); the record-keeping stays with the
                // registrar's office.
                Permission::AlumniView,
            ],

            // The Censeur shapes the academic structure, so he also shapes the
            // assessment framework that hangs off it - but deliberately NOT
            // marks.enter: 01-assessment 7.2's flow is two-person, and an
            // approver who can also author is one person, not two.
            self::VicePrincipal => [
                Permission::UserView, Permission::SettingView,
                Permission::AcademicsView, Permission::AcademicsManage,
                Permission::StudentsView,
                Permission::MarksValidate, Permission::AssessmentConfigure,
                Permission::ReportsPublish, Permission::ReportsView,
                // Phase 8: the Censeur builds the timetable, runs the
                // attendance operation day to day (take, amend, justify) and
                // manages discipline alongside the Surveillant Général. He
                // does NOT hold promotion rights - evaluation and apply stay
                // with the Proviseur per the phase-08 §1 matrix.
                Permission::TimetableView, Permission::TimetableManage,
                Permission::AttendanceView, Permission::AttendanceTake,
                Permission::AttendanceAmend, Permission::AttendanceJustify,
                Permission::DisciplineView, Permission::DisciplineManage,
            ],

            // The bursar reads the student roll to collect against it, but
            // never edits it - 07-students 7.5 keeps money and identity apart.
            self::Bursar => [
                Permission::FeeView, Permission::FeeCollect, Permission::StudentsView,
                Permission::ReportsView,
                // 02-accounting §21.3: the bursar RECORDS petty spend but
                // deliberately cannot approve it - maker and checker must be
                // two people, so ExpenseApprove lives on Accountant/Principal.
                Permission::ExpenseRecord,
                // Phase 13 (10-documents §19): reprinting a receipt or other
                // money document is reserved to the money offices, mirroring
                // the payment-void segregation in 04-fees.
                Permission::DocumentsPrint, Permission::DocumentsReprint,
                Permission::DocumentsReprintFinancial,
                // Phase 5 (03-tax-procurement): the bursar RECORDS the
                // payables chain - suppliers, orders, invoice capture,
                // payment - and deliberately holds NO approval right in it:
                // approving is the Principal's (orders, payments) and the
                // Accountant's (invoices), keeping author and approver two
                // people (§4).
                Permission::ProcurementView, Permission::ProcurementSupplierManage,
                Permission::ProcurementOrderManage, Permission::ProcurementInvoiceView,
                Permission::ProcurementInvoiceCreate, Permission::ProcurementPaymentRecord,
                Permission::TaxView,
            ],

            self::Accountant => [
                Permission::FeeView, Permission::LedgerView, Permission::LedgerPost,
                Permission::LedgerConfigure, Permission::FeeVoid, Permission::ReportsView,
                // Checker to the Bursar's maker (02-accounting §21.3): the
                // accountant approves and posts petty spend but does not
                // record it, so no one person can both raise and release cash.
                Permission::ExpenseApprove,
                // 04-fees: the accountant shapes the fee catalogue; the
                // bursar (who handles the cash) deliberately does not.
                Permission::FeeConfigure,
                // Phase 13 (10-documents §19): same financial-reprint right
                // as the bursar. DocumentsRevoke rides along with FeeVoid
                // above - voiding a payment automatically revokes its
                // receipt's IssuedDocument row (10-documents §10.1, "a
                // voided payment's receipt... IssuedDocument.status becomes
                // revoked"), and the accountant is the one role trusted with
                // FeeVoid, so she is the one who can carry out that same
                // action's direct, unavoidable consequence.
                Permission::DocumentsPrint, Permission::DocumentsReprint,
                Permission::DocumentsReprintFinancial, Permission::DocumentsRevoke,
                // Phase 5 (03-tax-procurement): the accountant APPROVES what
                // the bursar captured (invoices; match overrides) and voids
                // payments (mirroring FeeVoid), and owns the tax cycle -
                // generation AND recording the filing. She does not RECORD
                // supplier payments: recorder and voider stay two people
                // (§11.9), as do invoice author and approver (§4.6).
                Permission::ProcurementView, Permission::ProcurementInvoiceView,
                Permission::ProcurementInvoiceApprove, Permission::ProcurementInvoiceOverrideMatch,
                Permission::ProcurementPaymentVoid,
                Permission::TaxView, Permission::TaxDeclare, Permission::TaxFile,
            ],

            // 00-core 9.1: the registrar owns the student record end to end -
            // admissions, enrolment, guardians. Finalising a matricule is
            // granted here and almost nowhere else (07-students 6.4): it is
            // irreversible, so it stays with the office that owns the roll.
            self::Registrar => [
                Permission::AcademicsView,
                Permission::StudentsView, Permission::StudentsManage,
                Permission::StudentsMatriculeFinalise,
                Permission::GuardiansManage, Permission::AdmissionsManage,
                // Phase 8: the registrar reads the timetable (scheduling
                // context for enrolment) but never edits it.
                Permission::TimetableView,
                // Alumni: the registrar owns the student record end to end,
                // and the alumni register is that record's final chapter -
                // conversion, contact upkeep, the engagement log.
                Permission::AlumniView, Permission::AlumniManage,
            ],

            // 00-core 9.1: these three read the academic structure (year,
            // classes, subjects) and the roll, but do not shape either - that
            // is the Censeur's job (Vice-Principal, above). They differ only in
            // how far up the marks chain they sit.
            //
            // A Teacher holding marks.enter is NOT a licence to enter any
            // mark: 01-assessment 7.5 scopes entry to the allocations they are
            // assigned or delegated, and T22 asserts the deny-by-default. The
            // permission is the outer gate, not the whole check.
            self::Teacher => [
                Permission::AcademicsView, Permission::StudentsView,
                Permission::MarksEnter,
                // Phase 8: attendance.take is assignment-gated in the Action
                // (same outer-gate/inner-scope pattern as marks.enter): the
                // permission opens the door, the subject-allocation check
                // decides which registers this teacher may actually open.
                Permission::TimetableView,
                Permission::AttendanceView, Permission::AttendanceTake,
            ],

            // The Professeur Principal enters marks for his own subject and
            // validates his class's grid before it goes up.
            self::ClassMaster => [
                Permission::AcademicsView, Permission::StudentsView,
                Permission::MarksEnter, Permission::MarksValidate,
            ],

            // The exams office runs the assessment cycle end to end: it
            // configures frameworks, fills gaps in entry, validates and
            // publishes.
            self::ExamsOfficer => [
                Permission::AcademicsView, Permission::StudentsView,
                Permission::MarksEnter, Permission::MarksValidate,
                Permission::AssessmentConfigure, Permission::ReportsPublish, Permission::ReportsView,
            ],

            // Phase 8: the Surveillant Général polices attendance and owns
            // discipline casework. He views and justifies absences but does
            // not take registers (that is the teacher in front of the class)
            // and does not amend a submitted register (leadership only).
            self::DisciplineMaster => [
                Permission::AttendanceView, Permission::AttendanceJustify,
                Permission::DisciplineView, Permission::DisciplineManage,
            ],

            self::HrOfficer => [
                Permission::StaffView, Permission::StaffManage,
                Permission::LeaveApprove, Permission::TimesheetValidate,
            ],

            self::PayrollOfficer => [
                Permission::StaffView,
                Permission::PayrollView, Permission::PayrollRun,
                Permission::PayrollPay, Permission::DeclarationFile,
            ],

            self::Librarian => [
                Permission::LibraryView, Permission::LibraryManage,
                Permission::LibraryCirculate, Permission::LibraryWaiveFine,
            ],

            self::StoreKeeper => [
                Permission::InventoryView, Permission::InventoryManage,
                Permission::InventoryPost,
                Permission::AssetView, Permission::AssetManage,
            ],

            self::Nurse => [
                Permission::MedicalView, Permission::MedicalManage,
            ],

            self::WelfareOfficer => [
                Permission::TransportView, Permission::TransportManage,
                Permission::HostelView, Permission::HostelManage,
                Permission::VisitorManage,
                Permission::InsuranceView, Permission::InsuranceManage,
            ],

            self::FrontDesk => [
                Permission::VisitorManage,
            ],

            // Phase 12: portal roles hold exactly one permission - the outer
            // portal gate. Everything a guardian may actually SEE per child
            // is still decided by GuardianScopeMatrix (07-students 7.5);
            // portal.access only opens the /portal shell, so the matrix
            // remains the single source of truth for the high-risk boundary.
            self::Guardian, self::StaffPortal => [Permission::PortalAccess],
        };
    }
}

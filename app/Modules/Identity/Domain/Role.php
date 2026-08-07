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
     * The seeded baseline. Portal roles get nothing here: guardian access is
     * decided per-child by GuardianScopeMatrix (07-students 7.5, Phase 2), and
     * granting it through a role would create a second, contradictory source
     * of truth for the highest-risk boundary in the product.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::SuperAdmin => Permission::cases(),

            self::Administrator => array_filter(
                Permission::cases(),
                static fn (Permission $p): bool => ! in_array(
                    $p,
                    [Permission::LicenceManage, Permission::BackupRestore],
                    true,
                ),
            ),

            // The Proviseur signs the bulletin, so publication is his; he does
            // not enter or validate marks himself.
            self::Principal => [
                Permission::UserView, Permission::AuditView,
                Permission::SettingView, Permission::FeeView, Permission::LedgerView,
                Permission::AcademicsView, Permission::StudentsView,
                Permission::ReportsPublish,
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
                Permission::ReportsPublish,
            ],

            // The bursar reads the student roll to collect against it, but
            // never edits it - 07-students 7.5 keeps money and identity apart.
            self::Bursar => [
                Permission::FeeView, Permission::FeeCollect, Permission::StudentsView,
            ],

            self::Accountant => [
                Permission::FeeView, Permission::LedgerView, Permission::LedgerPost,
                Permission::FeeVoid,
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
                Permission::AssessmentConfigure, Permission::ReportsPublish,
            ],

            self::HrOfficer, self::PayrollOfficer,
            self::DisciplineMaster, self::Librarian, self::StoreKeeper,
            self::Nurse, self::WelfareOfficer, self::FrontDesk => [],

            self::Guardian, self::StaffPortal => [],
        };
    }
}

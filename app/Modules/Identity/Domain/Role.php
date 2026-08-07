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

            self::Principal => [
                Permission::UserView, Permission::AuditView,
                Permission::SettingView, Permission::FeeView, Permission::LedgerView,
                Permission::AcademicsView,
            ],

            self::VicePrincipal => [
                Permission::UserView, Permission::SettingView,
                Permission::AcademicsView, Permission::AcademicsManage,
            ],

            self::Bursar => [Permission::FeeView, Permission::FeeCollect],

            self::Accountant => [
                Permission::FeeView, Permission::LedgerView, Permission::LedgerPost,
                Permission::FeeVoid,
            ],

            // 00-core 9.1: these roles read the academic structure (year,
            // classes, subjects) but do not shape it - that is the Censeur's
            // job (Vice-Principal, above).
            self::Registrar, self::ExamsOfficer,
            self::ClassMaster, self::Teacher => [Permission::AcademicsView],

            self::HrOfficer, self::PayrollOfficer,
            self::DisciplineMaster, self::Librarian, self::StoreKeeper,
            self::Nurse, self::WelfareOfficer, self::FrontDesk => [],

            self::Guardian, self::StaffPortal => [],
        };
    }
}

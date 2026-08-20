<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

use App\Modules\Identity\Domain\Role;

/**
 * WHICH dashboard each role lands on.
 *
 * One dashboard for twenty roles was the original design, and it produced two
 * concrete defects: an Accountant landed on a screen with ZERO KPI cards
 * (every tile was gated on an identity or operations permission they do not
 * hold), and a Teacher landed on one card reading "—" beside a raw
 * LedgerIntegrityCheck authorization exception. Gating the admin tiles away
 * was right; having nothing to put in their place was not.
 *
 * This class is the map from role to CONTENT. It is pure metadata - the
 * component does the reading, because these panels cross ten modules and
 * DomainPurityTest keeps database access out of Domain.
 *
 * Three rules the tests enforce:
 *   - every Role case has an entry, so "we forgot one" is a red test rather
 *     than a support call from the one deployment that granted it;
 *   - at most SIX panels per role. Past six the eye stops reading a KPI strip
 *     and starts scanning it, and the seventh card is worse than absent;
 *   - every role must hold, in its OWN Role::defaultPermissions() baseline,
 *     the permission for at least one of its panels and one of its quick
 *     actions. A well-formed profile made entirely of permissions the role
 *     does not hold renders exactly the blank screen this phase removes, and
 *     that mistake is invisible without the assertion.
 *
 * Every panel and every quick action names the permission that opens it, and
 * both the reader and the component filter on it. A card that 403s when
 * clicked is worse than no card: it teaches the operator that the screen lies.
 */
final class RoleDashboard
{
    /**
     * panel key => the permission required to see it.
     *
     * Every string here is a real case of Identity\Domain\Permission - a
     * value the Gate has never heard of makes Gate::allows() return false
     * forever, and the panel silently never renders for anyone.
     *
     * @var array<string, string>
     */
    private const PANEL_PERMISSIONS = [
        // Identity / operations
        'active_users' => 'user.view',
        'roles_configured' => 'user.view',
        'system_health' => 'setting.view',
        'last_backup' => 'backup.run',
        'go_live_blockers' => 'setting.view',
        // Students / admissions
        'enrolment_count' => 'students.view',
        'admissions_pipeline' => 'admissions.manage',
        'documents_pending' => 'students.view',
        'activities_running' => 'activity.view',
        // Teaching
        'my_classes' => 'timetable.view',
        'my_timetable_today' => 'timetable.view',
        'registers_not_taken' => 'attendance.view',
        'registers_today' => 'attendance.view',
        'attendance_rate' => 'attendance.view',
        'unjustified_absences' => 'attendance.view',
        'marks_due' => 'marks.enter',
        // Assessment administration
        'periods_open' => 'academics.view',
        'unpublished_periods' => 'reports.publish',
        'marks_pending_validation' => 'marks.validate',
        'exams_scheduled' => 'academics.view',
        // Money
        'todays_collections' => 'fee.view',
        'unpaid_invoices' => 'fee.view',
        'aged_receivables' => 'fee.view',
        'cash_desk_state' => 'fee.collect',
        'cash_position' => 'ledger.view',
        'unposted_entries' => 'ledger.view',
        'open_periods' => 'ledger.view',
        // Procurement / stores
        'stock_below_reorder' => 'inventory.view',
        'open_requisitions' => 'inventory.view',
        'pending_receipts' => 'procurement.view',
        // HR / payroll
        'staff_count' => 'staff.view',
        'leave_requests_pending' => 'leave.approve',
        'timesheets_pending' => 'timesheet.validate',
        'payroll_run_state' => 'payroll.view',
        'declarations_due' => 'declaration.file',
        // Welfare
        'open_discipline_cases' => 'discipline.view',
        'todays_consultations' => 'medical.view',
        'open_referrals' => 'medical.view',
        'hostel_occupancy' => 'hostel.view',
        'transport_allocations_active' => 'transport.view',
        'insurance_policies_active' => 'insurance.view',
        'visitors_today' => 'visitor.manage',
        // Library
        'books_on_loan' => 'library.view',
        'overdue_loans' => 'library.view',
        'fines_due' => 'library.view',
        // Assets
        'assets_in_service' => 'asset.view',
        'maintenance_open' => 'asset.view',
    ];

    /**
     * quick-action key => [route name, permission].
     *
     * Every route name here exists in routes/web.php today; the component
     * still checks Route::has, because an action offered by a role profile
     * can outrun the module that provides it.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const QUICK_ACTIONS = [
        'take_register' => ['attendance.take', 'attendance.take'],
        'attendance_overview' => ['attendance.index', 'attendance.view'],
        'enter_marks' => ['marks.entry', 'marks.enter'],
        'results' => ['assessment.results.index', 'reports.view'],
        'my_timetable' => ['timetable.index', 'timetable.view'],
        'collect_fees' => ['fees.cashier', 'fee.collect'],
        'invoices' => ['fees.invoices.index', 'fee.view'],
        'record_expense' => ['accounting.expenses.index', 'expense.record'],
        'new_journal_entry' => ['ledger.journal-entries.create', 'ledger.post'],
        'trial_balance' => ['ledger.trial-balance', 'ledger.view'],
        'tax_dashboard' => ['tax.dashboard', 'tax.view'],
        'new_admission' => ['admissions.wizard', 'admissions.manage'],
        'find_student' => ['students.index', 'students.view'],
        'requisitions' => ['procurement.requisitions.index', 'procurement.view'],
        'receive_goods' => ['procurement.receipts.index', 'procurement.view'],
        'stock_levels' => ['inventory.index', 'inventory.view'],
        'run_payroll' => ['payroll.index', 'payroll.run'],
        'staff_directory' => ['hr.index', 'staff.view'],
        'log_consultation' => ['welfare.medical.index', 'medical.manage'],
        'log_discipline_case' => ['welfare.discipline.index', 'discipline.manage'],
        'hostel_desk' => ['welfare.hostel.index', 'hostel.view'],
        'transport_desk' => ['welfare.transport.index', 'transport.view'],
        'visitor_log' => ['welfare.visitors.index', 'visitor.manage'],
        'library_desk' => ['library.index', 'library.view'],
        'asset_register' => ['assets.index', 'asset.view'],
        'add_user' => ['users.index', 'user.manage'],
        'go_live_setup' => ['operations.setup', 'setting.view'],
        'settings' => ['settings.index', 'setting.view'],
        'reports' => ['reports.hub', 'reports.view'],
        // The administrator tiles the reference dashboard names
        // (`frontend images/super admin dashbaord.png`). Each points at a
        // route that EXISTS - Dashboard::quickActions() drops any that does
        // not, so a tile can never 404, and none was invented to fill the
        // grid. Two of the reference's nine - "Fee Structures" and a
        // standalone school-calendar screen - have no route in the platform
        // yet and are deliberately absent rather than pointed at something
        // adjacent and mislabelled.
        'add_student' => ['students.index', 'students.manage'],
        'add_staff' => ['hr.index', 'staff.view'],
        'academic_year' => ['academics.settings', 'academics.manage'],
        'bulk_import' => ['students.import', 'students.manage'],
        'backup_database' => ['operations.backups', 'backup.run'],
    ];

    /**
     * quick-action key => x-opes-nav-icon navKey.
     *
     * Deliberately NOT a lang key: an icon is not a translation, and putting
     * it in lang/ means a translator can silently change a glyph.
     *
     * @var array<string, string>
     */
    private const QUICK_ACTION_ICONS = [
        'take_register' => 'attendance',
        'attendance_overview' => 'attendance',
        'enter_marks' => 'results',
        'results' => 'results',
        'my_timetable' => 'timetable',
        'collect_fees' => 'finance',
        'invoices' => 'finance',
        'record_expense' => 'expenses',
        'new_journal_entry' => 'ledger',
        'trial_balance' => 'statements',
        'tax_dashboard' => 'tax',
        'new_admission' => 'admissions',
        'find_student' => 'students',
        'requisitions' => 'procurement',
        'receive_goods' => 'procurement',
        'stock_levels' => 'inventory',
        'run_payroll' => 'payroll',
        'staff_directory' => 'staff',
        'log_consultation' => 'medical',
        'log_discipline_case' => 'students',
        'hostel_desk' => 'hostel',
        'transport_desk' => 'transport',
        'visitor_log' => 'visitors',
        'library_desk' => 'library',
        'asset_register' => 'assets',
        'add_user' => 'users',
        'go_live_setup' => 'setup',
        'settings' => 'settings',
        'reports' => 'reports',
        'add_student' => 'students',
        'add_staff' => 'staff',
        'academic_year' => 'academics',
        'bulk_import' => 'import',
        'backup_database' => 'backups',
    ];

    /**
     * @return array{panels: list<string>, quick_actions: list<string>}
     */
    public static function for(Role $role): array
    {
        return match ($role) {
            // Portal principals never reach this screen (Dashboard::mount
            // aborts 403); an empty profile keeps that fact stated in one
            // more place rather than leaving dead configuration behind.
            Role::Guardian, Role::StaffPortal => ['panels' => [], 'quick_actions' => []],

            Role::SuperAdmin, Role::Administrator => [
                'panels' => ['active_users', 'system_health', 'last_backup', 'go_live_blockers', 'enrolment_count', 'cash_position'],
                'quick_actions' => [
                    'add_student', 'add_staff', 'academic_year', 'new_admission',
                    'bulk_import', 'backup_database', 'reports', 'add_user',
                    'go_live_setup', 'settings',
                ],
            ],

            // The Proviseur signs the bulletin and answers for the roll, the
            // money and the discipline log - but holds no attendance-taking
            // or marks-entry right, so nothing here offers him one.
            Role::Principal => [
                'panels' => ['enrolment_count', 'attendance_rate', 'unpaid_invoices', 'open_discipline_cases', 'unpublished_periods', 'go_live_blockers'],
                'quick_actions' => ['find_student', 'reports', 'requisitions', 'settings'],
            ],

            // The Censeur runs the academic operation day to day. He has no
            // fee.view, so the Proviseur's money card is deliberately absent
            // rather than rendered and filtered away.
            Role::VicePrincipal => [
                'panels' => ['enrolment_count', 'attendance_rate', 'registers_today', 'open_discipline_cases', 'marks_pending_validation', 'unpublished_periods'],
                'quick_actions' => ['take_register', 'my_timetable', 'find_student', 'log_discipline_case'],
            ],

            Role::Registrar => [
                'panels' => ['admissions_pipeline', 'enrolment_count', 'documents_pending', 'activities_running'],
                'quick_actions' => ['new_admission', 'find_student', 'my_timetable'],
            ],

            // Front desk holds exactly one permission (visitor.manage), so it
            // gets exactly the screen that permission earns. One honest card
            // beats five filtered-away ones.
            Role::FrontDesk => [
                'panels' => ['visitors_today'],
                'quick_actions' => ['visitor_log'],
            ],

            Role::Bursar => [
                'panels' => ['todays_collections', 'cash_desk_state', 'unpaid_invoices', 'aged_receivables', 'pending_receipts'],
                'quick_actions' => ['collect_fees', 'invoices', 'record_expense', 'reports'],
            ],

            Role::Accountant => [
                'panels' => ['cash_position', 'unposted_entries', 'open_periods', 'aged_receivables', 'unpaid_invoices'],
                'quick_actions' => ['new_journal_entry', 'trial_balance', 'tax_dashboard', 'reports'],
            ],

            // HR holds staff.view but NOT payroll.view - leave and timesheets
            // are its queue, not the payroll run.
            Role::HrOfficer => [
                'panels' => ['staff_count', 'leave_requests_pending', 'timesheets_pending'],
                'quick_actions' => ['staff_directory'],
            ],

            Role::PayrollOfficer => [
                'panels' => ['payroll_run_state', 'declarations_due', 'staff_count'],
                'quick_actions' => ['run_payroll', 'staff_directory'],
            ],

            Role::ExamsOfficer => [
                'panels' => ['periods_open', 'unpublished_periods', 'marks_pending_validation', 'marks_due', 'exams_scheduled'],
                'quick_actions' => ['enter_marks', 'results', 'reports'],
            ],

            // The Professeur Principal has neither timetable.view nor
            // attendance.view in his baseline, so his dashboard is the marks
            // chain and his class roll.
            Role::ClassMaster => [
                'panels' => ['marks_due', 'marks_pending_validation', 'periods_open', 'enrolment_count'],
                'quick_actions' => ['enter_marks', 'find_student'],
            ],

            Role::Teacher => [
                'panels' => ['my_classes', 'my_timetable_today', 'registers_not_taken', 'marks_due'],
                'quick_actions' => ['take_register', 'enter_marks', 'my_timetable'],
            ],

            Role::DisciplineMaster => [
                'panels' => ['open_discipline_cases', 'unjustified_absences', 'attendance_rate'],
                'quick_actions' => ['log_discipline_case', 'attendance_overview'],
            ],

            Role::Librarian => [
                'panels' => ['books_on_loan', 'overdue_loans', 'fines_due'],
                'quick_actions' => ['library_desk'],
            ],

            Role::StoreKeeper => [
                'panels' => ['stock_below_reorder', 'open_requisitions', 'assets_in_service', 'maintenance_open'],
                'quick_actions' => ['stock_levels', 'asset_register'],
            ],

            Role::Nurse => [
                'panels' => ['todays_consultations', 'open_referrals'],
                'quick_actions' => ['log_consultation'],
            ],

            // Welfare here is boarding, transport, insurance and the gate -
            // NOT the sick bay (medical.*) and NOT discipline, neither of
            // which is in this role's grant.
            Role::WelfareOfficer => [
                'panels' => ['hostel_occupancy', 'transport_allocations_active', 'insurance_policies_active', 'visitors_today'],
                'quick_actions' => ['hostel_desk', 'transport_desk', 'visitor_log'],
            ],
        };
    }

    public static function panelPermission(string $panel): ?string
    {
        return self::PANEL_PERMISSIONS[$panel] ?? null;
    }

    /**
     * @return array{0: string, 1: string}|null  [route name, permission]
     */
    public static function quickAction(string $key): ?array
    {
        return self::QUICK_ACTIONS[$key] ?? null;
    }

    public static function quickActionIcon(string $key): string
    {
        return self::QUICK_ACTION_ICONS[$key] ?? 'dashboard';
    }

    /** @return list<string> */
    public static function allPanels(): array
    {
        return array_keys(self::PANEL_PERMISSIONS);
    }

    /** @return list<string> */
    public static function allQuickActions(): array
    {
        return array_keys(self::QUICK_ACTIONS);
    }
}

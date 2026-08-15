<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Domain\RoleDashboard;
use Carbon\CarbonImmutable;
use App\Modules\Operations\Domain\SetupCheckStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Reads one dashboard panel's figure.
 *
 * Every read is a query builder read: this Action sits in Operations and
 * touches a dozen other modules, and ModuleBoundaryTest forbids importing
 * their Models while permitting exactly this.
 *
 * EVERY TABLE AND COLUMN BELOW WAS READ OUT OF information_schema BEFORE IT
 * WAS WRITTEN. The plan that specified this screen named ~15 tables that do
 * not exist (`fee_invoices`, `fee_payments`, `journal_lines`, `accounts`,
 * `medical_visits`, `library_loans`, `inventory_items`, `requisitions`,
 * `timetable_lessons`, `admissions`, `examinations`, `setup_checklist_items`),
 * and a wrong name here is a 500 on the most-visited screen in the product.
 *
 * NULL, NOT ZERO. Each read returns null when the underlying thing has not
 * been recorded, and only returns 0 when zero is the true, measured answer.
 * "No register has been taken today" and "every child was absent" are
 * different facts about a school (09-ui §3.3), and x-kpi-card renders null as
 * an em dash with a screen-reader label rather than printing a figure the
 * operator cannot tell apart from a real one.
 *
 * Permission is checked HERE as well as in the component: a panel is a read
 * of another module's data, and a caller who may not open that module may not
 * read its summary either.
 */
final class ReadDashboardPanels
{
    /**
     * Invoice money lives in the LINES, not on the invoice: `invoices` carries
     * no amount column at all. Total is SUM(line amount + line tax), settled is
     * SUM(payment_allocations.amount) over allocations that were never
     * reversed. Both are correlated subqueries so one wrapper can ask
     * "outstanding > 0" without loading every invoice into PHP.
     */
    private function outstandingInvoices(bool $overdueOnly): Builder
    {
        $query = DB::table('invoices as i')
            // The status enum is exactly draft/issued/cancelled - there is no
            // `overdue` and no `part_paid`, whatever the plan assumed.
            ->where('i.status', 'issued')
            ->select([
                'i.id',
                DB::raw('(SELECT COALESCE(SUM(il.amount + il.tax_amount), 0) FROM invoice_lines il WHERE il.invoice_id = i.id) as billed'),
                DB::raw('(SELECT COALESCE(SUM(pa.amount), 0) FROM payment_allocations pa WHERE pa.invoice_id = i.id AND pa.reversed_at IS NULL) as settled'),
            ]);

        if ($overdueOnly) {
            $query->whereDate('i.due_date', '<', today());
        }

        return DB::query()->fromSub($query, 'inv')->whereRaw('billed > settled');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}|null
     */
    public function read(string $panel): ?array
    {
        $permission = RoleDashboard::panelPermission($panel);

        if ($permission === null || ! Gate::allows($permission)) {
            return null;
        }

        return match ($panel) {
            // ── Identity and operations ────────────────────────────────────
            'active_users' => $this->panel($panel, DB::table('users')->where('status', 'active')->count(), 'blue', 'users', 'users.index'),
            'roles_configured' => $this->panel($panel, DB::table('roles')->count(), 'purple', 'users', 'users.index'),
            // These two carry a status pill rather than a numeral, so the
            // blade gives them its display slot and the value stays null.
            'system_health' => $this->panel($panel, null, 'green', 'operations', null),
            'last_backup' => $this->lastBackup(),
            'go_live_blockers' => $this->blockers(),

            // ── Students and admissions ────────────────────────────────────
            'enrolment_count' => $this->panel($panel, DB::table('enrollments')->whereIn('status', ['pending', 'active', 'suspended'])->count(), 'green', 'students', 'students.index'),
            'admissions_pipeline' => $this->panel($panel, DB::table('admission_applications')->whereIn('status', ['draft', 'submitted', 'under_review', 'accepted'])->count(), 'blue', 'admissions', 'admissions.index'),
            'documents_pending' => $this->documentsPending(),
            'activities_running' => $this->panel($panel, DB::table('activities')->where('status', 'active')->count(), 'purple', 'academics', 'activities.index'),

            // ── Teaching ───────────────────────────────────────────────────
            'my_classes' => $this->myClasses(),
            'my_timetable_today' => $this->myTimetableToday(),
            'registers_not_taken' => $this->registersNotTaken(),
            'registers_today' => $this->registersToday(),
            'attendance_rate' => $this->attendanceRate(),
            'unjustified_absences' => $this->unjustifiedAbsences(),
            'marks_due' => $this->openPeriods('marks_due', 'results', 'marks.entry'),

            // ── Assessment administration ──────────────────────────────────
            'periods_open' => $this->openPeriods('periods_open', 'results', 'assessment.results.index'),
            'unpublished_periods' => $this->unpublishedPeriods(),
            'marks_pending_validation' => $this->marksPendingValidation(),
            'exams_scheduled' => $this->panel($panel, DB::table('exams')->whereDate('scheduled_on', '>=', today())->count(), 'blue', 'examinations', 'assessment.examinations.index'),

            // ── Money ──────────────────────────────────────────────────────
            'todays_collections' => $this->todaysCollections(),
            'unpaid_invoices' => $this->unpaidInvoices(),
            'aged_receivables' => $this->agedReceivables(),
            'cash_desk_state' => $this->cashDeskState(),
            'cash_position' => $this->cashPosition(),
            'unposted_entries' => $this->unpostedEntries(),
            'open_periods' => $this->panel($panel, DB::table('fiscal_years')->where('status', 'open')->count(), 'blue', 'ledger', 'accounting.year-end'),

            // ── Procurement and stores ─────────────────────────────────────
            'stock_below_reorder' => $this->stockBelowReorder(),
            'open_requisitions' => $this->panel($panel, DB::table('store_requisitions')->whereIn('status', ['draft', 'submitted', 'approved'])->count(), 'blue', 'inventory', 'inventory.index'),
            'pending_receipts' => $this->pendingReceipts(),

            // ── HR and payroll ─────────────────────────────────────────────
            'staff_count' => $this->panel($panel, DB::table('staff_members')->where('status', 'active')->count(), 'green', 'staff', 'hr.index'),
            'leave_requests_pending' => $this->queue('leave_requests_pending', DB::table('leave_requests')->where('status', 'submitted')->count(), 'staff', 'hr.index'),
            'timesheets_pending' => $this->queue('timesheets_pending', DB::table('timesheets')->where('status', 'submitted')->count(), 'staff', 'hr.index'),
            'payroll_run_state' => $this->payrollRunState(),
            'declarations_due' => $this->declarationsDue(),

            // ── Welfare ────────────────────────────────────────────────────
            'open_discipline_cases' => $this->openDisciplineCases(),
            'todays_consultations' => $this->todaysConsultations(),
            'open_referrals' => $this->queue('open_referrals', DB::table('medical_referrals')->whereNull('followed_up_at')->count(), 'medical', 'welfare.medical.index'),
            'hostel_occupancy' => $this->panel($panel, DB::table('hostel_allocations')->where('status', 'active')->count(), 'green', 'hostel', 'welfare.hostel.index'),
            'transport_allocations_active' => $this->panel($panel, DB::table('transport_allocations')->where('status', 'active')->count(), 'blue', 'transport', 'welfare.transport.index'),
            'insurance_policies_active' => $this->panel($panel, DB::table('insurance_policies')->where('status', 'active')->count(), 'purple', 'insurance', 'welfare.insurance.index'),
            'visitors_today' => $this->panel($panel, DB::table('visitor_logs')->whereDate('checked_in_at', today())->count(), 'blue', 'visitors', 'welfare.visitors.index'),

            // ── Library ────────────────────────────────────────────────────
            'books_on_loan' => $this->panel($panel, DB::table('library_issues')->whereNull('returned_on')->count(), 'blue', 'library', 'library.index'),
            'overdue_loans' => $this->overdueLoans(),
            'fines_due' => $this->finesDue(),

            // ── Assets ─────────────────────────────────────────────────────
            'assets_in_service' => $this->panel($panel, DB::table('assets')->where('status', 'in_service')->count(), 'green', 'assets', 'assets.index'),
            'maintenance_open' => $this->maintenanceOpen(),

            default => null,
        };
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function panel(string $key, int|string|null $value, string $tone, string $icon, ?string $routeName, ?string $sub = null): array
    {
        return [
            'key' => $key,
            // Zero is a real, measured answer for a COUNT and is shown as 0.
            // The null-not-zero rule applies to figures that were never
            // recorded - see the per-panel readers below.
            'value' => $value,
            'sub' => $sub,
            'tone' => $tone,
            'icon' => $icon,
            // Never offer a link to a route that does not exist yet: the card
            // would 404 on click, which is worse than a card that is not
            // clickable.
            'route' => $routeName !== null && Route::has($routeName) ? $routeName : null,
        ];
    }

    /**
     * A work queue: empty is GOOD and reads green, anything waiting reads
     * amber. The tone is the fastest thing on the card to read, so it must
     * mean something.
     *
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function queue(string $key, int $count, string $icon, ?string $routeName): array
    {
        return $this->panel($key, $count, $count === 0 ? 'green' : 'amber', $icon, $routeName);
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function blockers(): array
    {
        $blocked = 0;

        foreach (app(EvaluateSetupReadiness::class)->handle() as $check) {
            if ($check['status'] === SetupCheckStatus::Blocked) {
                $blocked++;
            }
        }

        // There is no setup_checklist_items table - readiness is EVALUATED
        // against live data every time it is asked, never stored as a flag
        // somebody can tick.
        return $this->panel('go_live_blockers', $blocked, $blocked === 0 ? 'green' : 'pink', 'setup', 'operations.setup');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function documentsPending(): array
    {
        $count = DB::table('student_documents')
            ->where('verification_status', 'unverified')
            ->where('is_archived', false)
            ->count();

        return $this->queue('documents_pending', $count, 'system_documentation', 'students.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function lastBackup(): array
    {
        // This card used to be hard-coded to null on the theory that the blade
        // would fill it from a display slot; nothing ever did, so it printed a
        // permanent "—". Read the real thing, and when there is nothing to
        // read say so in the sub-line rather than leaving a bare dash.
        $completedAt = DB::table('backups')
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->max('completed_at');

        if ($completedAt === null) {
            return $this->panel('last_backup', null, 'amber', 'backups', 'operations.backups', __('opes.dashboard.sub_never_backed_up'));
        }

        return $this->panel(
            'last_backup',
            CarbonImmutable::parse($completedAt)->diffForHumans(),
            'green',
            'backups',
            'operations.backups',
        );
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function myClasses(): array
    {
        $staffId = $this->currentStaffId();

        if ($staffId === null) {
            return $this->panel('my_classes', null, 'blue', 'classes', 'timetable.index', __('opes.dashboard.sub_no_staff_record'));
        }

        $count = DB::table('timetable_slots')
            ->where('staff_member_id', $staffId)
            ->distinct()
            ->count('class_group_id');

        return $this->panel('my_classes', $count, 'blue', 'classes', 'timetable.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function myTimetableToday(): array
    {
        $staffId = $this->currentStaffId();

        if ($staffId === null) {
            return $this->panel('my_timetable_today', null, 'blue', 'timetable', 'timetable.index', __('opes.dashboard.sub_no_staff_record'));
        }

        $count = DB::table('timetable_slots')
            ->where('staff_member_id', $staffId)
            ->where('day_of_week', (int) today()->dayOfWeekIso)
            ->count();

        return $this->panel('my_timetable_today', $count, 'blue', 'timetable', 'timetable.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function registersNotTaken(): array
    {
        $staffId = $this->currentStaffId();

        if ($staffId === null) {
            return $this->panel('registers_not_taken', null, 'amber', 'attendance', 'attendance.index', __('opes.dashboard.sub_no_staff_record'));
        }

        $expected = DB::table('timetable_slots')
            ->where('staff_member_id', $staffId)
            ->where('day_of_week', (int) today()->dayOfWeekIso)
            ->pluck('class_group_id')
            ->unique();

        if ($expected->isEmpty()) {
            // No lessons today: "nothing to take" is not "you are behind".
            return $this->panel('registers_not_taken', null, 'green', 'attendance', 'attendance.index', __('opes.dashboard.sub_no_lessons_today'));
        }

        $taken = DB::table('attendance_registers')
            ->whereDate('date', today())
            ->whereIn('status', ['submitted', 'amended'])
            ->whereIn('class_group_id', $expected)
            ->pluck('class_group_id')
            ->unique();

        return $this->queue('registers_not_taken', $expected->diff($taken)->count(), 'attendance', 'attendance.index');
    }

    /**
     * The school-wide version, for the Censeur: registers OPENED today and
     * never submitted. Deliberately not the teacher's staff-scoped figure -
     * he is not behind on them, the school is.
     *
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function registersToday(): array
    {
        $count = DB::table('attendance_registers')
            ->whereDate('date', today())
            ->where('status', 'open')
            ->count();

        return $this->queue('registers_today', $count, 'attendance', 'attendance.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function attendanceRate(): array
    {
        $registerIds = DB::table('attendance_registers')
            ->whereDate('date', today())
            ->whereIn('status', ['submitted', 'amended'])
            ->pluck('id');

        if ($registerIds->isEmpty()) {
            // NULL, not 0%: zero registers is "not yet taken", not "nobody
            // came" (07-students §9, C5).
            return $this->panel('attendance_rate', null, 'blue', 'attendance', 'attendance.index');
        }

        $totals = DB::table('attendance_registers')
            ->whereIn('id', $registerIds)
            ->selectRaw('SUM(expected_count) as expected, SUM(present_count) as present, SUM(late_count) as late')
            ->first();

        // §9.6's formula, the same one Attendance\Livewire\Index uses:
        // (present + late) over (expected − suspended-rows), never plain
        // expected.
        $suspended = DB::table('attendance_records')
            ->whereIn('attendance_register_id', $registerIds)
            ->where('status', 'suspended')
            ->count();

        $denominator = (int) ($totals->expected ?? 0) - $suspended;

        if ($denominator <= 0) {
            return $this->panel('attendance_rate', null, 'blue', 'attendance', 'attendance.index');
        }

        $rate = ((int) ($totals->present ?? 0) + (int) ($totals->late ?? 0)) / $denominator * 100;

        return $this->panel(
            'attendance_rate',
            number_format($rate, 1).'%',
            $rate >= 90 ? 'green' : ($rate >= 75 ? 'amber' : 'pink'),
            'attendance',
            'attendance.index',
        );
    }

    /**
     * The Surveillant Général's queue: absences on the last 30 days of taken
     * registers that nobody has justified.
     *
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function unjustifiedAbsences(): array
    {
        $count = DB::table('attendance_records as ar')
            ->join('attendance_registers as reg', 'reg.id', '=', 'ar.attendance_register_id')
            ->whereIn('ar.status', ['absent', 'late'])
            ->where('ar.is_justified', false)
            ->whereIn('reg.status', ['submitted', 'amended'])
            ->whereDate('reg.date', '>=', today()->subDays(30))
            ->count();

        return $this->queue('unjustified_absences', $count, 'attendance', 'attendance.index');
    }

    /**
     * assessment_periods has no `is_open` column - the state is the `status`
     * enum (planned/open/closed).
     *
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function openPeriods(string $key, string $icon, string $routeName): array
    {
        $count = DB::table('assessment_periods')->where('status', 'open')->count();

        return $this->panel($key, $count, $count === 0 ? 'blue' : 'amber', $icon, $routeName);
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function unpublishedPeriods(): array
    {
        $count = DB::table('assessment_periods')
            ->where('status', 'closed')
            ->where('is_reporting_period', true)
            ->whereNotExists(static function (Builder $query): void {
                $query->select(DB::raw('1'))
                    ->from('period_publications')
                    ->whereColumn('period_publications.assessment_period_id', 'assessment_periods.id')
                    ->where('period_publications.status', 'published');
            })
            ->count();

        return $this->queue('unpublished_periods', $count, 'results', 'assessment.results.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function marksPendingValidation(): array
    {
        $count = DB::table('mark_approvals')->where('status', 'submitted')->count();

        return $this->queue('marks_pending_validation', $count, 'results', 'assessment.results.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function todaysCollections(): array
    {
        // The table is `payments`, not `fee_payments`, and the date it is
        // banked against is `value_date`.
        $query = DB::table('payments')->whereDate('value_date', today());

        if (! $query->exists()) {
            // No payment recorded today is NOT "zero francs collected" until
            // the desk has been opened - and printing 0 FCFA on a bursar's
            // dashboard at 08:00 reads as a bad day, not an empty one.
            return $this->panel('todays_collections', null, 'green', 'finance', 'fees.cashier');
        }

        $total = (int) DB::table('payments')->whereDate('value_date', today())->sum('amount');

        return $this->panel('todays_collections', $this->money($total), 'green', 'finance', 'fees.cashier');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function unpaidInvoices(): array
    {
        return $this->queue('unpaid_invoices', $this->outstandingInvoices(false)->count(), 'finance', 'fees.invoices.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function agedReceivables(): array
    {
        $overdue = (int) $this->outstandingInvoices(true)->sum(DB::raw('billed - settled'));

        return $this->panel(
            'aged_receivables',
            $this->money(max(0, $overdue)),
            $overdue > 0 ? 'pink' : 'green',
            'finance',
            'fees.invoices.index',
        );
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function cashDeskState(): array
    {
        $open = DB::table('cash_desk_sessions')->whereNull('closed_at')->count();

        return $this->panel('cash_desk_state', $open, $open === 0 ? 'blue' : 'green', 'finance', 'fees.cashier');
    }

    /**
     * OHADA class 5 is trésorerie - cash, bank and mobile money. There is no
     * `is_cash_or_bank` flag and no `accounts` table: the chart is
     * `chart_of_accounts` and the class is a tinyint.
     *
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function cashPosition(): array
    {
        $posted = DB::table('journal_entries')->where('status', 'posted')->exists();

        if (! $posted) {
            // Nothing has been posted, so there is no cash position - not a
            // cash position of zero.
            return $this->panel('cash_position', null, 'blue', 'finance_dashboard', 'ledger.trial-balance');
        }

        $balance = (int) DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->where('coa.account_class', 5)
            ->sum(DB::raw('jl.debit - jl.credit'));

        return $this->panel(
            'cash_position',
            $this->money($balance),
            $balance >= 0 ? 'green' : 'pink',
            'finance_dashboard',
            'ledger.trial-balance',
        );
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function unpostedEntries(): array
    {
        $count = DB::table('journal_entries')->where('status', 'draft')->count();

        return $this->queue('unposted_entries', $count, 'ledger', 'ledger.journal-entries.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function stockBelowReorder(): array
    {
        // Stock lives per (item, location) in stock_balances; the threshold
        // lives on the item. There is no `inventory_items` table.
        $count = DB::table('stock_balances as sb')
            ->join('items as it', 'it.id', '=', 'sb.item_id')
            ->where('it.is_stock_tracked', true)
            ->where('it.status', 'active')
            ->whereNotNull('it.reorder_level')
            ->whereColumn('sb.quantity_on_hand', '<', 'it.reorder_level')
            ->distinct()
            ->count('sb.item_id');

        return $this->panel('stock_below_reorder', $count, $count === 0 ? 'green' : 'pink', 'inventory', 'inventory.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function pendingReceipts(): array
    {
        $count = DB::table('purchase_orders')
            ->whereIn('status', ['approved', 'sent', 'partially_received'])
            ->count();

        return $this->queue('pending_receipts', $count, 'procurement', 'procurement.receipts.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function payrollRunState(): array
    {
        $status = DB::table('payroll_runs')->orderByDesc('id')->value('status');

        return $this->panel(
            'payroll_run_state',
            // Null when payroll has never been run - the one fact this card
            // exists to carry, and 'draft' would be a lie about it.
            is_string($status) ? (string) __('opes.dashboard.payroll_state_'.$status) : null,
            $status === 'paid' || $status === 'closed' ? 'green' : 'amber',
            'payroll',
            'payroll.index',
        );
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function declarationsDue(): array
    {
        $count = DB::table('statutory_declarations')
            ->whereIn('status', ['due', 'generated', 'late'])
            ->count();

        return $this->panel('declarations_due', $count, $count === 0 ? 'green' : 'pink', 'tax', 'tax.declarations.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function openDisciplineCases(): array
    {
        // The enum is open/under_investigation/resolved/dismissed - there is
        // no `closed`.
        $count = DB::table('discipline_cases')
            ->whereIn('status', ['open', 'under_investigation'])
            ->where('is_positive', false)
            ->count();

        return $this->queue('open_discipline_cases', $count, 'students', 'welfare.discipline.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function todaysConsultations(): array
    {
        $count = DB::table('medical_consultations')->whereDate('visited_at', today())->count();

        return $this->panel('todays_consultations', $count, 'green', 'medical', 'welfare.medical.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function overdueLoans(): array
    {
        $count = DB::table('library_issues')
            ->whereNull('returned_on')
            ->whereDate('due_on', '<', today())
            ->count();

        return $this->panel('overdue_loans', $count, $count === 0 ? 'green' : 'pink', 'library', 'library.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function finesDue(): array
    {
        $assessed = DB::table('library_fines')->where('status', 'assessed');

        if (! $assessed->exists()) {
            return $this->panel('fines_due', null, 'green', 'finance', 'library.index');
        }

        $total = (int) DB::table('library_fines')
            ->where('status', 'assessed')
            ->sum(DB::raw('amount - waived_amount'));

        return $this->panel('fines_due', $this->money($total), $total > 0 ? 'amber' : 'green', 'finance', 'library.index');
    }

    /**
     * @return array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}
     */
    private function maintenanceOpen(): array
    {
        $count = DB::table('asset_maintenance_requests')
            ->whereIn('status', ['open', 'assigned', 'in_progress'])
            ->count();

        return $this->queue('maintenance_open', $count, 'assets', 'assets.index');
    }

    /** FCFA has no minor unit: never a decimal, always a thin-space group. */
    private function money(int $amount): string
    {
        return number_format($amount, 0, '.', ' ').' FCFA';
    }

    /**
     * The staff_members row for the signed-in user, or null when the account
     * is not linked to one (an administrator account, a service account).
     */
    private function currentStaffId(): ?int
    {
        $userId = auth()->id();

        if ($userId === null) {
            return null;
        }

        // The link column is `portal_user_id`, not `user_id`.
        $id = DB::table('staff_members')->where('portal_user_id', $userId)->value('id');

        return $id === null ? null : (int) $id;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Fees\Actions\AgedBalances;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Support\Navigation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The figures behind the administrator landing screen in
 * `frontend images/super admin dashbaord.png`.
 *
 * WHY A SEPARATE ACTION FROM ReadDashboardPanels. That one answers "what does
 * THIS ROLE get on its tiles" and is driven by RoleDashboard metadata; this
 * one answers "what does the administrator's composed screen show", which is
 * a fixed set of eleven panels the design names explicitly. Folding the
 * second into the first would make every role pay for eleven cross-module
 * aggregates to render four tiles.
 *
 * THREE RULES, each of which this screen previously had a way of breaking:
 *
 * 1. EVERY panel is permission-gated, and a panel the reader may not see
 *    returns null rather than zero. A bursar-only figure rendered as "0" to a
 *    librarian is not privacy, it is a lie that looks like data.
 *
 * 2. EVERY block is wrapped. This screen is the post-login destination for
 *    every staff principal in the product, so one missing table on a
 *    part-migrated install must degrade a single panel, never lock everyone
 *    out of the platform. A failed block returns null and the panel does not
 *    render.
 *
 * 3. Module ACTIONS are reused, never re-implemented. Top fee balances comes
 *    from Fees\Actions\AgedBalances - the same code the receivables screen
 *    and the statements run on - because a second, subtly different
 *    definition of "what a family owes" is how two screens in one product
 *    start quoting different numbers at the same parent.
 */
final readonly class ReadAdminDashboard
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return [
            'students_total' => $this->guard(Permission::StudentsView, fn (): int => (int) DB::table('students')
                ->where('is_archived', false)
                ->whereNull('left_on')
                ->count()),

            'staff_total' => $this->guard(Permission::StaffView, fn (): int => (int) DB::table('staff_members')
                ->where('status', 'active')
                ->count()),

            'classes_total' => $this->guard(Permission::AcademicsView, fn (): int => (int) DB::table('class_groups')->count()),

            'attendance_today' => $this->guard(Permission::AttendanceView, fn (): ?array => $this->attendanceToday()),

            'fees_this_month' => $this->guard(Permission::FeeView, fn (): int => (int) DB::table('payments')
                ->whereNull('bounced_on')
                ->whereBetween('value_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('amount')),

            'modules' => $this->modules(),

            'student_strength' => $this->guard(Permission::StudentsView, fn (): array => $this->studentStrength()),

            'financial_overview' => $this->guard(Permission::LedgerView, fn (): array => $this->financialOverview()),

            'top_balances' => $this->guard(Permission::FeeView, fn (): array => $this->topBalances()),

            'recent_activities' => $this->guard(Permission::AuditView, fn (): array => $this->recentActivities()),

            'upcoming_events' => $this->guard(Permission::AcademicsView, fn (): array => $this->upcomingEvents()),
        ];
    }

    /**
     * Run a block only if the reader holds the permission, and only if it
     * does not throw. Both failure modes collapse to null, which the view
     * reads as "this panel does not render" - never as a zero.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private function guard(Permission $permission, callable $read): mixed
    {
        if (! Gate::allows($permission->value)) {
            return null;
        }

        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Today's attendance as taken, not as expected: present over expected
     * across every register submitted for the business date.
     *
     * @return array{present: int, expected: int, percent: float}|null
     */
    private function attendanceToday(): ?array
    {
        $row = DB::table('attendance_registers')
            ->whereDate('date', now()->toDateString())
            ->selectRaw('COALESCE(SUM(present_count), 0) as present, COALESCE(SUM(expected_count), 0) as expected')
            ->first();

        $expected = (int) ($row->expected ?? 0);

        // No register taken yet today is a real state, and it is NOT "0%
        // present" - that would read as a school-wide absence. The panel
        // renders an em dash instead.
        if ($expected === 0) {
            return null;
        }

        $present = (int) ($row->present ?? 0);

        return [
            'present' => $present,
            'expected' => $expected,
            'percent' => round($present / $expected * 100, 1),
        ];
    }

    /**
     * "26 / 26 modules, 100% operational" - built modules over declared
     * modules, counted off Navigation rather than a hard-coded numeral, so
     * the figure cannot drift the moment a module ships.
     *
     * @return array{built: int, total: int, percent: int}
     */
    private function modules(): array
    {
        /*
         * Counted off app/Modules, because that IS the module list - the
         * reference's "26 / 26" is this application's own directory count,
         * not a figure someone chose. Deriving it means the card cannot go
         * stale the day a twenty-seventh module ships.
         *
         * A module counts as OPERATIONAL when at least one of its nav items
         * is built; a module whose every screen is still a placeholder is
         * the only kind that honestly is not running yet.
         */
        $modules = Cache::remember('dashboard.module_count', now()->addHour(), static function (): int {
            $path = app_path('Modules');

            return is_dir($path) ? count(glob($path.'/*', GLOB_ONLYDIR) ?: []) : 0;
        });

        $placeholders = Navigation::placeholderKeys();

        $unbuilt = 0;

        foreach (Navigation::groups() as $itemKeys) {
            $anyBuilt = false;

            foreach ($itemKeys as $itemKey) {
                if (! in_array($itemKey, $placeholders, true)) {
                    $anyBuilt = true;

                    break;
                }
            }

            if (! $anyBuilt) {
                $unbuilt++;
            }
        }

        $built = max(0, $modules - $unbuilt);

        return [
            'built' => $built,
            'total' => $modules,
            'percent' => $modules === 0 ? 0 : (int) round($built / $modules * 100),
        ];
    }

    /**
     * The roll broken down the way the reference breaks it down. Counted off
     * ENROLMENTS for the current year, not off the students table, because
     * "how many are here this year" and "how many records exist" are
     * different questions and the panel asks the first.
     *
     * @return array{total: int, male: int, female: int, day: int, boarding: int}
     */
    private function studentStrength(): array
    {
        $yearId = DB::table('academic_years')->where('is_current', true)->value('id');

        $base = DB::table('enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->when($yearId !== null, fn ($q) => $q->where('e.academic_year_id', $yearId))
            ->whereNull('e.left_on');

        $byGender = (clone $base)->selectRaw('s.gender, COUNT(*) as n')->groupBy('s.gender')->pluck('n', 'gender');
        $byBoarding = (clone $base)->selectRaw('e.boarding_status, COUNT(*) as n')->groupBy('e.boarding_status')->pluck('n', 'boarding_status');

        return [
            'total' => (int) (clone $base)->count(),
            'male' => (int) ($byGender['male'] ?? 0),
            'female' => (int) ($byGender['female'] ?? 0),
            'day' => (int) ($byBoarding['day'] ?? 0),
            'boarding' => (int) ($byBoarding['boarder'] ?? 0),
        ];
    }

    /**
     * Collection, spend and the difference, for the current month.
     *
     * @return array{collection: int, expenses: int, balance: int}
     */
    private function financialOverview(): array
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $collection = (int) DB::table('payments')
            ->whereNull('bounced_on')
            ->whereBetween('value_date', [$from, $to])
            ->sum('amount');

        $expenses = 0;

        if (Schema::hasTable('supplier_payments')) {
            // payment_date / net_amount, NOT value_date / amount - those are
            // the customer-side `payments` column names, and using them here
            // threw, was swallowed by guard(), and made the whole Financial
            // Overview panel vanish with no error anywhere.
            $expenses = (int) DB::table('supplier_payments')
                ->whereBetween('payment_date', [$from, $to])
                ->sum('net_amount');
        }

        return [
            'collection' => $collection,
            'expenses' => $expenses,
            'balance' => $collection - $expenses,
        ];
    }

    /**
     * The five largest family balances, from the SAME action the receivables
     * screen runs on - see this class's rule 3.
     *
     * @return list<array{name: string, amount: int}>
     */
    private function topBalances(): array
    {
        /*
         * Cached because of what AgedBalances DOES, not because of what it
         * costs on this database: it walks every issued invoice, instalment,
         * allocation, adjustment, credit note and write-off in the school to
         * answer "what does each family owe". On the 933-student demo school
         * that is 0.07s and would not need caching; on a school with years
         * of real invoice history behind it, it is the most expensive read
         * on a page every administrator lands on at login.
         *
         * The cache is NOT a second definition of the number - it is the
         * same action's answer, held briefly. Five minutes, keyed on the
         * business date so it turns over with the day, is well inside the
         * tolerance of a "top five debtors" panel: a payment taken now shows
         * on the receivables screen (which is uncached) immediately, and
         * here within the interval.
         */
        return \Illuminate\Support\Facades\Cache::remember(
            'dashboard.top_balances.'.now()->toDateString(),
            now()->addMinutes(5),
            fn (): array => $this->computeTopBalances(),
        );
    }

    /**
     * @return list<array{name: string, amount: int}>
     */
    private function computeTopBalances(): array
    {
        $rows = app(AgedBalances::class)->handle()
            ->filter(static fn (object $row): bool => $row->net > 0)
            ->sortByDesc('net')
            ->take(5);

        if ($rows->isEmpty()) {
            return [];
        }

        $names = DB::table('students')
            ->whereIn('id', $rows->pluck('student_id')->all())
            ->select(['id', 'first_name', 'last_name'])
            ->get()
            ->keyBy('id');

        return $rows->map(static function (object $row) use ($names): array {
            $student = $names[$row->student_id] ?? null;

            return [
                'name' => $student === null
                    ? '#'.$row->student_id
                    : trim($student->first_name.' '.$student->last_name),
                'amount' => $row->net,
            ];
        })->values()->all();
    }

    /**
     * The audit trail, read as an activity feed. The audit log is the one
     * record of "what just happened" that spans every module, so the panel
     * reads it rather than each module keeping its own feed.
     *
     * @return list<array{action: string, module: string, at: Carbon}>
     */
    private function recentActivities(): array
    {
        return DB::table('audit_logs')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['action', 'module', 'created_at'])
            ->map(static fn (object $row): array => [
                'action' => (string) $row->action,
                'module' => (string) ($row->module ?? ''),
                'at' => Carbon::parse($row->created_at),
            ])
            ->all();
    }

    /**
     * What the school calendar has coming, from today forward.
     *
     * @return list<array{title: string, on: Carbon, detail: string}>
     */
    private function upcomingEvents(): array
    {
        $labelColumn = app()->getLocale() === 'fr' ? 'label_fr' : 'label';

        return DB::table('school_calendar_days')
            ->whereDate('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->limit(4)
            ->get(['date', 'day_type', 'label', 'label_fr'])
            ->map(static fn (object $row): array => [
                // A calendar row's label is optional and usually empty, so
                // day_type is the fallback NAME rather than a subtitle -
                // otherwise every event in the panel reads "teaching" with
                // no title above it.
                'title' => (string) ($row->{$labelColumn} ?: $row->label
                    ?: __('opes.calendar.day_type_'.$row->day_type)),
                'on' => Carbon::parse($row->date),
                // Empty only when the row had no label of its own - in that
                // case the type is already the TITLE, and repeating it here
                // printed "Teaching day" twice in every event. The cast is
                // load-bearing: these columns are nullable, and null === ''
                // is false, which is exactly how the duplicate got through.
                'detail' => (string) ($row->{$labelColumn} ?? '') === '' && (string) ($row->label ?? '') === ''
                    ? ''
                    : (string) __('opes.calendar.day_type_'.$row->day_type),
            ])
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Operations\Livewire;

use App\Modules\Identity\Domain\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use stdClass;

/**
 * The "What's open right now" panel (docs/specs/08-operations.md §6.4),
 * embedded on the dashboard. At all times it shows: the active academic year
 * and its date range, the active exercice and its date range, the current
 * accounting period and its lock state, the soft- and hard-locked months,
 * the next scheduled forced quarterly closure (AUDCIF Art. 22), and whether
 * any assessment period is open for marks entry. Four questions a bursar
 * asks daily, answered in one glance, in one place.
 *
 * Visibility: the spec names Bursar, Accountant, Principal and
 * Administrator. Those are exactly the roles holding `fee.view` or
 * `ledger.view`, so the panel keys on the PERMISSIONS (roles are a baseline,
 * not a ceiling - 00-core 9.1) and renders nothing for anyone else. An
 * absence, not a 403: the dashboard is shared ground and the panel simply
 * is not this operator's business.
 *
 * Every read crosses a module boundary (Academics, Accounting, Assessment),
 * so everything goes through DB::table - never another module's model
 * (tests/Architecture/ModuleBoundaryTest.php).
 */
final class WhatsOpenPanel extends Component
{
    public function render(): View
    {
        if (! $this->visible()) {
            return view('livewire.operations.whats-open-panel', ['data' => null]);
        }

        $today = Carbon::now()->toDateString();

        $year = DB::table('academic_years')->where('is_current', true)->first();

        $exercice = DB::table('fiscal_years')
            ->where('status', 'open')
            ->orderByDesc('starts_on')
            ->first();

        $period = $exercice === null ? null : DB::table('accounting_periods')
            ->where('fiscal_year_id', (int) $exercice->id)
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->first();

        $locked = DB::table('accounting_periods')
            ->whereIn('status', ['soft_locked', 'hard_locked'])
            ->orderBy('period_month')
            ->get(['period_month', 'status'])
            ->map(static fn (stdClass $row): array => [
                'month' => Carbon::parse((string) $row->period_month)->isoFormat('MMM YYYY'),
                'status' => (string) $row->status,
            ])
            ->all();

        // The next forced quarterly closure still ahead of an open period
        // (AUDCIF Art. 22, 02-accounting §5.3).
        $nextClosure = DB::table('accounting_periods')
            ->where('is_quarter_end', true)
            ->where('status', 'open')
            ->whereNotNull('forced_closure_due_on')
            ->whereDate('forced_closure_due_on', '>=', $today)
            ->orderBy('forced_closure_due_on')
            ->value('forced_closure_due_on');

        $marksOpen = (int) DB::table('assessment_periods')
            ->where('status', 'open')
            ->count();

        return view('livewire.operations.whats-open-panel', [
            'data' => [
                'year' => $year,
                'exercice' => $exercice,
                'period' => $period,
                'locked' => $locked,
                'nextClosure' => is_string($nextClosure) ? Carbon::parse($nextClosure)->toDateString() : null,
                'marksOpen' => $marksOpen,
            ],
        ]);
    }

    private function visible(): bool
    {
        return Gate::allows(Permission::FeeView->value)
            || Gate::allows(Permission::LedgerView->value);
    }
}

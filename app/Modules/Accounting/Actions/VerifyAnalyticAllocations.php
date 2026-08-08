<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\AnalyticAxis;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §12.3 - the on-demand re-assertion of
 * AN-1/AN-2/AN-3 across posted (and reversed) entries. This is the BODY of
 * the nightly job and of the blocking YearEndChecklist step; scheduling is
 * the integrator's (handoff: wire this into the scheduler and the
 * checklist - it takes an optional fiscal-year scope for exactly that).
 *
 * Read-only: violations are RETURNED, never "fixed". The amount summation
 * is done in PHP via Money, per the module's never-SQL-arithmetic rule.
 *
 * Reads go through DB::table() deliberately: these are cross-table
 * reporting joins whose selected columns belong to three different tables,
 * not any one Model's attribute set.
 */
final class VerifyAnalyticAllocations
{
    public const PERMISSION = Permission::LedgerView->value;

    /**
     * @return list<array{invariant: string, journal_entry_line_id: int, analytic_axis_id: int, detail: string}>
     */
    public function handle(?int $fiscalYearId = null): array
    {
        Gate::authorize(self::PERMISSION);

        $violations = [];

        // ---- AN-1 / AN-2: every (line, axis) group present in the pivot.
        $pivotRows = DB::table('journal_entry_line_analytics')
            ->join('journal_entry_lines', 'journal_entry_lines.id', '=', 'journal_entry_line_analytics.journal_entry_line_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entries.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->when($fiscalYearId !== null, fn ($query) => $query->where('journal_entries.fiscal_year_id', $fiscalYearId))
            ->orderBy('journal_entry_line_analytics.journal_entry_line_id')
            ->orderBy('journal_entry_line_analytics.analytic_axis_id')
            ->get([
                'journal_entry_line_analytics.journal_entry_line_id as line_id',
                'journal_entry_line_analytics.analytic_axis_id as axis_id',
                'journal_entry_line_analytics.amount',
                'journal_entry_line_analytics.share_bp',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
            ]);

        /** @var array<string, array{line_id: int, axis_id: int, sum: Money, share_bp: int, signed: Money}> $groups */
        $groups = [];

        foreach ($pivotRows as $row) {
            $key = $row->line_id.':'.$row->axis_id;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'line_id' => (int) $row->line_id,
                    'axis_id' => (int) $row->axis_id,
                    'sum' => Money::zero(),
                    'share_bp' => 0,
                    'signed' => Money::of((int) $row->debit - (int) $row->credit),
                ];
            }

            $groups[$key]['sum'] = $groups[$key]['sum']->plus(Money::of((int) $row->amount));
            $groups[$key]['share_bp'] += (int) $row->share_bp;
        }

        foreach ($groups as $group) {
            // AN-1: Σ amount conserves the line's signed value, hence its
            // magnitude (|Σ amount| = debit + credit, one side being zero).
            if (! $group['sum']->equals($group['signed'])) {
                $violations[] = [
                    'invariant' => 'AN-1',
                    'journal_entry_line_id' => $group['line_id'],
                    'analytic_axis_id' => $group['axis_id'],
                    'detail' => sprintf(
                        'Sum of analytic amounts %d does not conserve the line value %d.',
                        $group['sum']->amount(),
                        $group['signed']->amount(),
                    ),
                ];
            }

            if ($group['share_bp'] !== AllocateLineAnalytics::FULL_SHARE_BP) {
                $violations[] = [
                    'invariant' => 'AN-2',
                    'journal_entry_line_id' => $group['line_id'],
                    'analytic_axis_id' => $group['axis_id'],
                    'detail' => sprintf(
                        'Sum of shares is %d basis points; expected %d.',
                        $group['share_bp'],
                        AllocateLineAnalytics::FULL_SHARE_BP,
                    ),
                ];
            }
        }

        // ---- AN-3: mandatory axes on requires_analytic accounts.
        /** @var \Illuminate\Support\Collection<int, AnalyticAxis> $mandatoryAxes */
        $mandatoryAxes = AnalyticAxis::query()
            ->where('is_mandatory', true)
            ->where('is_active', true)
            ->where('is_archived', false)
            ->get();

        if ($mandatoryAxes->isEmpty()) {
            return $violations;
        }

        $lines = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_entry_lines.account_id')
            ->whereIn('journal_entries.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->when($fiscalYearId !== null, fn ($query) => $query->where('journal_entries.fiscal_year_id', $fiscalYearId))
            ->where('chart_of_accounts.requires_analytic', true)
            ->orderBy('journal_entry_lines.id')
            ->get([
                'journal_entry_lines.id as line_id',
                'chart_of_accounts.code as account_code',
                'chart_of_accounts.account_class',
            ]);

        /** @var array<int, list<int>> $allocatedAxisIdsByLine */
        $allocatedAxisIdsByLine = [];

        $allocationRows = DB::table('journal_entry_line_analytics')
            ->whereIn('journal_entry_line_id', $lines->pluck('line_id'))
            ->get(['journal_entry_line_id', 'analytic_axis_id']);

        foreach ($allocationRows as $row) {
            $allocatedAxisIdsByLine[(int) $row->journal_entry_line_id][] = (int) $row->analytic_axis_id;
        }

        foreach ($lines as $line) {
            $lineId = (int) $line->line_id;
            $accountClass = (int) $line->account_class;
            $allocated = $allocatedAxisIdsByLine[$lineId] ?? [];

            foreach ($mandatoryAxes as $axis) {
                if (! $axis->appliesToClass($accountClass)) {
                    continue;
                }

                if (! in_array((int) $axis->getKey(), $allocated, true)) {
                    $violations[] = [
                        'invariant' => 'AN-3',
                        'journal_entry_line_id' => $lineId,
                        'analytic_axis_id' => (int) $axis->getKey(),
                        'detail' => sprintf(
                            'Line on account %s (requires_analytic, class %d) has no splits on mandatory axis %s.',
                            (string) $line->account_code,
                            $accountClass,
                            $axis->code,
                        ),
                    ];
                }
            }
        }

        return $violations;
    }
}

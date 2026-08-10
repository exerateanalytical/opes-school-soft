<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\PhasingProfile;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLine;
use App\Modules\Accounting\Models\BudgetPhasing;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §16 - spread a budget line's annual figure
 * across the accounting periods of its fiscal year.
 *
 * B-1 ("Σ BudgetPhasing.amount = BudgetLine.annual_amount per line") is the
 * point of this Action, and it is why the whole phasing set for a line is
 * DELETED and rewritten inside one transaction rather than upserted period by
 * period: a half-written set sums to something that is not the annual figure,
 * and the over-budget check would read it.
 *
 * `Money::allocate` does the arithmetic, so the remainder is distributed and
 * the set sums back exactly - twelve independent roundings of annual/12 do
 * not. Allocation is done on the ABSOLUTE amount and the sign reapplied,
 * because a budget line is legitimately negative (a credit-normal expense
 * account such as `6033` stock variation) and ratio allocation of a negative
 * total is not what the allocator is specified for.
 *
 * The grid comes from `accounting_periods`, not from a hardcoded twelve: an
 * irregular first exercice has fewer periods (§5.1/§6), and the phasing must
 * align with the periods that actually exist or Budget-vs-Actual joins to
 * nothing.
 */
final class ApplyBudgetPhasing
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array<string, int>|null  $manualAmounts  'YYYY-MM-01' => amount.
     *                                                  Required for Manual,
     *                                                  ignored otherwise.
     * @return list<BudgetPhasing>
     */
    public function handle(
        int $budgetLineId,
        PhasingProfile $profile,
        ?array $manualAmounts,
        Actor $actor,
    ): array {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($budgetLineId, $profile, $manualAmounts, $actor): array {
            /** @var BudgetLine $line */
            $line = BudgetLine::query()->whereKey($budgetLineId)->lockForUpdate()->firstOrFail();

            /** @var Budget $budget */
            $budget = Budget::query()->whereKey($line->budget_id)->lockForUpdate()->firstOrFail();

            SaveBudget::assertDraft($budget);

            $months = $this->periodMonths((int) $budget->fiscal_year_id);

            if ($months === []) {
                throw new DomainException(sprintf(
                    'Fiscal year %d has no accounting periods, so a budget line cannot be phased against it.',
                    (int) $budget->fiscal_year_id,
                ));
            }

            $amounts = $profile === PhasingProfile::Manual
                ? $this->manual($months, $manualAmounts, (int) $line->annual_amount)
                : $this->byWeight($months, $profile, (int) $line->annual_amount);

            $line->phasings()->delete();

            /** @var list<BudgetPhasing> $written */
            $written = [];

            foreach ($months as $month) {
                $written[] = BudgetPhasing::query()->create([
                    'budget_line_id' => $line->getKey(),
                    'period_month' => $month,
                    'amount' => $amounts[$month],
                ]);
            }

            // B-1, asserted rather than assumed - the whole set is in hand
            // and still inside the transaction, so a mistake rolls back.
            $total = array_sum($amounts);

            if ($total !== (int) $line->annual_amount) {
                throw new DomainException(sprintf(
                    'Phasing does not sum to the annual amount (§16 B-1): %d phased vs %d annual on budget line %d.',
                    $total,
                    (int) $line->annual_amount,
                    (int) $line->getKey(),
                ));
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: BudgetLine::class,
                auditableId: (int) $line->getKey(),
                after: ['phasing_profile' => $profile->value, 'phased_total' => $total],
                actor: $actor,
            );

            return $written;
        });
    }

    /**
     * @return list<string>
     */
    private function periodMonths(int $fiscalYearId): array
    {
        /** @var list<string> $months */
        $months = AccountingPeriod::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->orderBy('period_month')
            ->pluck('period_month')
            ->map(static fn (mixed $month): string => Carbon::parse((string) $month)->startOfMonth()->toDateString())
            ->values()
            ->all();

        return $months;
    }

    /**
     * @param  list<string>  $months
     * @return array<string, int>
     */
    private function byWeight(array $months, PhasingProfile $profile, int $annualAmount): array
    {
        /** @var list<int> $ratios */
        $ratios = array_map(
            static fn (string $month): int => $profile->weightForMonth((int) Carbon::parse($month)->format('n')),
            $months,
        );

        $sign = $annualAmount < 0 ? -1 : 1;
        $parts = Money::of(abs($annualAmount))->allocate($ratios);

        /** @var array<string, int> $amounts */
        $amounts = [];

        foreach ($months as $index => $month) {
            $amounts[$month] = $sign * $parts[$index]->amount();
        }

        return $amounts;
    }

    /**
     * @param  list<string>  $months
     * @param  array<string, int>|null  $manualAmounts
     * @return array<string, int>
     */
    private function manual(array $months, ?array $manualAmounts, int $annualAmount): array
    {
        if ($manualAmounts === null) {
            throw new DomainException('Manual phasing needs per-period amounts.');
        }

        /** @var array<string, int> $amounts */
        $amounts = [];

        foreach ($months as $month) {
            $amounts[$month] = (int) ($manualAmounts[$month] ?? 0);
        }

        $unknown = array_diff(array_keys($manualAmounts), $months);

        if ($unknown !== []) {
            throw new DomainException(sprintf(
                'Manual phasing names periods that are not in this fiscal year: %s.',
                implode(', ', $unknown),
            ));
        }

        $total = array_sum($amounts);

        if ($total !== $annualAmount) {
            throw new DomainException(sprintf(
                'Manual phasing sums to %d but the line\'s annual amount is %d (§16 B-1). Adjust a period or the annual figure.',
                $total,
                $annualAmount,
            ));
        }

        return $amounts;
    }
}

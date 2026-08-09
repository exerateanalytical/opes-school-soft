<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Tax\Domain\DeclarationStatus;
use App\Modules\Tax\Domain\DueRule;
use App\Modules\Tax\Models\FiscalIdentity;
use App\Modules\Tax\Models\TaxDeclaration;
use App\Modules\Tax\Models\TaxObligation;
use Illuminate\Support\Carbon;

/**
 * docs/specs/03-tax-procurement.md §7.4 - the compliance calendar QUERY
 * action: from the `tax_obligations` reference data (declarative
 * `due_rule` expressions - data, not a hardcoded match) it derives the
 * upcoming obligations with their T−15 / T−7 / T−1 / due-today alert
 * levels, escalating to OVERDUE while the period's declaration is
 * unfiled.
 *
 * `applies_when` predicates ({"tax_regime": "reel"},
 * {"is_tva_registered": true}) are evaluated against the confirmed
 * fiscal identity; while the identity is unconfirmed a conditional
 * obligation cannot be evaluated and is skipped WITH a visible note
 * (never silently assumed to apply or not).
 *
 * The system NEVER files anything (§7.4): every row is a date and a
 * status, and the wording on the screens must never imply automated
 * filing. Due dates are the statutory dates with NO weekend/holiday
 * roll-forward (NEEDS VERIFICATION, §7.6).
 *
 * @phpstan-type CalendarItem array{
 *     obligation_id: int,
 *     declaration_type: string,
 *     declaration_name: string,
 *     period_year: int,
 *     period_month: int,
 *     due_date: string,
 *     alert_level: string,
 *     is_filed: bool,
 *     penalty_note: string|null,
 * }
 */
final class ComplianceCalendar
{
    /** How many future monthly occurrences each obligation contributes. */
    private const HORIZON_MONTHS = 3;

    /**
     * @return array{items: list<CalendarItem>, notes: list<string>}
     */
    public function handle(?string $today = null): array
    {
        $now = Carbon::parse($today ?? \App\Support\Clock\BusinessDate::today())->startOfDay();
        $identity = FiscalIdentity::current();
        $confirmed = $identity !== null && $identity->isConfirmed();

        $items = [];
        $notes = [];

        /** @var list<TaxObligation> $obligations */
        $obligations = TaxObligation::query()
            ->with('declarationType')
            ->where('is_archived', false)
            ->get()
            ->all();

        if ($obligations === []) {
            $notes[] = 'No tax obligations are configured yet - the compliance calendar ships empty and is completed with your accountant (03-tax-procurement §7.4).';
        }

        foreach ($obligations as $obligation) {
            $type = $obligation->declarationType()->first();

            if ($type === null || $type->is_archived) {
                continue;
            }

            if ($obligation->applies_when !== null && $obligation->applies_when !== []) {
                if (! $confirmed) {
                    $notes[] = sprintf(
                        '%s: cannot decide whether this obligation applies - the fiscal identity is not confirmed (predicate %s).',
                        $type->code,
                        (string) json_encode($obligation->applies_when),
                    );

                    continue;
                }

                if (! $this->applies($obligation->applies_when, $identity)) {
                    continue;
                }
            }

            $rule = DueRule::parse($obligation->due_rule);

            if ($rule->isCentreDependent() && ($identity === null || $identity->tax_centre_type === null)) {
                $notes[] = sprintf(
                    '%s: the due date depends on the tax centre type, which is not confirmed yet (03-tax-procurement §2.1).',
                    $type->code,
                );

                continue;
            }

            foreach ($this->periodsFor($obligation->frequency, $now) as [$periodYear, $periodMonth]) {
                $dueDate = $rule->dueDateFor($periodYear, $periodMonth, $identity?->tax_centre_type);
                $isFiled = $this->isFiled($type->code, $periodYear, $periodMonth);

                // Past-due AND filed: nothing left to surface.
                if ($isFiled && $dueDate->lessThan($now)) {
                    continue;
                }

                $items[] = [
                    'obligation_id' => (int) $obligation->id,
                    'declaration_type' => $type->code,
                    'declaration_name' => $type->name,
                    'period_year' => $periodYear,
                    'period_month' => $periodMonth,
                    'due_date' => $dueDate->toDateString(),
                    'alert_level' => $this->alertLevel($now, $dueDate, $isFiled),
                    'is_filed' => $isFiled,
                    'penalty_note' => $obligation->penalty_note,
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => [$a['due_date'], $a['declaration_type']] <=> [$b['due_date'], $b['declaration_type']]);

        return ['items' => $items, 'notes' => $notes];
    }

    /**
     * The periods whose due dates are worth surfacing today: the previous
     * occurrences (they may be overdue) and the next HORIZON_MONTHS.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function periodsFor(string $frequency, Carbon $now): array
    {
        if ($frequency === 'annual') {
            // Period year Y is due during Y+1, so the years in play are
            // last year (typically upcoming/overdue) and the one before.
            return [
                [(int) $now->format('Y') - 2, 0],
                [(int) $now->format('Y') - 1, 0],
            ];
        }

        if ($frequency === 'quarterly') {
            $periods = [];
            $cursor = $now->copy()->startOfMonth()->subMonths(4);

            for ($i = 0; $i < self::HORIZON_MONTHS + 4; $i++) {
                if (((int) $cursor->format('n')) % 3 === 0) {
                    $periods[] = [(int) $cursor->format('Y'), (int) $cursor->format('n')];
                }
                $cursor = $cursor->addMonthNoOverflow();
            }

            return $periods;
        }

        // Monthly: the two previous periods (overdue candidates) plus the
        // horizon ahead.
        $periods = [];
        $cursor = $now->copy()->startOfMonth()->subMonths(2);

        for ($i = 0; $i < self::HORIZON_MONTHS + 2; $i++) {
            $periods[] = [(int) $cursor->format('Y'), (int) $cursor->format('n')];
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $periods;
    }

    /**
     * @param  array<string, mixed>  $predicate
     */
    private function applies(array $predicate, ?FiscalIdentity $identity): bool
    {
        if ($identity === null) {
            return false;
        }

        foreach ($predicate as $key => $expected) {
            $actual = match ($key) {
                'tax_regime' => $identity->tax_regime?->value,
                'is_tva_registered' => $identity->is_tva_registered,
                'tax_centre_type' => $identity->tax_centre_type?->value,
                'legal_form' => $identity->legal_form?->value,
                default => null,
            };

            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function isFiled(string $typeCode, int $periodYear, int $periodMonth): bool
    {
        return TaxDeclaration::query()
            ->where('declaration_type', $typeCode)
            ->where('period_year', $periodYear)
            ->where('period_month', $periodMonth)
            ->whereIn('status', [
                DeclarationStatus::Filed->value,
                DeclarationStatus::Paid->value,
                DeclarationStatus::Amended->value,
            ])
            ->exists();
    }

    /** §7.4: alerts at T−15, T−7, T−1 and on the due date; then overdue. */
    private function alertLevel(Carbon $now, Carbon $dueDate, bool $isFiled): string
    {
        if ($isFiled) {
            return 'filed';
        }

        if ($dueDate->lessThan($now)) {
            return 'overdue';
        }

        if ($dueDate->equalTo($now)) {
            return 'due_today';
        }

        $days = (int) $now->diffInDays($dueDate);

        return match (true) {
            $days <= 1 => 't-1',
            $days <= 7 => 't-7',
            $days <= 15 => 't-15',
            default => 'upcoming',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

use DomainException;
use Illuminate\Support\Carbon;

/**
 * docs/specs/03-tax-procurement.md §7.4/§7.6 - the small declarative
 * due-date expression carried by `tax_obligations.due_rule`. Data, not a
 * hardcoded match. Two forms are understood:
 *
 * - `day_of_next_month(15)` - a monthly obligation for period (Y, M) is
 *   due on day 15 of the following month.
 * - `tax_centre_dependent(DGE=03-15,CIME=04-15,other=05-15)` - an annual
 *   obligation for period year Y is due at MM-DD of Y+1, the MM-DD picked
 *   by the school's tax_centre_type (§7.6: the CDI/CSI arm is `other`).
 *
 * Weekend/holiday roll-forward is NEEDS VERIFICATION: the statutory date
 * is returned with NO adjustment (§7.6) - the screens carry the note.
 */
final readonly class DueRule
{
    private function __construct(
        private string $kind,
        private int $dayOfNextMonth,
        /** @var array<string, string> centre => 'MM-DD' */
        private array $centreDates,
    ) {}

    public static function parse(string $expression): self
    {
        if (preg_match('/^day_of_next_month\((\d{1,2})\)$/', $expression, $m) === 1) {
            $day = (int) $m[1];

            if ($day < 1 || $day > 28) {
                throw new DomainException("due_rule day {$day} is outside 1-28; every month must contain it.");
            }

            return new self('day_of_next_month', $day, []);
        }

        if (preg_match('/^tax_centre_dependent\(([A-Za-z0-9=,\- ]+)\)$/', $expression, $m) === 1) {
            $dates = [];

            foreach (explode(',', $m[1]) as $pair) {
                $parts = explode('=', trim($pair));

                if (count($parts) !== 2 || preg_match('/^\d{2}-\d{2}$/', $parts[1]) !== 1) {
                    throw new DomainException("Unparseable tax_centre_dependent arm '{$pair}' in due_rule.");
                }

                $dates[strtoupper($parts[0]) === 'OTHER' ? 'other' : strtoupper($parts[0])] = $parts[1];
            }

            if (! isset($dates['other'])) {
                throw new DomainException("A tax_centre_dependent due_rule needs an 'other' arm.");
            }

            return new self('tax_centre_dependent', 0, $dates);
        }

        throw new DomainException(
            "Unknown due_rule expression '{$expression}'. Supported: day_of_next_month(D), tax_centre_dependent(CENTRE=MM-DD,…,other=MM-DD)."
        );
    }

    /**
     * The statutory due date for a period. Monthly rules need the period
     * (year, month); centre-dependent annual rules need the period year
     * and the school's tax centre type.
     */
    public function dueDateFor(int $periodYear, int $periodMonth, ?TaxCentreType $centre): Carbon
    {
        if ($this->kind === 'day_of_next_month') {
            if ($periodMonth < 1 || $periodMonth > 12) {
                throw new DomainException('A monthly due_rule needs a period month between 1 and 12.');
            }

            return Carbon::parse(sprintf('%04d-%02d-01', $periodYear, $periodMonth))
                ->addMonthNoOverflow()
                ->day($this->dayOfNextMonth)
                ->startOfDay();
        }

        if ($centre === null) {
            throw new DomainException(
                'This obligation\'s due date depends on the tax centre type; confirm the fiscal identity first (03-tax-procurement §2.1 - tax_centre_type is load-bearing).'
            );
        }

        $monthDay = $this->centreDates[$centre->value] ?? $this->centreDates['other'];

        return Carbon::parse(sprintf('%04d-%s', $periodYear + 1, $monthDay))->startOfDay();
    }

    public function isCentreDependent(): bool
    {
        return $this->kind === 'tax_centre_dependent';
    }
}

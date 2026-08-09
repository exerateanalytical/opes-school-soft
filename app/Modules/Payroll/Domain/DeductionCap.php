<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * The quotite cessible/saisissable cap (docs/specs/05-hr-payroll.md 5.7).
 *
 * The cap TABLE ships empty (2.4): while unconfigured, preflight check 11
 * refuses any run carrying a cappable deduction, so this class only ever
 * executes against a cap the school configured. Statutory deductions are
 * exempt and never pass through here.
 *
 * The excess is NEVER discarded: it comes back as the carry-forward list,
 * which the calculate Action persists as DeductionCarryForward rows and
 * re-presents next month - silently dropping it would make a loan
 * un-repayable.
 */
final class DeductionCap
{
    /**
     * Applies deductions in the given order until the cap is exhausted.
     *
     * @param  list<array{code: string, amount: int}>  $deductions
     * @return array{applied: list<array{code: string, amount: int}>, carried: list<array{code: string, amount: int}>}
     */
    public static function apply(int $capAmount, array $deductions): array
    {
        $remaining = $capAmount;
        $applied = [];
        $carried = [];

        foreach ($deductions as $deduction) {
            // min(cap headroom, requested), never below zero - expressed
            // through Rational so no numeric literal appears here (4.3).
            $headroom = Rational::ofInt($remaining)->max(Rational::zero())->roundHalfUp();
            $take = min($headroom, $deduction['amount']);

            $applied[] = ['code' => $deduction['code'], 'amount' => $take];

            if ($take !== $deduction['amount']) {
                $carried[] = [
                    'code' => $deduction['code'],
                    'amount' => $deduction['amount'] - $take,
                ];
            }

            $remaining -= $take;
        }

        return ['applied' => $applied, 'carried' => $carried];
    }

    private function __construct()
    {
    }
}

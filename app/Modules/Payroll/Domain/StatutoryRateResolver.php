<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Modules\Payroll\Models\StatutoryRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Statutory rate resolution (docs/specs/05-hr-payroll.md 4.3), stated
 * exactly:
 *
 *   The date that drives selection is the payroll period END date, not the
 *   run date. A March run executed on 4 April uses rates effective 31 March.
 *
 * Unverified rows are ABSENT to this class (4.2 rule 9); zero matches and
 * two-plus matches are both fatal, with no fallback path. The selection
 * predicate is a pure filter over candidate rows (`selectFrom`), so the
 * 10-year daily sweep property test exercises the REAL selection logic
 * without a query per day.
 *
 * No numeric literals live here (4.3 architecture rule): counting is done
 * by shift-then-emptiness, dates and bands compare value-to-value.
 */
final class StatutoryRateResolver
{
    public function resolve(
        string $code,
        Carbon $periodEnd,
        ?string $riskClass = null,
        ?CnpsRegime $cnpsRegime = null,
        ?int $bandValue = null,
    ): StatutoryRate {
        /** @var Collection<int, StatutoryRate> $candidates */
        $candidates = StatutoryRate::query()
            ->where('code', $code)
            ->where('is_verified', true)
            ->get();

        return $this->selectFrom($candidates, $code, $periodEnd, $riskClass, $cnpsRegime, $bandValue);
    }

    /**
     * The pure selection predicate over already-loaded candidate rows.
     * Verified-only is re-asserted here so a caller handing in a mixed
     * collection cannot accidentally widen the engine's view.
     *
     * @param  Collection<int, StatutoryRate>  $candidates
     */
    public function selectFrom(
        Collection $candidates,
        string $code,
        Carbon $periodEnd,
        ?string $riskClass = null,
        ?CnpsRegime $cnpsRegime = null,
        ?int $bandValue = null,
    ): StatutoryRate {
        $matches = $candidates
            ->filter(fn (StatutoryRate $rate): bool => $this->matches(
                $rate, $code, $periodEnd, $riskClass, $cnpsRegime, $bandValue,
            ))
            ->values();

        $first = $matches->shift();

        if ($first === null) {
            throw StatutoryRateUnresolved::for($code, $periodEnd->toDateString());
        }

        if ($matches->isNotEmpty()) {
            throw StatutoryRateAmbiguous::for($code, $periodEnd->toDateString());
        }

        return $first;
    }

    private function matches(
        StatutoryRate $rate,
        string $code,
        Carbon $periodEnd,
        ?string $riskClass,
        ?CnpsRegime $cnpsRegime,
        ?int $bandValue,
    ): bool {
        if ($rate->code !== $code || ! $rate->is_verified) {
            return false;
        }

        // effective_from inclusive, effective_to EXCLUSIVE.
        if ($rate->effective_from->gt($periodEnd)) {
            return false;
        }

        if ($rate->effective_to !== null && ! $rate->effective_to->gt($periodEnd)) {
            return false;
        }

        // risk_class / cnps_regime: "matches or IS NULL" - a NULL on the
        // row means the row does not discriminate on that axis.
        if ($rate->risk_class !== null && $rate->risk_class !== $riskClass) {
            return false;
        }

        if ($rate->cnps_regime !== null && $rate->cnps_regime !== $cnpsRegime) {
            return false;
        }

        // Flat bands select by the basis value: band_from inclusive,
        // band_to EXCLUSIVE, NULL = open top band. Without a basis value a
        // band row cannot match - the caller's omission surfaces as
        // StatutoryRateUnresolved, never as a guessed band.
        if ($rate->shape === RateShape::FlatBand) {
            if ($bandValue === null || $rate->band_from === null) {
                return false;
            }

            if ($rate->band_from > $bandValue) {
                return false;
            }

            if ($rate->band_to !== null && $rate->band_to <= $bandValue) {
                return false;
            }
        }

        return true;
    }
}

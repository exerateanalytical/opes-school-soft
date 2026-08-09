<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Payroll\Domain\CnpsRegime;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Domain\StatutoryRateCode;
use App\Modules\Payroll\Models\StatutoryRate;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY way an amount ever lands on a statutory rate row
 * (docs/specs/05-hr-payroll.md 4.2, 4.4, 9.2). The bursar transcribes the
 * value from the school's own CNPS notification letter or DGI notice -
 * `source_citation` is mandatory, `is_verified` is set here and nowhere
 * else, and nothing is ever pre-filled or suggested.
 *
 * An unverified, unlocked SHELL matching the row key is completed in place
 * (that is what the shells shipped for); any other configuration - a new
 * band row, a bracket row, a dated successor for an unlocked row - inserts.
 * Locked rows never pass through here: CloseAndSupersedeRate is their only
 * mutation path.
 *
 * Overlap check (4.4): per (code, risk_class, cnps_regime, band_from) on
 * [effective_from, effective_to), among VERIFIED rows - resolution must
 * find exactly one row, and this Action is where two-rows-cover-one-day is
 * stopped before it can become StatutoryRateAmbiguous at run time.
 */
final class ConfigureStatutoryRate
{
    public function handle(
        StatutoryRateCode $code,
        string $effectiveFrom,
        string $sourceCitation,
        ?int $employeeRateBp = null,
        ?int $employerRateBp = null,
        ?int $flatAmount = null,
        ?int $ceilingAmount = null,
        ?int $floorAmount = null,
        ?int $bandFrom = null,
        ?int $bandTo = null,
        ?string $riskClass = null,
        ?CnpsRegime $cnpsRegime = null,
        ?string $effectiveTo = null,
        ?int $sourceDocumentId = null,
        ?Actor $actor = null,
    ): StatutoryRate {
        Gate::authorize(PayrollPermission::CONFIGURE);

        $this->assertShapeOfAmounts($code, $employeeRateBp, $employerRateBp, $flatAmount);

        if (trim($sourceCitation) === '') {
            throw ValidationException::withMessages([
                'source_citation' => 'A statutory rate must cite its source document - an uncited rate cannot be defended at audit.',
            ]);
        }

        $actor ??= auth()->user()?->toAuditActor() ?? Actor::system();

        return DB::transaction(function () use (
            $code, $effectiveFrom, $effectiveTo, $sourceCitation, $employeeRateBp, $employerRateBp,
            $flatAmount, $ceilingAmount, $floorAmount, $bandFrom, $bandTo, $riskClass, $cnpsRegime,
            $sourceDocumentId, $actor,
        ): StatutoryRate {
            $this->assertNoVerifiedOverlap($code, $riskClass, $cnpsRegime, $bandFrom, $effectiveFrom, $effectiveTo);

            $values = [
                'employee_rate_bp' => $employeeRateBp,
                'employer_rate_bp' => $employerRateBp,
                'flat_amount' => $flatAmount,
                'ceiling_amount' => $ceilingAmount,
                'floor_amount' => $floorAmount,
                'band_from' => $bandFrom,
                'band_to' => $bandTo,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'source_citation' => $sourceCitation,
                'source_document_id' => $sourceDocumentId,
                'is_verified' => true,
                'verified_by' => $actor->id,
                'verified_at' => Carbon::now(),
            ];

            // Complete the shipped shell in place when its key matches;
            // otherwise this is a NEW row (band, bracket or successor).
            $shell = StatutoryRate::query()
                ->where('code', $code->value)
                ->where('is_verified', false)
                ->where('locked', false)
                ->where('risk_class', $riskClass)
                ->where('cnps_regime', $cnpsRegime?->value)
                ->whereNull('band_from')
                ->whereNull('employee_rate_bp')
                ->whereNull('employer_rate_bp')
                ->whereNull('flat_amount')
                ->lockForUpdate()
                ->first();

            if ($shell !== null && $bandFrom === null) {
                $shell->fill($values);
                $shell->save();
                $rate = $shell;
            } else {
                $template = $shell ?? StatutoryRate::query()
                    ->where('code', $code->value)
                    ->orderBy('id')
                    ->first();

                $rate = StatutoryRate::query()->create(array_merge([
                    'code' => $code->value,
                    'label' => $template->label ?? $code->value,
                    'label_fr' => $template?->label_fr,
                    'shape' => $template?->shape->value ?? 'percentage',
                    'basis' => $template?->basis->value ?? 'gross',
                    'bracket_basis' => $template?->bracket_basis?->value,
                    'risk_class' => $riskClass,
                    'cnps_regime' => $cnpsRegime?->value,
                    'locked' => false,
                ], $values));
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::SettingChanged,
                module: 'Payroll',
                auditableType: StatutoryRate::class,
                auditableId: (int) $rate->getKey(),
                after: [
                    'code' => $code->value,
                    'risk_class' => $riskClass,
                    'cnps_regime' => $cnpsRegime?->value,
                    'employee_rate_bp' => $employeeRateBp,
                    'employer_rate_bp' => $employerRateBp,
                    'flat_amount' => $flatAmount,
                    'ceiling_amount' => $ceilingAmount,
                    'band_from' => $bandFrom,
                    'band_to' => $bandTo,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $effectiveTo,
                    'source_citation' => $sourceCitation,
                ],
                actor: $actor,
            );

            return $rate;
        });
    }

    /**
     * Mirrors the DB CHECKs with actionable messages: exactly one of
     * rates/flat amount, employer-only codes carry no employee share, RP is
     * never capped and always classed, PF is always per regime.
     */
    private function assertShapeOfAmounts(
        StatutoryRateCode $code,
        ?int $employeeRateBp,
        ?int $employerRateBp,
        ?int $flatAmount,
    ): void {
        $hasRates = $employeeRateBp !== null || $employerRateBp !== null;

        if ($hasRates === ($flatAmount !== null)) {
            throw ValidationException::withMessages([
                'amounts' => 'A rate row carries either percentage rates or a flat band amount - exactly one of the two.',
            ]);
        }

        if ($code->isEmployerOnly() && $employeeRateBp !== null) {
            throw ValidationException::withMessages([
                'employee_rate_bp' => "{$code->value} is employer-borne only; an employee share is a defect (05-hr-payroll 4.2).",
            ]);
        }
    }

    private function assertNoVerifiedOverlap(
        StatutoryRateCode $code,
        ?string $riskClass,
        ?CnpsRegime $cnpsRegime,
        ?int $bandFrom,
        string $effectiveFrom,
        ?string $effectiveTo,
    ): void {
        $overlapping = StatutoryRate::query()
            ->where('code', $code->value)
            ->where('is_verified', true)
            ->where('risk_class', $riskClass)
            ->where('cnps_regime', $cnpsRegime?->value)
            ->where('band_from', $bandFrom)
            ->where(function ($query) use ($effectiveTo): void {
                if ($effectiveTo !== null) {
                    $query->where('effective_from', '<', $effectiveTo);
                }
            })
            ->where(function ($query) use ($effectiveFrom): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $effectiveFrom);
            })
            ->lockForUpdate()
            ->exists();

        if ($overlapping) {
            throw ValidationException::withMessages([
                'effective_from' => 'A verified rate already covers part of this period for the same key; close it first (effective_to is exclusive).',
            ]);
        }
    }
}

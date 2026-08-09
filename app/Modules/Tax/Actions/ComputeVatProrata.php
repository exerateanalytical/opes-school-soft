<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\ProrataBasis;
use App\Modules\Tax\Domain\ProrataRounding;
use App\Modules\Tax\Models\TaxSettings;
use App\Modules\Tax\Models\VatProrata;
use App\Support\Audit\Actor;
use App\Support\Rate\Rate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §5.4 - compute (or manually enter) the
 * prorata de déduction for a fiscal year and basis. Turnover figures are
 * supplied by the caller: the provisional prorata is normally N−1's
 * definitive (first year: entered manually with a reason), and the
 * definitive one comes from the year's actual turnover working paper.
 *
 * The ROUNDING RULE is the accountant's configured TaxSettings.prorata_
 * rounding (NEEDS VERIFICATION whether the CGI mandates rounding up to the
 * whole percent) - unset means REFUSE, never assume:
 * - exact_bp:            half-up to the basis point (0.01%);
 *   11.7241…% → 11.72% → 11 720 in Rate scale, which is what makes the
 *   §5.4 worked example reproduce to the franc.
 * - up_to_whole_percent: ceiling to the whole percent; → 12% → 12 000.
 *
 * The result is UNUSABLE until ConfirmVatProrata: ComputeLineTax refuses
 * an unconfirmed prorata.
 */
final class ComputeVatProrata
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(
        int $fiscalYearId,
        ProrataBasis $basis,
        int $numeratorAmount,
        int $denominatorAmount,
        string $source = 'computed',
        ?string $manualReason = null,
        ?Actor $actor = null,
    ): VatProrata {
        Gate::authorize(self::PERMISSION);

        if ($actor === null) {
            throw new DomainException('Computing a prorata is an audited act; it needs an actor.');
        }

        if (! in_array($source, ['computed', 'manual'], true)) {
            throw new DomainException('source must be computed or manual.');
        }

        if ($source === 'manual' && ($manualReason === null || trim($manualReason) === '')) {
            throw new DomainException('A manual prorata requires a reason (03-tax-procurement §5.4).');
        }

        if ($denominatorAmount <= 0) {
            throw new DomainException('The prorata denominator (total turnover HT) must be positive.');
        }

        if ($numeratorAmount < 0 || $numeratorAmount > $denominatorAmount) {
            throw new DomainException('The prorata numerator must lie between 0 and the denominator.');
        }

        $rateBp = $this->roundedRateBp($numeratorAmount, $denominatorAmount);

        return DB::transaction(function () use ($fiscalYearId, $basis, $numeratorAmount, $denominatorAmount, $source, $manualReason, $rateBp, $actor): VatProrata {
            $exists = DB::table('fiscal_years')->where('id', $fiscalYearId)->exists();

            if (! $exists) {
                throw new DomainException('Unknown fiscal year.');
            }

            /** @var VatProrata|null $prorata */
            $prorata = VatProrata::query()
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('basis', $basis->value)
                ->lockForUpdate()
                ->first();

            if ($prorata !== null && $prorata->isConfirmed()) {
                throw new DomainException(
                    'This prorata is already confirmed; deductions were taken against it. '
                    .'The provisional→definitive difference is posted by the year-end regularisation, '
                    .'never by recomputing in place (03-tax-procurement §5.4.3).'
                );
            }

            $attributes = [
                'fiscal_year_id' => $fiscalYearId,
                'basis' => $basis->value,
                'rate_bp' => $rateBp,
                'numerator_amount' => $numeratorAmount,
                'denominator_amount' => $denominatorAmount,
                'computed_at' => now(),
                'computed_by' => $actor->id,
                'source' => $source,
                'manual_reason' => $manualReason,
            ];

            if ($prorata === null) {
                $prorata = VatProrata::query()->create($attributes);
                $auditAction = AuditAction::Created;
                $before = null;
            } else {
                $before = $prorata->only(array_keys($attributes));
                $prorata->fill($attributes)->save();
                $auditAction = AuditAction::Updated;
            }

            $this->audit->handle(
                action: $auditAction,
                module: 'Tax',
                auditableType: VatProrata::class,
                auditableId: (int) $prorata->getKey(),
                before: $before,
                after: $attributes,
                actor: $actor,
            );

            return $prorata->refresh();
        });
    }

    /**
     * Integer arithmetic throughout - the same no-floats discipline as
     * Money/Rate. Turnovers are bounded far below the point where
     * numerator × 10 000 could overflow a 64-bit int.
     */
    private function roundedRateBp(int $numerator, int $denominator): int
    {
        $rounding = TaxSettings::current()?->prorata_rounding;

        if ($rounding === null) {
            throw new DomainException(
                'The prorata rounding rule is not configured (TaxSettings.prorata_rounding ships unset - '
                .'whether the CGI rounds up to the whole percent is unverified). '
                .'Decide it with your accountant before computing a prorata (03-tax-procurement §5.4).'
            );
        }

        $rateBp = match ($rounding) {
            // Half-up to 0.01% (a per-10 000 point), then ×10 into Rate
            // scale: 34M/290M → 1 172.41 → 1 172 → 11 720.
            ProrataRounding::ExactBp => intdiv(2 * $numerator * 10_000 + $denominator, 2 * $denominator) * 10,
            // Ceiling to the whole percent: → 11.72…% → 12% → 12 000.
            ProrataRounding::UpToWholePercent => intdiv($numerator * 100 + $denominator - 1, $denominator) * 1_000,
        };

        // Round-trip through Rate so an out-of-range value fails here, not
        // when a deduction is computed years later.
        return Rate::ofBasisPoints($rateBp)->basisPoints();
    }
}

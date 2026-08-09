<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Models\StatutoryRate;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * "Changing a rate" is ALWAYS this operation (docs/specs/05-hr-payroll.md
 * 4.4, fixing H7): close the current row - `effective_to` set from NULL,
 * exclusive - and insert a successor carrying the new amounts from the same
 * date. There is no edit form for a locked row anywhere in the product, and
 * the BEFORE UPDATE trigger rejects every other shape of write; editing
 * `effective_from` in place would silently recompute a January payslip
 * under a March rate.
 *
 * The closure date must not cut under history: when payroll run tables
 * exist, the latest APPROVED period end referencing the row bounds it from
 * below (the trigger enforces the write's shape; this Action enforces its
 * meaning).
 */
final class CloseAndSupersedeRate
{
    public function handle(
        int $rateId,
        string $supersedeOn,
        string $sourceCitation,
        ?int $employeeRateBp = null,
        ?int $employerRateBp = null,
        ?int $flatAmount = null,
        ?int $ceilingAmount = null,
        ?int $floorAmount = null,
        ?int $sourceDocumentId = null,
        ?Actor $actor = null,
    ): StatutoryRate {
        Gate::authorize(PayrollPermission::CONFIGURE);

        if (trim($sourceCitation) === '') {
            throw ValidationException::withMessages([
                'source_citation' => 'The successor rate must cite its source document.',
            ]);
        }

        $actor ??= auth()->user()?->toAuditActor() ?? Actor::system();

        return DB::transaction(function () use (
            $rateId, $supersedeOn, $sourceCitation, $employeeRateBp, $employerRateBp,
            $flatAmount, $ceilingAmount, $floorAmount, $sourceDocumentId, $actor,
        ): StatutoryRate {
            /** @var StatutoryRate $current */
            $current = StatutoryRate::query()->lockForUpdate()->findOrFail($rateId);

            if (! $current->is_verified) {
                throw new DomainException(
                    'An unverified shell has no history to preserve - configure it directly instead of superseding.'
                );
            }

            if ($current->effective_to !== null) {
                throw new DomainException('This rate row is already closed; supersede the open row for its key.');
            }

            if ($current->effective_from->toDateString() >= $supersedeOn) {
                throw new DomainException('The supersede date must fall after the row became effective.');
            }

            // 4.4: effective_to may only close AT OR AFTER the latest period
            // end of an approved run that referenced this row. The run
            // tables belong to a later migration package; when absent there
            // is no history to protect yet.
            $latestReferenced = $this->latestReferencedPeriodEnd($rateId);

            if ($latestReferenced !== null && $supersedeOn < $latestReferenced) {
                throw new DomainException(
                    "An approved run for a period ending {$latestReferenced} references this rate;"
                    ." it cannot be closed before that date."
                );
            }

            // The ONE update a locked row permits: effective_to, NULL -> date.
            $current->effective_to = Carbon::parse($supersedeOn);
            $current->save();

            $hasRates = $employeeRateBp !== null || $employerRateBp !== null;

            if ($hasRates === ($flatAmount !== null)) {
                throw ValidationException::withMessages([
                    'amounts' => 'The successor carries either percentage rates or a flat band amount - exactly one of the two.',
                ]);
            }

            $successor = StatutoryRate::query()->create([
                'code' => $current->code,
                'label' => $current->label,
                'label_fr' => $current->label_fr,
                'shape' => $current->shape->value,
                'basis' => $current->basis->value,
                'bracket_basis' => $current->bracket_basis?->value,
                'employee_rate_bp' => $employeeRateBp,
                'employer_rate_bp' => $employerRateBp,
                'flat_amount' => $flatAmount,
                'ceiling_amount' => $ceilingAmount,
                'floor_amount' => $floorAmount,
                'band_from' => $current->band_from,
                'band_to' => $current->band_to,
                'risk_class' => $current->risk_class,
                'cnps_regime' => $current->cnps_regime?->value,
                'effective_from' => $supersedeOn,
                'effective_to' => null,
                'source_citation' => $sourceCitation,
                'source_document_id' => $sourceDocumentId,
                'is_verified' => true,
                'verified_by' => $actor->id,
                'verified_at' => Carbon::now(),
                'locked' => false,
            ]);

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::SettingChanged,
                module: 'Payroll',
                auditableType: StatutoryRate::class,
                auditableId: (int) $successor->getKey(),
                before: [
                    'closed_rate_id' => $rateId,
                    'employee_rate_bp' => $current->employee_rate_bp,
                    'employer_rate_bp' => $current->employer_rate_bp,
                    'flat_amount' => $current->flat_amount,
                    'ceiling_amount' => $current->ceiling_amount,
                ],
                after: [
                    'code' => $current->code,
                    'effective_from' => $supersedeOn,
                    'employee_rate_bp' => $employeeRateBp,
                    'employer_rate_bp' => $employerRateBp,
                    'flat_amount' => $flatAmount,
                    'ceiling_amount' => $ceilingAmount,
                    'source_citation' => $sourceCitation,
                ],
                actor: $actor,
            );

            return $successor;
        });
    }

    /**
     * Latest APPROVED-or-later period end referencing the rate, as Y-m-d,
     * or null when nothing references it (or the run tables do not exist
     * yet - they land with the run-engine package).
     */
    private function latestReferencedPeriodEnd(int $rateId): ?string
    {
        if (! Schema::hasTable('payroll_lines') || ! Schema::hasTable('payroll_items') || ! Schema::hasTable('payroll_runs')) {
            return null;
        }

        $latestMonth = DB::table('payroll_lines')
            ->join('payroll_items', 'payroll_items.id', '=', 'payroll_lines.payroll_item_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
            ->where('payroll_lines.statutory_rate_id', $rateId)
            ->whereIn('payroll_runs.status', ['approved', 'paid', 'closed'])
            ->max('payroll_runs.payroll_month');

        if ($latestMonth === null) {
            return null;
        }

        return Carbon::parse((string) $latestMonth)->endOfMonth()->toDateString();
    }
}

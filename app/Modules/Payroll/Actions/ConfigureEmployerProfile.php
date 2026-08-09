<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Payroll\Domain\CnpsRegime;
use App\Modules\Payroll\Domain\IrppMode;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Models\EmployerProfile;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The blocking first-run wizard step (docs/specs/05-hr-payroll.md 3.1, C2):
 * the school confirms - against its own CNPS notification letter - the
 * regime and risk class that determine every employer contribution it will
 * pay. Both confirmations are mandatory affirmative acts, recorded in the
 * audit log; nothing here is pre-filled or suggested.
 *
 * Effective-dating: at most one profile covers any date. Configuring a new
 * version CLOSES the open one at the new effective_from (a regime
 * reclassification applies FROM a date and must never rewrite prior
 * payslips); overlaps in any other shape are rejected.
 *
 * `proration_basis` and `ceiling_prorates_partial_month` may stay NULL -
 * they are a 2.4 decision, and preflight blocks partial-month runs until
 * the customer takes it. There is no default.
 */
final class ConfigureEmployerProfile
{
    public function handle(
        string $cnpsEmployerNumber,
        string $dipeNumber,
        string $niu,
        int $tdlCommuneId,
        CnpsRegime $cnpsRegime,
        string $rpRiskClass,
        int $cnpsNotificationDocumentId,
        string $cnpsNotificationReference,
        string $effectiveFrom,
        bool $regimeConfirmed,
        bool $riskClassConfirmed,
        ?string $dgiCentre = null,
        ?string $prorationBasis = null,
        ?bool $ceilingProratesPartialMonth = null,
        IrppMode $irppMode = IrppMode::YtdCumulative,
        ?Actor $actor = null,
    ): EmployerProfile {
        Gate::authorize(PayrollPermission::CONFIGURE);

        // 3.1: "Open your CNPS notification letter. Confirm the regime and
        // risk class printed on it." Unconfirmed values do not save - the
        // two columns drive every employer contribution (N2, H2).
        if (! $regimeConfirmed || ! $riskClassConfirmed) {
            throw ValidationException::withMessages([
                'confirmation' => 'Confirm the CNPS regime and risk class against the notification letter before saving.',
            ]);
        }

        $actor ??= auth()->user()?->toAuditActor() ?? Actor::system();

        return DB::transaction(function () use (
            $cnpsEmployerNumber, $dipeNumber, $niu, $tdlCommuneId, $cnpsRegime, $rpRiskClass,
            $cnpsNotificationDocumentId, $cnpsNotificationReference, $effectiveFrom,
            $dgiCentre, $prorationBasis, $ceilingProratesPartialMonth, $irppMode, $actor,
        ): EmployerProfile {
            // 3.1: the employer NIU mirrors the school's fiscal identity;
            // validated equal at save. Cross-module read via DB::table only.
            $fiscalNiu = DB::table('fiscal_identities')->orderByDesc('id')->value('niu');

            if (is_string($fiscalNiu) && $fiscalNiu !== '' && $fiscalNiu !== $niu) {
                throw ValidationException::withMessages([
                    'niu' => "The employer NIU must equal the school's fiscal NIU ({$fiscalNiu}).",
                ]);
            }

            // Serialise concurrent configuration attempts on the whole set.
            $existing = EmployerProfile::query()
                ->orderBy('effective_from')
                ->lockForUpdate()
                ->get();

            foreach ($existing as $profile) {
                if ($profile->effective_from->toDateString() >= $effectiveFrom) {
                    throw new DomainException(
                        'A profile already starts on or after this effective date; employer history is append-only.'
                    );
                }

                if ($profile->effective_to === null) {
                    // Close the open profile at the new effective date -
                    // exclusive end, so the old version covers up to the
                    // day before the new one takes over.
                    $profile->effective_to = $effectiveFrom;
                    $profile->save();
                } elseif ($profile->effective_to->toDateString() > $effectiveFrom) {
                    throw new DomainException(
                        'The new profile would overlap an existing dated version; at most one profile covers any date.'
                    );
                }
            }

            $profile = EmployerProfile::query()->create([
                'cnps_employer_number' => $cnpsEmployerNumber,
                'dipe_number' => $dipeNumber,
                'niu' => $niu,
                'dgi_centre' => $dgiCentre,
                'tdl_commune_id' => $tdlCommuneId,
                'cnps_regime' => $cnpsRegime->value,
                'rp_risk_class' => $rpRiskClass,
                'cnps_notification_document_id' => $cnpsNotificationDocumentId,
                'cnps_notification_reference' => $cnpsNotificationReference,
                'proration_basis' => $prorationBasis,
                'ceiling_prorates_partial_month' => $ceilingProratesPartialMonth,
                'irpp_mode' => $irppMode->value,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'created_by' => $actor->id ?? throw new DomainException(
                    'Configuring the employer profile requires an authenticated actor.'
                ),
            ]);

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::SettingChanged,
                module: 'Payroll',
                auditableType: EmployerProfile::class,
                auditableId: (int) $profile->getKey(),
                after: [
                    'cnps_employer_number' => $cnpsEmployerNumber,
                    'cnps_regime' => $cnpsRegime->value,
                    'rp_risk_class' => $rpRiskClass,
                    'cnps_notification_reference' => $cnpsNotificationReference,
                    'effective_from' => $effectiveFrom,
                    // The wizard's affirmative confirmations, recorded (3.1).
                    'regime_confirmed' => true,
                    'risk_class_confirmed' => true,
                ],
                actor: $actor,
            );

            return $profile;
        });
    }
}

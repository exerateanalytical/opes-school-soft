<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\ConsultationOutcome;
use App\Modules\Welfare\Domain\MedicalPermission;
use App\Modules\Welfare\Models\MedicalConsultation;
use App\Modules\Welfare\Models\MedicalReferral;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W3). Refers a consultation out to an external
 * facility. Recording a referral also flips the parent consultation's
 * outcome to `referred` in the same transaction, so the two rows can never
 * tell different stories about how the visit ended. The clinical `reason`
 * is encrypted by the model cast and never reaches the audit log.
 */
final class RecordReferral
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $consultationId,
        string $referredTo,
        string $reason,
        Carbon $referredOn,
        Actor $actor,
    ): MedicalReferral {
        Gate::authorize(MedicalPermission::MANAGE);

        if (trim($referredTo) === '') {
            throw ValidationException::withMessages([
                'referred_to' => 'A referral requires the facility or practitioner it goes to.',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A referral requires the clinical reason.',
            ]);
        }

        return DB::transaction(function () use (
            $consultationId, $referredTo, $reason, $referredOn, $actor
        ): MedicalReferral {
            /** @var MedicalConsultation $consultation */
            $consultation = MedicalConsultation::query()
                ->lockForUpdate()
                ->findOrFail($consultationId);

            $referral = MedicalReferral::query()->create([
                'consultation_id' => (int) $consultation->getKey(),
                'referred_to' => trim($referredTo),
                'reason' => trim($reason),
                'referred_on' => $referredOn,
                'referred_by' => $actor->id,
            ]);

            if ($consultation->outcome !== ConsultationOutcome::Referred) {
                $consultation->fill(['outcome' => ConsultationOutcome::Referred])->save();
            }

            // NO clinical text in the audit trail - it is plaintext at rest.
            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Welfare',
                auditableType: MedicalReferral::class,
                auditableId: (int) $referral->getKey(),
                after: [
                    'consultation_id' => (int) $consultation->getKey(),
                    'student_id' => $consultation->student_id,
                    'referred_to' => trim($referredTo),
                    'referred_on' => $referredOn->toDateString(),
                ],
                actor: $actor,
            );

            return $referral->refresh();
        });
    }
}

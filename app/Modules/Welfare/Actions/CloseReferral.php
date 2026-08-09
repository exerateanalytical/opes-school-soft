<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\MedicalPermission;
use App\Modules\Welfare\Models\MedicalReferral;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/plans/phase-10.md §4 (W3). Records the follow-up on a referral and
 * closes it. A referral is open exactly while followed_up_at is NULL, so a
 * second close is refused rather than silently overwriting the recorded
 * follow-up. Follow-up `notes` are clinical narrative: encrypted by the
 * model cast, absent from the audit entry.
 */
final class CloseReferral
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $referralId,
        Carbon $followedUpAt,
        ?string $notes,
        Actor $actor,
    ): MedicalReferral {
        Gate::authorize(MedicalPermission::MANAGE);

        return DB::transaction(function () use ($referralId, $followedUpAt, $notes, $actor): MedicalReferral {
            /** @var MedicalReferral $referral */
            $referral = MedicalReferral::query()
                ->lockForUpdate()
                ->findOrFail($referralId);

            if ($referral->followed_up_at !== null) {
                throw new DomainException(
                    'This referral is already closed; its follow-up was recorded on '
                    .$referral->followed_up_at->toDateString().'.'
                );
            }

            if ($followedUpAt->lessThan(Carbon::parse($referral->referred_on->toDateString()))) {
                throw new DomainException('A referral cannot be followed up before it was made.');
            }

            $referral->fill([
                'followed_up_at' => $followedUpAt,
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Welfare',
                auditableType: MedicalReferral::class,
                auditableId: (int) $referral->getKey(),
                after: [
                    'consultation_id' => $referral->consultation_id,
                    'followed_up_at' => $followedUpAt->toDateTimeString(),
                ],
                actor: $actor,
            );

            return $referral->refresh();
        });
    }
}

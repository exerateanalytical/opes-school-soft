<?php

declare(strict_types=1);

namespace App\Modules\Activities\Actions;

use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Domain\ConsentStatus;
use App\Modules\Activities\Models\Activity;
use App\Modules\Activities\Models\ActivityMembership;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Records a guardian's consent decision for a student's excursion seat -
 * the gap-analysis row-15 tie-in, held as columns on the membership.
 *
 *  - Only an EXCURSION membership carries consent; recording it on a club
 *    or team is refused.
 *  - The deciding guardian must actually be linked to THIS student via a
 *    currently-valid student_guardians row - read via DB::table, never
 *    the Guardians module's Models (ModuleBoundaryTest). An unlinked
 *    guardian consenting to someone else's child is exactly the boundary
 *    GuardianScopeMatrix exists to police; this Action refuses it.
 *  - The decision is granted or declined - never back to pending. A
 *    changed decision overwrites, with the audit trail keeping both.
 */
final class RecordConsent
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $membershipId,
        int $guardianId,
        ConsentStatus $decision,
        ?string $note,
        Actor $actor,
    ): ActivityMembership {
        Gate::authorize(ActivityPermission::MANAGE);

        if (! $decision->isDecision()) {
            throw new DomainException(
                'A consent record is granted or declined; pending is the absence of one.'
            );
        }

        return DB::transaction(function () use ($membershipId, $guardianId, $decision, $note, $actor): ActivityMembership {
            /** @var ActivityMembership $membership */
            $membership = ActivityMembership::query()->lockForUpdate()->findOrFail($membershipId);

            /** @var Activity $activity */
            $activity = Activity::query()->findOrFail($membership->activity_id);

            if (! $activity->type->isExcursion()) {
                throw new DomainException(
                    "'{$activity->name}' is not an excursion; guardian consent applies to excursions only."
                );
            }

            $today = Carbon::today()->toDateString();

            $linked = DB::table('student_guardians')
                ->where('guardian_id', $guardianId)
                ->where('student_id', $membership->student_id)
                ->where('valid_from', '<=', $today)
                ->where(function ($q) use ($today): void {
                    $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today);
                })
                ->exists();

            if (! $linked) {
                throw new DomainException(
                    'That guardian holds no current link to this student and cannot consent for them.'
                );
            }

            $before = $membership->consent_status?->value;

            $trimmedNote = trim((string) ($note ?? ''));

            $membership->fill([
                'consent_status' => $decision,
                'consent_guardian_id' => $guardianId,
                'consent_recorded_by' => $actor->id,
                'consent_recorded_at' => Carbon::now(),
                'consent_note' => $trimmedNote === '' ? null : $trimmedNote,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Activities',
                auditableType: ActivityMembership::class,
                auditableId: (int) $membership->getKey(),
                before: ['consent_status' => $before],
                after: [
                    'consent_status' => $decision->value,
                    'guardian_id' => $guardianId,
                    'activity' => $activity->name,
                ],
                actor: $actor,
            );

            return $membership->refresh();
        });
    }
}

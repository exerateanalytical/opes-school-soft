<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md 7.2: "Unlink is `valid_to = business_date()` +
 * `revocation_reason`. There is NO hard delete."
 *
 * End-dated, not soft-deleted and certainly not removed. The distinction
 * matters: a deleted row cannot answer "who was allowed to collect this child
 * in March?", which is the question asked when something has gone wrong, and
 * the FKs are RESTRICT precisely so that the answer survives.
 *
 * Closing today rather than tomorrow is deliberate and differs from
 * SetGuardianAuthorization's successor dating. Under the 7.3 predicate
 * `valid_to >= business_date()` a link closed with today's date is still valid
 * FOR TODAY. That is right for a scope change, where a successor row takes
 * over tomorrow and there must be no uncovered day - and it is what the spec
 * says for revocation too. Where access must stop within the hour rather than
 * at midnight, the lever 7.6 names is session revocation, not an earlier date.
 */
final class UnlinkGuardian
{
    public function handle(
        StudentGuardian $link,
        string $revocationReason,
        ?Actor $actor = null,
    ): StudentGuardian {
        Gate::authorize(Permission::GuardiansManage->value);

        $actor ??= $this->currentActor();

        if (trim($revocationReason) === '') {
            // 7.2 makes the reason mandatory whenever valid_to is set to a
            // past or current date. "Why did this person lose access to this
            // child" is the first question asked in a custody dispute, and an
            // empty string is not an answer.
            throw ValidationException::withMessages([
                'revocation_reason' => 'A revocation reason is required to end a guardian link.',
            ]);
        }

        $today = BusinessDate::today();

        return DB::transaction(function () use ($link, $revocationReason, $today, $actor): StudentGuardian {
            /** @var StudentGuardian|null $fresh */
            $fresh = StudentGuardian::query()->whereKey($link->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                throw ValidationException::withMessages([
                    'link' => 'This guardian link no longer exists.',
                ]);
            }

            if ($fresh->valid_to !== null) {
                // Idempotence would be wrong here: silently succeeding would
                // let a second operator believe they revoked access when in
                // fact someone else set an earlier date and a different reason.
                throw ValidationException::withMessages([
                    'link' => 'This guardian link was already revoked on '.$fresh->valid_to->toDateString().'.',
                ]);
            }

            $before = $this->auditPayload($fresh);

            // setAttribute, not `->valid_to =`: the property is documented as
            // Carbon|null for static analysis, while the `date` cast wants the
            // raw Y-m-d string on the way in. Assigning through the magic
            // setter keeps both honest without a cast dance.
            $fresh->setAttribute('valid_to', $today);
            $fresh->revocation_reason = $revocationReason;
            $fresh->updated_by = $actor->id;
            $fresh->save();

            $after = $this->auditPayload($fresh->refresh());

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Guardians',
                auditableType: StudentGuardian::class,
                auditableId: (int) $fresh->getKey(),
                before: $before,
                after: $after,
                actor: $actor,
            );

            return $fresh;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(StudentGuardian $link): array
    {
        $payload = [
            'student_id' => $link->student_id,
            'guardian_id' => $link->guardian_id,
            'valid_from' => $link->valid_from->toDateString(),
            'valid_to' => $link->valid_to?->toDateString(),
            'revocation_reason' => $link->revocation_reason,
        ];

        foreach (StudentGuardian::AUDITED_SCOPE_COLUMNS as $flag) {
            $payload[$flag] = (bool) $link->getAttribute($flag);
        }

        return $payload;
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}

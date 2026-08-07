<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

use App\Modules\Guardians\Domain\GuardianRelationship;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md 7.2 - create the StudentGuardian link with its
 * relationship and its INITIAL authorization flags.
 *
 * Initial is the operative word. Flags are never edited in place afterwards;
 * 7.2 requires a scope change to close the current row and insert a successor,
 * which is SetGuardianAuthorization's job. That is what makes "a custody
 * change neither deletes history nor leaves stale access" true.
 */
final class LinkGuardian
{
    public function handle(
        int $studentId,
        int $guardianId,
        GuardianRelationship $relationship,
        ?string $relationshipOther = null,
        bool $isPrimary = false,
        bool $hasCustody = false,
        bool $receivesReports = false,
        bool $receivesInvoices = false,
        bool $isEmergencyContact = false,
        bool $isAuthorisedForPickup = false,
        bool $isFeePayer = false,
        ?string $validFrom = null,
        ?Actor $actor = null,
    ): StudentGuardian {
        Gate::authorize(Permission::GuardiansManage->value);

        $actor ??= $this->currentActor();

        // 7.3: resolved ONCE, here, and passed down - not re-read per query.
        $today = BusinessDate::today();
        $validFrom ??= $today;

        if ($relationship->requiresFreeText() && ($relationshipOther === null || trim($relationshipOther) === '')) {
            throw ValidationException::withMessages([
                'relationship_other' => 'Describe the relationship when it is recorded as "other".',
            ]);
        }

        if (! $relationship->requiresFreeText()) {
            // Otherwise a relationship corrected from `other` to `mother`
            // leaves the stale free text behind, and the profile screen prints
            // both.
            $relationshipOther = null;
        }

        // 7.2, stated verbatim: "is_primary = 1 implies has_custody = 1.
        // Rejected otherwise." NOT silently coerced: the primary guardian is
        // the default addressee on every printed document, and quietly
        // granting custody to make a printing default work is precisely the
        // conflation the matrix exists to prevent. The operator must say it.
        if ($isPrimary && ! $hasCustody) {
            throw ValidationException::withMessages([
                'is_primary' => 'The primary guardian must also hold custody.',
            ]);
        }

        return DB::transaction(function () use (
            $studentId, $guardianId, $relationship, $relationshipOther, $isPrimary, $hasCustody,
            $receivesReports, $receivesInvoices, $isEmergencyContact, $isAuthorisedForPickup,
            $isFeePayer, $validFrom, $today, $actor
        ): StudentGuardian {
            // 7.2: "A student may have at most ONE open link per guardian;
            // re-linking after revocation creates a new row with a later
            // valid_from." Checked under the transaction; `uq_link` only
            // guards the same valid_from, so this is the clause that stops a
            // second open row dated a day later.
            $openLink = StudentGuardian::query()
                ->where('student_id', '=', $studentId)
                ->where('guardian_id', '=', $guardianId)
                ->whereNull('valid_to')
                ->lockForUpdate()
                ->first();

            if ($openLink !== null) {
                throw ValidationException::withMessages([
                    'guardian_id' => 'This guardian is already linked to this student. Revoke the existing link first.',
                ]);
            }

            if ($isPrimary) {
                // "Every active student has exactly one current primary
                // guardian" (7.2). At-most-one is enforced by
                // uq_primary_guardian on the generated column; this read makes
                // the failure legible instead of a driver error, and the catch
                // below covers the concurrent case the read cannot see.
                $existingPrimary = StudentGuardian::query()
                    ->where('student_id', '=', $studentId)
                    ->where('is_primary', '=', true)
                    ->whereNull('valid_to')
                    ->lockForUpdate()
                    ->first();

                if ($existingPrimary !== null) {
                    throw ValidationException::withMessages([
                        'is_primary' => 'This student already has a primary guardian. Revoke or demote that link first.',
                    ]);
                }
            }

            $attributes = [
                'student_id' => $studentId,
                'guardian_id' => $guardianId,
                'relationship' => $relationship,
                'relationship_other' => $relationshipOther,
                'is_primary' => $isPrimary,
                'has_custody' => $hasCustody,
                'receives_reports' => $receivesReports,
                'receives_invoices' => $receivesInvoices,
                'is_emergency_contact' => $isEmergencyContact,
                'is_authorised_for_pickup' => $isAuthorisedForPickup,
                'is_fee_payer' => $isFeePayer,
                'valid_from' => $validFrom,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ];

            try {
                $link = StudentGuardian::query()->create($attributes);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'guardian_id' => 'This link already exists for the given start date.',
                ]);
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'Guardians',
                auditableType: StudentGuardian::class,
                auditableId: (int) $link->getKey(),
                after: $this->auditPayload($link, $today),
                actor: $actor,
            );

            return $link;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(StudentGuardian $link, string $today): array
    {
        $payload = [
            'student_id' => $link->student_id,
            'guardian_id' => $link->guardian_id,
            'relationship' => $link->relationship->value,
            'valid_from' => $link->valid_from->toDateString(),
            'valid_to' => $link->valid_to?->toDateString(),
            // Recorded because a link created with a future valid_from grants
            // nothing yet (7.3), and an auditor reading the row a year later
            // has no other way to know that.
            'effective_today' => $link->isValid($today),
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

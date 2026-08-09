<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

use App\Modules\Guardians\Domain\PortalInvitationCode;
use App\Modules\Guardians\Domain\PortalSubjectType;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\PortalInvitation;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Issue a portal activation code for a guardian or a staff member
 * (docs/plans/phase-12-13.md 12.2; migration 2026_08_09_300003).
 *
 * The returned array carries the plaintext code EXACTLY ONCE - the issuing
 * screen shows it, the operator hands it over (printed slip, WhatsApp, over
 * the counter - 00-core 9.3 assumes no SMTP), and it is gone: only the
 * SHA-256 lands in the database, so a database read cannot impersonate
 * anybody.
 *
 * Issuing supersedes: any still-open invitation for the same subject is
 * revoked in the same transaction, so at most one code is ever redeemable
 * per person and "I lost the slip" has a safe answer - reissue, and the lost
 * code dies.
 */
final class IssuePortalInvitation
{
    /** Codes are short-lived by design. */
    public const TTL_DAYS = 14;

    /**
     * @return array{invitation: PortalInvitation, code: string}
     */
    public function handle(
        PortalSubjectType $subjectType,
        int $subjectId,
        ?Actor $actor = null,
    ): array {
        Gate::authorize(Permission::PortalManage->value);

        $actor ??= auth()->user()?->toAuditActor() ?? Actor::system();

        $this->assertSubjectCanBeInvited($subjectType, $subjectId);

        $code = PortalInvitationCode::generate();
        $now = Carbon::now();

        $invitation = DB::transaction(function () use ($subjectType, $subjectId, $code, $now, $actor): PortalInvitation {
            // Supersede: at most one open invitation per subject.
            PortalInvitation::query()
                ->where('subject_type', $subjectType->value)
                ->where('subject_id', $subjectId)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->each(function (PortalInvitation $stale) use ($now): void {
                    $stale->revoked_at = $now;
                    $stale->save();
                });

            $invitation = PortalInvitation::query()->create([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'code_hash' => PortalInvitationCode::hash($code),
                'expires_at' => $now->copy()->addDays(self::TTL_DAYS),
                'issued_by' => $actor->id,
                'issued_at' => $now,
            ]);

            // The audit row names the subject and the expiry - NEVER the code
            // or its hash. The hash is already the secret's shadow; copying it
            // into a second, long-retention table widens the surface for
            // nothing.
            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Created,
                module: 'Guardians',
                auditableType: PortalInvitation::class,
                auditableId: (int) $invitation->getKey(),
                after: [
                    'subject_type' => $subjectType->value,
                    'subject_id' => $subjectId,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ],
                actor: $actor,
            );

            return $invitation;
        });

        return ['invitation' => $invitation, 'code' => $code];
    }

    /**
     * The subject must exist, be active, and not already hold a portal
     * account. Guardians are this module's own model; staff members are
     * another module's table, read through the query builder (the sanctioned
     * cross-module read - ModuleBoundaryTest forbids importing the model).
     */
    private function assertSubjectCanBeInvited(PortalSubjectType $subjectType, int $subjectId): void
    {
        if ($subjectType === PortalSubjectType::Guardian) {
            $guardian = Guardian::query()->find($subjectId);

            if ($guardian === null || $guardian->is_archived) {
                throw ValidationException::withMessages(['subject' => 'This guardian does not exist.']);
            }

            if (! $guardian->isActive()) {
                throw ValidationException::withMessages([
                    'subject' => 'This guardian is inactive; reactivate them before inviting them to the portal.',
                ]);
            }

            if ($guardian->portal_user_id !== null) {
                throw ValidationException::withMessages([
                    'subject' => 'This guardian already has a portal account.',
                ]);
            }

            return;
        }

        $staff = DB::table('staff_members')
            ->where('id', $subjectId)
            ->first(['id', 'status', 'portal_user_id']);

        if ($staff === null) {
            throw ValidationException::withMessages(['subject' => 'This staff member does not exist.']);
        }

        if ($staff->status !== 'active') {
            throw ValidationException::withMessages([
                'subject' => 'This staff member is not active; only active staff can be invited to the portal.',
            ]);
        }

        if ($staff->portal_user_id !== null) {
            throw ValidationException::withMessages([
                'subject' => 'This staff member already has a portal account.',
            ]);
        }
    }
}

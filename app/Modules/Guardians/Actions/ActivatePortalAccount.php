<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

use App\Modules\Guardians\Domain\PortalInvitationCode;
use App\Modules\Guardians\Domain\PortalSubjectType;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\PortalInvitation;
use App\Modules\Identity\Actions\ProvisionPortalUser;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Redeem an invitation code: create the portal account, assign the portal
 * role, link `portal_user_id` on the subject, consume the code
 * (docs/plans/phase-12-13.md 12.2).
 *
 * UNAUTHENTICATED by design - the person activating has no account yet. The
 * invitation code IS the credential, which shapes two rules:
 *
 *   - every code failure - unknown, expired, revoked, already used - answers
 *     with the SAME generic message. A distinguishable "expired" tells a
 *     guesser they found a real code; generic tells them nothing.
 *   - the subject's fitness is re-checked at redemption, not just at issue:
 *     a guardian deactivated or linked in the two weeks between the slip
 *     being printed and typed gets the same generic refusal.
 *
 * Everything happens in one transaction with the invitation row locked, so
 * two concurrent submissions of the same code cannot both mint an account.
 */
final class ActivatePortalAccount
{
    public const GENERIC_FAILURE = 'This activation code is not valid. Ask the school office for a new one.';

    /**
     * @return int the id of the newly-provisioned portal user
     */
    public function handle(string $code, string $name, string $email, string $password): int
    {
        $hash = PortalInvitationCode::hash($code);
        $now = Carbon::now();

        return DB::transaction(function () use ($hash, $name, $email, $password, $now): int {
            $invitation = PortalInvitation::query()
                ->where('code_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ! $invitation->isOpen($now)) {
                throw ValidationException::withMessages(['code' => self::GENERIC_FAILURE]);
            }

            $this->assertSubjectStillEligible($invitation);

            // A distinct, honest message: this failure is about the CHOSEN
            // email, not the code, and the person holding a valid code is
            // entitled to know which field to fix.
            if (DB::table('users')->where('email', $email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This email address is already in use. Choose another.',
                ]);
            }

            $user = app(ProvisionPortalUser::class)->handle(
                name: $name,
                email: $email,
                role: $invitation->subject_type->portalRole(),
                plainPassword: $password,
            );

            $userId = (int) $user->getKey();

            $this->linkSubject($invitation, $userId);

            $invitation->used_at = $now;
            $invitation->used_by_user_id = $userId;
            $invitation->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Guardians',
                auditableType: PortalInvitation::class,
                auditableId: (int) $invitation->getKey(),
                before: ['used_at' => null],
                after: [
                    'subject_type' => $invitation->subject_type->value,
                    'subject_id' => $invitation->subject_id,
                    'used_at' => $now->toIso8601String(),
                    'used_by_user_id' => $userId,
                ],
                actor: new Actor($userId, $name),
            );

            return $userId;
        });
    }

    /**
     * Re-check at redemption what IssuePortalInvitation checked at issue.
     * All failures are the generic code failure: which precondition broke is
     * the school's business, not the code-holder's.
     */
    private function assertSubjectStillEligible(PortalInvitation $invitation): void
    {
        if ($invitation->subject_type === PortalSubjectType::Guardian) {
            $guardian = Guardian::query()
                ->whereKey($invitation->subject_id)
                ->lockForUpdate()
                ->first();

            $eligible = $guardian !== null
                && ! $guardian->is_archived
                && $guardian->isActive()
                && $guardian->portal_user_id === null;

            if (! $eligible) {
                throw ValidationException::withMessages(['code' => self::GENERIC_FAILURE]);
            }

            return;
        }

        $staff = DB::table('staff_members')
            ->where('id', $invitation->subject_id)
            ->lockForUpdate()
            ->first(['id', 'status', 'portal_user_id']);

        $eligible = $staff !== null
            && $staff->status === 'active'
            && $staff->portal_user_id === null;

        if (! $eligible) {
            throw ValidationException::withMessages(['code' => self::GENERIC_FAILURE]);
        }
    }

    private function linkSubject(PortalInvitation $invitation, int $userId): void
    {
        if ($invitation->subject_type === PortalSubjectType::Guardian) {
            // Through the model so the UNIQUE portal_user_id and the model's
            // own casts apply; the row is already locked above.
            Guardian::query()
                ->whereKey($invitation->subject_id)
                ->update(['portal_user_id' => $userId]);

            return;
        }

        // staff_members belongs to HR; the query builder is the sanctioned
        // cross-module path (ModuleBoundaryTest forbids importing the model).
        DB::table('staff_members')
            ->where('id', $invitation->subject_id)
            ->update(['portal_user_id' => $userId]);
    }
}

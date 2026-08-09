<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

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
 * Kill an invitation code before it expires (migration 2026_08_09_300003:
 * "guardian lost custody, staff left"). The code stops redeeming
 * immediately; an already-activated account is untouched - revoking ACCESS
 * is SetGuardianAuthorization / guardian deactivation, not this.
 */
final class RevokePortalInvitation
{
    public function handle(PortalInvitation $invitation, ?Actor $actor = null): PortalInvitation
    {
        Gate::authorize(Permission::PortalManage->value);

        $actor ??= auth()->user()?->toAuditActor() ?? Actor::system();

        return DB::transaction(function () use ($invitation, $actor): PortalInvitation {
            /** @var PortalInvitation|null $current */
            $current = PortalInvitation::query()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                throw ValidationException::withMessages(['invitation' => 'This invitation no longer exists.']);
            }

            if ($current->used_at !== null) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation was already used; deactivate the account instead.',
                ]);
            }

            if ($current->revoked_at !== null) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is already revoked.']);
            }

            $current->revoked_at = Carbon::now();
            $current->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Guardians',
                auditableType: PortalInvitation::class,
                auditableId: (int) $current->getKey(),
                before: ['revoked_at' => null],
                after: [
                    'subject_type' => $current->subject_type->value,
                    'subject_id' => $current->subject_id,
                    'revoked_at' => $current->revoked_at->toIso8601String(),
                ],
                actor: $actor,
            );

            return $current;
        });
    }
}

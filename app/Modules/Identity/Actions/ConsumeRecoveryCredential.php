<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\RecoveryCode;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\RecoveryCredential;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class ConsumeRecoveryCredential
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /** Returns the user to log in as, or null if the code is not usable. */
    public function handle(string $plainCode): ?User
    {
        $normalised = RecoveryCode::normalise($plainCode);

        return DB::transaction(function () use ($normalised): ?User {
            $credential = RecoveryCredential::query()
                ->active()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($credential === null || ! Hash::check($normalised, $credential->code_hash)) {
                return null;
            }

            $admin = User::query()
                ->where('status', 'active')
                ->whereHas('roles', static function ($q): void {
                    $q->whereIn('name', [Role::SuperAdmin->value, Role::Administrator->value]);
                })
                ->orderBy('id')
                ->first();

            if ($admin === null) {
                return null;
            }

            $credential->used_at = now();
            $credential->save();

            $this->audit->handle(
                action: AuditAction::RecoveryUsed,
                module: 'Identity',
                auditableType: User::class,
                auditableId: (int) $admin->getKey(),
                actor: $admin->toAuditActor(),
            );

            return $admin;
        });
    }
}

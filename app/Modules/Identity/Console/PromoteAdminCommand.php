<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;

/**
 * Vendor escape hatch (docs/specs/00-core.md 9.3). Requires server access,
 * which is the point: it is the last resort when even the recovery credential
 * is gone.
 */
final class PromoteAdminCommand extends Command
{
    protected $signature = 'opes:promote-admin {email}';

    protected $description = 'Grant the Administrator role to a user by email. Requires server access.';

    public function handle(WriteAuditEntry $audit): int
    {
        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        $user->assignRole(Role::Administrator->value);

        $audit->handle(
            action: AuditAction::RoleAssigned,
            module: 'Identity',
            auditableType: User::class,
            auditableId: (int) $user->getKey(),
            after: ['role' => Role::Administrator->value, 'via' => 'opes:promote-admin'],
            actor: $user->toAuditActor(),
        );

        $this->info("Granted Administrator to [{$email}].");
        $this->line('This action was audited.');

        return self::SUCCESS;
    }
}

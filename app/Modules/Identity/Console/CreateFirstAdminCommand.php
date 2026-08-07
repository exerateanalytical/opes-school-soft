<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * First-run bootstrap only (docs/specs/00-core.md 9.3-adjacent). There is no
 * authenticated actor on a brand-new install, so this deliberately bypasses
 * the CreateUser action (which requires one) and refuses to run at all once
 * any user exists - creating further admins is the UI's job from then on.
 */
final class CreateFirstAdminCommand extends Command
{
    protected $signature = 'opes:create-admin {--name=} {--email=}';

    protected $description = 'Bootstrap the very first Administrator account. Refuses to run once any user exists.';

    public function handle(WriteAuditEntry $audit): int
    {
        if (User::query()->exists()) {
            $this->error('Refusing to run: a user already exists. Use the Users screen or opes:promote-admin instead.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: $this->ask('Administrator name'));
        $email = (string) ($this->option('email') ?: $this->ask('Administrator email'));

        if (RoleModel::query()->count() === 0) {
            (new RolePermissionSeeder())->run();
        }

        $password = Str::password(20);

        $user = DB::transaction(function () use ($name, $email, $password, $audit): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password, // 'hashed' cast applies argon2id
                'status' => 'active',
            ]);

            $user->assignRole(Role::Administrator->value);

            $audit->handle(
                action: AuditAction::Created,
                module: 'Identity',
                auditableType: User::class,
                auditableId: (int) $user->getKey(),
                after: ['name' => $name, 'email' => $email, 'role' => Role::Administrator->value, 'via' => 'opes:create-admin'],
                actor: Actor::system(),
            );

            return $user;
        });

        $this->components->info("Created administrator [{$user->email}].");
        $this->line('Generated password (shown once - it will not be shown again, change it on first login):');
        $this->line($password);

        return self::SUCCESS;
    }
}

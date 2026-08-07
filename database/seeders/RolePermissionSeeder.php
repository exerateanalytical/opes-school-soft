<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\Permission as PermissionEnum;
use App\Modules\Identity\Domain\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (RoleEnum::cases() as $role) {
            $model = Role::findOrCreate($role->value, 'web');

            $model->syncPermissions(
                array_map(
                    static fn (PermissionEnum $p): string => $p->value,
                    $role->defaultPermissions(),
                )
            );
        }
    }
}

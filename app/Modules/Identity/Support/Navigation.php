<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Domain\Permission;

/**
 * The sidebar, from docs/specs/09-ui.md section 2.
 *
 * Items whose module does not exist yet are listed with `enabled => false` and
 * render disabled with an explanatory title. Hiding them would misrepresent the
 * product; linking them to nothing would be worse. Each item also carries the
 * permission that gates it, so the nav and the route agree by construction.
 *
 * @phpstan-type NavItem array{key: string, route: string|null, permission: Permission|null, enabled: bool}
 */
final class Navigation
{
    /**
     * @return list<NavItem>
     */
    public static function items(): array
    {
        return [
            ['key' => 'dashboard', 'route' => '/dashboard', 'permission' => null, 'enabled' => true],
            ['key' => 'admissions', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'students', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'guardians', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'staff', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'classes', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'subjects', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'timetable', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'attendance', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'examinations', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'results', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'finance', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'library', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'inventory', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'transport', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'hostel', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'reports', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'users', 'route' => '/users', 'permission' => Permission::UserView, 'enabled' => true],
            ['key' => 'settings', 'route' => null, 'permission' => Permission::SettingView, 'enabled' => false],
        ];
    }
}

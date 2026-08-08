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
            ['key' => 'admissions', 'route' => '/admissions', 'permission' => Permission::AdmissionsManage, 'enabled' => true],
            ['key' => 'students', 'route' => '/students', 'permission' => Permission::StudentsView, 'enabled' => true],
            // Guardians are reachable only through a student's record for now:
            // there is a `guardians.show` route but no guardian list. Pointing
            // a nav item at a detail route with no id would be a link to
            // nothing, so this stays disabled until the list screen exists.
            ['key' => 'guardians', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'staff', 'route' => null, 'permission' => null, 'enabled' => false],
            // Gated on manage, not view: the route behind it is
            // `can:academics.manage`, and this file's contract is that the nav
            // and the route agree by construction. A Teacher with only
            // `academics.view` must not be shown a link that answers 403.
            ['key' => 'academics', 'route' => '/academics/settings', 'permission' => Permission::AcademicsManage, 'enabled' => true],
            ['key' => 'classes', 'route' => '/classes', 'permission' => Permission::AcademicsView, 'enabled' => true],
            ['key' => 'subjects', 'route' => '/subjects', 'permission' => Permission::AcademicsView, 'enabled' => true],
            ['key' => 'timetable', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'attendance', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'examinations', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'results', 'route' => null, 'permission' => null, 'enabled' => false],
            ['key' => 'finance', 'route' => null, 'permission' => null, 'enabled' => false],
            // The general ledger (09-ui.md's Finance section covers fees and
            // accounting together at `/finance`, but that dashboard route does
            // not exist yet). This item is scoped to the ledger screens Phase 4
            // ships: chart of accounts, journal entries, trial balance. Gated
            // on ledger.view so the sidebar and the routes below agree by
            // construction, per this file's documented contract.
            ['key' => 'ledger', 'route' => '/ledger/chart-of-accounts', 'permission' => Permission::LedgerView, 'enabled' => true],
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

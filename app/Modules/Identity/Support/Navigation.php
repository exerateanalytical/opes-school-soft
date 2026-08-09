<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Domain\Permission;

/**
 * The sidebar, from docs/specs/09-ui.md section 2.
 *
 * Every item is a real link. Modules that are not built yet carry
 * `built => false`: their link leads to an in-shell placeholder page at the
 * SAME URL the real module will later occupy (so bookmarks survive the
 * module landing), and the nav renders a small "soon" chip beside them.
 * Hiding future modules would misrepresent the product; a dead grey label
 * reads as a defect to an operator, which is exactly how it was reported.
 *
 * Each item also carries the permission that gates it, so the nav and the
 * route agree by construction. Placeholder pages contain no data, so
 * `permission => null` (auth only) is correct for them.
 *
 * @phpstan-type NavItem array{key: string, route: string|null, permission: Permission|null, enabled: bool, built: bool}
 */
final class Navigation
{
    /**
     * @return list<NavItem>
     */
    public static function items(): array
    {
        return [
            ['key' => 'dashboard', 'route' => '/dashboard', 'permission' => null, 'enabled' => true, 'built' => true],
            ['key' => 'admissions', 'route' => '/admissions', 'permission' => Permission::AdmissionsManage, 'enabled' => true, 'built' => true],
            ['key' => 'students', 'route' => '/students', 'permission' => Permission::StudentsView, 'enabled' => true, 'built' => true],
            // Guardian RECORDS exist (Phase 2) and are reached through a
            // student's profile; what is missing is the guardian LIST screen.
            // The placeholder at /guardians says exactly that, and the real
            // list will take over the same URL when it ships.
            ['key' => 'guardians', 'route' => '/guardians', 'permission' => null, 'enabled' => true, 'built' => false],
            ['key' => 'staff', 'route' => '/staff', 'permission' => null, 'enabled' => true, 'built' => false],
            // Gated on manage, not view: the route behind it is
            // `can:academics.manage`, and this file's contract is that the nav
            // and the route agree by construction. A Teacher with only
            // `academics.view` must not be shown a link that answers 403.
            ['key' => 'academics', 'route' => '/academics/settings', 'permission' => Permission::AcademicsManage, 'enabled' => true, 'built' => true],
            ['key' => 'classes', 'route' => '/classes', 'permission' => Permission::AcademicsView, 'enabled' => true, 'built' => true],
            ['key' => 'subjects', 'route' => '/subjects', 'permission' => Permission::AcademicsView, 'enabled' => true, 'built' => true],
            ['key' => 'timetable', 'route' => '/timetable', 'permission' => null, 'enabled' => true, 'built' => false],
            ['key' => 'attendance', 'route' => '/attendance', 'permission' => null, 'enabled' => true, 'built' => false],
            // Exam SCHEDULING (sittings, invigilators, seating) shipped with
            // Phase 3's Actions; marks entry lives at /marks. What these two
            // placeholders await is their dedicated screens.
            ['key' => 'examinations', 'route' => '/examinations', 'permission' => null, 'enabled' => true, 'built' => false],
            ['key' => 'results', 'route' => '/results', 'permission' => null, 'enabled' => true, 'built' => false],
            // Fees (Phase 6): the finance item lands on the invoices list;
            // the cashier and per-student statements hang off it. Gated on
            // fee.view, matching its route, per this file's nav-and-route-
            // agree-by-construction contract; the ACT of collecting is gated
            // harder (fee.collect) inside the cashier screen, the same
            // screen-vs-write split the ledger item uses.
            ['key' => 'finance', 'route' => '/finance/invoices', 'permission' => Permission::FeeView, 'enabled' => true, 'built' => true],
            // The general ledger (09-ui.md's Finance section covers fees and
            // accounting together at `/finance`, but that dashboard route does
            // not exist yet). This item is scoped to the ledger screens Phase 4
            // ships: chart of accounts, journal entries, trial balance. Gated
            // on ledger.view so the sidebar and the routes below agree by
            // construction, per this file's documented contract.
            ['key' => 'ledger', 'route' => '/ledger/chart-of-accounts', 'permission' => Permission::LedgerView, 'enabled' => true, 'built' => true],
            // Procurement (Phase 5): lands on the supplier register; the
            // rest of the P2P chain (requisitions, orders, receipts,
            // invoices, payments) hangs off it. Gated on procurement.view,
            // matching its route, per this file's nav-and-route-agree-by-
            // construction contract; the ACTS (approve, pay, void) are
            // gated harder inside the screens and Actions.
            ['key' => 'procurement', 'route' => '/procurement/suppliers', 'permission' => Permission::ProcurementView, 'enabled' => true, 'built' => true],
            // Tax & declarations (Phase 5): the compliance dashboard.
            // Gated on tax.view to match its route; generating and filing
            // are gated harder (tax.declare / tax.file) on their screens
            // and Actions. The tax CONFIGURATION cockpit lives under
            // /settings/tax behind ledger.configure.
            ['key' => 'tax', 'route' => '/tax', 'permission' => Permission::TaxView, 'enabled' => true, 'built' => true],
            ['key' => 'library', 'route' => '/library', 'permission' => null, 'enabled' => true, 'built' => false],
            ['key' => 'inventory', 'route' => '/inventory', 'permission' => null, 'enabled' => true, 'built' => false],
            ['key' => 'transport', 'route' => '/transport', 'permission' => null, 'enabled' => true, 'built' => false],
            ['key' => 'hostel', 'route' => '/hostel', 'permission' => null, 'enabled' => true, 'built' => false],
            ['key' => 'reports', 'route' => '/reports', 'permission' => null, 'enabled' => true, 'built' => false],
            ['key' => 'users', 'route' => '/users', 'permission' => Permission::UserView, 'enabled' => true, 'built' => true],
            ['key' => 'settings', 'route' => '/settings', 'permission' => Permission::SettingView, 'enabled' => true, 'built' => false],
        ];
    }

    /**
     * The nav keys whose module is not built yet - each serves the shared
     * placeholder page at its own future URL. routes/web.php iterates this so
     * a key added here gets its route by construction, and the
     * PlaceholderRoutesTest walks it so none can silently 404.
     *
     * @return list<string>
     */
    public static function placeholderKeys(): array
    {
        return array_values(array_map(
            static fn (array $item): string => $item['key'],
            array_filter(
                self::items(),
                static fn (array $item): bool => ! $item['built'] && is_string($item['route']),
            ),
        ));
    }

    /**
     * @return array<string, string> nav key => path, for placeholder items
     */
    public static function placeholderRoutes(): array
    {
        $routes = [];

        foreach (self::items() as $item) {
            if (! $item['built'] && is_string($item['route'])) {
                $routes[$item['key']] = $item['route'];
            }
        }

        return $routes;
    }
}

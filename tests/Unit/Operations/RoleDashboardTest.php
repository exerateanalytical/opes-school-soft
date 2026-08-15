<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Operations\Domain\RoleDashboard;

it('covers every role in the enum', function (): void {
    // A role with no entry lands on the empty dashboard this phase exists to
    // remove, so "we forgot one" must be a test failure, not a support call.
    foreach (Role::cases() as $role) {
        expect(RoleDashboard::for($role))->not->toBeNull("role [{$role->value}] has no dashboard profile");
    }
});

it('gives the portal roles no staff dashboard at all', function (): void {
    // Guardian and StaffPortal have their own portals and are aborted at
    // mount(); a staff profile for them would be dead configuration that
    // someone later "fixes" by removing the abort.
    expect(RoleDashboard::for(Role::Guardian)['panels'])->toBe([])
        ->and(RoleDashboard::for(Role::StaffPortal)['panels'])->toBe([]);
});

it('never gives a role more than six KPI panels', function (): void {
    foreach (Role::cases() as $role) {
        expect(count(RoleDashboard::for($role)['panels']))
            ->toBeLessThanOrEqual(6, "role [{$role->value}] has too many KPI panels to read");
    }
});

it('gives an accountant a finance-shaped dashboard', function (): void {
    $panels = RoleDashboard::for(Role::Accountant)['panels'];

    // The defect this phase fixes: an Accountant used to land on a dashboard
    // with ZERO KPI cards.
    expect($panels)->not->toBe([])
        ->and($panels)->toContain('unposted_entries', 'open_periods');
});

it('gives a teacher a teaching-shaped dashboard', function (): void {
    $panels = RoleDashboard::for(Role::Teacher)['panels'];

    expect($panels)->toContain('my_classes', 'registers_not_taken', 'marks_due')
        // A Teacher has no business reading the ledger; this is the other
        // half of the defect - a raw LedgerIntegrityCheck authorization
        // exception used to render on their dashboard.
        ->and($panels)->not->toContain('unposted_entries');
});

it('names a permission for every panel and every quick action', function (): void {
    foreach (Role::cases() as $role) {
        $profile = RoleDashboard::for($role);

        foreach ($profile['panels'] as $panel) {
            expect(RoleDashboard::panelPermission($panel))
                ->toBeString("panel [{$panel}] has no permission");
        }

        foreach ($profile['quick_actions'] as $action) {
            expect(RoleDashboard::quickAction($action))
                ->toBeArray("quick action [{$action}] has no route/permission pair");
        }
    }
});

it('gives every non-portal role at least one quick action', function (): void {
    foreach (Role::cases() as $role) {
        if (in_array($role, [Role::Guardian, Role::StaffPortal], true)) {
            continue;
        }

        expect(RoleDashboard::for($role)['quick_actions'])
            ->not->toBe([], "role [{$role->value}] has nothing it can do from its own dashboard");
    }
});

it('names only permissions the Permission enum actually declares', function (): void {
    // A permission string the Gate has never heard of makes Gate::allows()
    // return false forever, so the panel silently never renders for anyone -
    // which is exactly the empty dashboard this phase is fixing.
    $known = array_map(static fn (Permission $p): string => $p->value, Permission::cases());

    foreach (RoleDashboard::allPanels() as $panel) {
        expect($known)->toContain(RoleDashboard::panelPermission($panel));
    }

    foreach (RoleDashboard::allQuickActions() as $key) {
        $action = RoleDashboard::quickAction($key);
        expect($known)->toContain($action[1]);
    }
});

it('gives every non-portal role panels its own role permissions can open', function (): void {
    // The second half of the defect: a profile can be perfectly well formed
    // and still render nothing, because none of its panels is inside the
    // role's own grant. Every role must hold the permission for at least one
    // of its own panels and one of its own quick actions.
    foreach (Role::cases() as $role) {
        if (in_array($role, [Role::Guardian, Role::StaffPortal], true)) {
            continue;
        }

        $held = array_map(
            static fn (Permission $p): string => $p->value,
            $role->defaultPermissions(),
        );

        $panels = array_filter(
            RoleDashboard::for($role)['panels'],
            static fn (string $p): bool => in_array(RoleDashboard::panelPermission($p), $held, true),
        );

        expect($panels)->not->toBe([], "role [{$role->value}] cannot see a single one of its own panels");

        $actions = array_filter(
            RoleDashboard::for($role)['quick_actions'],
            static fn (string $a): bool => in_array(RoleDashboard::quickAction($a)[1], $held, true),
        );

        expect($actions)->not->toBe([], "role [{$role->value}] cannot use a single one of its own quick actions");
    }
});

<?php

declare(strict_types=1);

namespace App\Modules\Operations\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Operations\Actions\CollectHealth;
use App\Modules\Operations\Actions\ReadDashboardPanels;
use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Domain\RoleDashboard;
use App\Modules\Operations\Models\Backup;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The landing screen, docs/specs/09-ui.md section 3 — composed PER ROLE.
 *
 * It used to be ONE screen for twenty roles, with five admin-centric tiles
 * (active users, roles configured, system health, last backup, attendance)
 * each gated on an identity or operations permission. Gating them away from
 * the other eighteen roles was correct; having nothing to put in their place
 * was not, and the audit found the two defects that follows from it: an
 * Accountant landed on a page with ZERO KPI cards, and a Teacher landed on
 * one card reading "—" beside a raw authorization exception.
 *
 * WHAT each role sees is RoleDashboard (pure metadata, Domain);
 * the FIGURES come from ReadDashboardPanels (cross-module DB::table reads).
 * This component only composes them, and applies the same two gates to
 * everything it offers: the PERMISSION, because a card that 403s on click
 * teaches the operator that the screen lies, and Route::has, because a role
 * profile can outrun the module that provides its action.
 */
#[Layout('layouts.app')]
final class Dashboard extends Component
{
    /**
     * The staff shell's landing route carries no `can:` gate of its own -
     * every authenticated user lands here by the app's single hard-coded
     * post-login destination (`Login::authenticate()`,
     * `bootstrap/app.php`'s `redirectUsersTo('/dashboard')`) - so without
     * this check a `guardian`/`staff_portal` portal principal, whose ENTIRE
     * grant is the single `portal.access` permission
     * (Identity\Domain\Role::defaultPermissions,
     * AuthorizationMatrixTest), would still see this staff shell render
     * (empty tiles, but the shell itself: sidebar, nav, layout). That is
     * exactly the leak docs/plans/phase-12-13.md 12.2 promises the portal
     * shells do NOT share -
     * `GuardianDenyByDefaultRouteEnumerationTest`/`StaffPortalTest` assert
     * a portal principal gets refused here, same as any other
     * non-allow-listed route (00-core 9.2).
     */
    public function mount(): void
    {
        $user = auth()->user();

        if ($user !== null && ($user->hasRole(Role::Guardian->value) || $user->hasRole(Role::StaffPortal->value))) {
            abort(403);
        }
    }

    /**
     * Which permission a health alert requires before it is worth showing.
     *
     * An alert nobody in the room can act on is noise, and noise is how a
     * dashboard trains its readers to ignore it. Keys absent from this map are
     * shown to EVERYONE on purpose: a check that fails in a way we did not
     * anticipate - including CollectHealth's own `check.error` fallback - must
     * surface rather than be silently swallowed by a missing entry.
     *
     * @var array<string, Permission>
     */
    private const ALERT_PERMISSIONS = [
        'backup.recency' => Permission::BackupRun,
        'backup.second_target' => Permission::SettingEdit,
        'drill.recency' => Permission::BackupRestore,
        'disk.free' => Permission::BackupRun,
        'migrations.pending' => Permission::BackupRun,
        'mysql.durability' => Permission::SettingEdit,
        'queue.heartbeat' => Permission::SettingEdit,
        'queue.failed_jobs' => Permission::SettingEdit,
        'audit.chain' => Permission::AuditView,
        // CollectHealth's own fallback, and the reason a Teacher used to see
        // the words "LedgerIntegrityCheck" and "This action is unauthorized"
        // on their landing screen: LedgerIntegrityCheck runs
        // VerifyLedgerIntegrity, which Gate::authorize's ledger.view, so for
        // any signed-in user without it the check THROWS and is wrapped as
        // `check.error` carrying the class name and the exception message.
        // A check that could not run is an operator fact - the same
        // reasoning as every other row in this map - so it goes to whoever
        // can act on it, and stops being a raw stack-trace fragment rendered
        // at a teacher.
        'check.error' => Permission::SettingView,
    ];

    /**
     * @return list<HealthCheckResult>
     */
    private function alerts(CollectHealth $health): array
    {
        return array_values(array_filter(
            $health->handle(),
            function (HealthCheckResult $result): bool {
                if ($result->status === HealthStatus::Ok) {
                    return false;
                }

                $required = self::ALERT_PERMISSIONS[$result->key] ?? null;

                return $required === null || Gate::allows($required->value);
            },
        ));
    }

    /**
     * Age of the newest healthy backup, or null when there has never been one.
     *
     * Null is NOT zero. "No backup has ever completed" is the single most
     * important fact this screen can carry, and rendering it as 0 would bury it
     * (09-ui 3.3).
     */
    private function lastBackupAge(): ?string
    {
        $backup = Backup::query()
            ->healthy()
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->first();

        return $backup?->completed_at?->diffForHumans();
    }

    /**
     * "Signed in as {name} · {role}".
     *
     * Purely informational, but it is what makes a role demonstration
     * legible: the audience can see WHICH identity is producing the filtered
     * screen in front of them. Falls back to the raw role name if a role is
     * present that the enum does not know (a hand-granted custom role).
     *
     * @return array{name: string, role: string|null}|null
     */
    private function signedInAs(): ?array
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $roleName = null;
        $first = $user->getRoleNames()->first();

        if (is_string($first)) {
            $roleName = Role::tryFrom($first)?->label() ?? $first;
        }

        $name = $user->getAttribute('name');

        return [
            'name' => is_string($name) ? $name : '',
            'role' => $roleName,
        ];
    }


    /**
     * The signed-in user's roles, for dashboard composition.
     *
     * A user holding several roles gets the UNION of their panels, capped at
     * six and de-duplicated in panel order: a vice-principal who also teaches
     * needs both halves, and picking one role arbitrarily would hide half
     * their job from them.
     *
     * @return list<Role>
     */
    private function dashboardRoles(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $roles = [];

        foreach ($user->getRoleNames() as $name) {
            $role = Role::tryFrom((string) $name);

            if ($role !== null) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * The panels this user actually sees: their roles' union, permission
     * filtered by ReadDashboardPanels (which returns null for a panel the
     * caller may not read), capped at six.
     *
     * @return list<array{key: string, value: int|string|null, sub: string|null, tone: string, icon: string, route: string|null}>
     */
    private function rolePanels(ReadDashboardPanels $reader): array
    {
        $keys = [];

        foreach ($this->dashboardRoles() as $role) {
            foreach (RoleDashboard::for($role)['panels'] as $panel) {
                if (! in_array($panel, $keys, true)) {
                    $keys[] = $panel;
                }
            }
        }

        $panels = [];

        foreach ($keys as $key) {
            $panel = $reader->read($key);

            if ($panel === null) {
                continue;
            }

            // The backup clock is a duration, not a count, and it is this
            // component that owns the Backup model - so the reader leaves the
            // value null and it is filled in here rather than duplicating the
            // query across a module boundary.
            if ($key === 'last_backup') {
                $panel['value'] = $this->lastBackupAge();
            }

            $panels[] = $panel;

            // Six is the point past which the eye stops reading a KPI strip
            // and starts scanning it.
            if (count($panels) === 6) {
                break;
            }
        }

        return $panels;
    }

    /**
     * The quick actions this user can actually perform, from their roles'
     * union.
     *
     * Both gates matter and are both applied: the PERMISSION, because a card
     * that 403s on click teaches the operator that the screen lies; and
     * Route::has, because a route that does not exist yet would 404 - and an
     * action offered by a role profile can outrun the module that provides
     * it.
     *
     * @return list<array{key: string, label: string, description: string, icon: string, url: string}>
     */
    private function quickActions(): array
    {
        $keys = [];

        foreach ($this->dashboardRoles() as $role) {
            foreach (RoleDashboard::for($role)['quick_actions'] as $action) {
                if (! in_array($action, $keys, true)) {
                    $keys[] = $action;
                }
            }
        }

        $visible = [];

        foreach ($keys as $key) {
            $action = RoleDashboard::quickAction($key);

            if ($action === null) {
                continue;
            }

            [$routeName, $permission] = $action;

            if (! Gate::allows($permission) || ! Route::has($routeName)) {
                continue;
            }

            $visible[] = [
                'key' => $key,
                'label' => (string) __('opes.dashboard.action_'.$key),
                'description' => (string) __('opes.dashboard.action_'.$key.'_description'),
                // NOT a lang key: an icon is not a translation.
                'icon' => RoleDashboard::quickActionIcon($key),
                'url' => route($routeName, absolute: false),
            ];
        }

        return $visible;
    }

    public function render(CollectHealth $health, ReadDashboardPanels $panelReader): mixed
    {
        return view('livewire.dashboard', [
            'panels' => $this->rolePanels($panelReader),
            'alerts' => $this->alerts($health),
            'quickActions' => $this->quickActions(),
            'signedInAs' => $this->signedInAs(),
            // Kept: this feeds the health panel's display slot, which carries
            // a status pill rather than a numeral.
            'healthSummary' => Gate::allows(Permission::SettingView->value) ? $health->summary() : null,
        ]);
    }
}

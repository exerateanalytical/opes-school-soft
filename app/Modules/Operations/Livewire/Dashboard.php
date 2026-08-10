<?php

declare(strict_types=1);

namespace App\Modules\Operations\Livewire;

use App\Modules\Identity\Actions\CountActiveUsers;
use App\Modules\Identity\Actions\CountConfiguredRoles;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Operations\Actions\CollectHealth;
use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Models\Backup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The landing screen, docs/specs/09-ui.md section 3.
 *
 * Only tiles whose data actually exists are shown. The rest of section 3
 * describes enrolment, attendance and fee tiles that have no source yet, and a
 * tile reading 0 because its module is unbuilt is a lie the operator cannot
 * detect (09-ui 3.3). They arrive with their modules.
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
     * "Today's Attendance", 09-ui §3.3: present ÷ expected from TODAY's
     * submitted/amended registers only — draft registers do not count, the
     * same rule the Attendance Management screen itself uses
     * (Attendance\Livewire\Index::render()). Read cross-module via
     * DB::table (00-core 6.2: never another module's Model), formatted here
     * rather than handed back as a bare float so the view never has to
     * decide between "—" and "0%" itself.
     *
     * NULL — not "0%" — when the school has taken no register yet today
     * (07-students §9, C5): zero registers is "not yet taken", not "nobody
     * came".
     */
    private function todaysAttendanceRate(): ?string
    {
        $today = now()->toDateString();

        $registerIds = DB::table('attendance_registers')
            ->whereDate('date', $today)
            ->whereIn('status', ['submitted', 'amended'])
            ->pluck('id');

        if ($registerIds->isEmpty()) {
            return null;
        }

        $totals = DB::table('attendance_registers')
            ->whereIn('id', $registerIds)
            ->selectRaw('SUM(expected_count) as expected, SUM(present_count) as present, SUM(late_count) as late')
            ->first();

        // §9.6's formula, the same one Attendance\Livewire\Index and
        // GetAttendanceRateForEnrollments use: (present + late) over
        // (expected − suspended-rows), never plain expected.
        $suspended = DB::table('attendance_records')
            ->whereIn('attendance_register_id', $registerIds)
            ->where('status', 'suspended')
            ->count();

        // MySQL SUM() returns strings — cast.
        $expected = (int) ($totals->expected ?? 0);
        $denominator = $expected - $suspended;

        if ($denominator <= 0) {
            return null;
        }

        $present = (int) ($totals->present ?? 0);
        $late = (int) ($totals->late ?? 0);

        return number_format((($present + $late) / $denominator) * 100, 1).'%';
    }

    /**
     * @return list<array{key: string, label: string, route: string|null}>
     */
    private function quickActions(): array
    {
        $actions = [
            ['key' => 'add_user', 'permission' => Permission::UserManage, 'route' => '/users'],
            ['key' => 'take_backup', 'permission' => Permission::BackupRun, 'route' => null],
            ['key' => 'check_health', 'permission' => null, 'route' => '/up'],
        ];

        $visible = [];

        foreach ($actions as $action) {
            $permission = $action['permission'];

            if ($permission instanceof Permission && ! Gate::allows($permission->value)) {
                continue;
            }

            $visible[] = [
                'key' => $action['key'],
                'label' => (string) __('opes.dashboard.action_'.$action['key']),
                'route' => $action['route'],
            ];
        }

        return $visible;
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

    public function render(
        CollectHealth $health,
        CountActiveUsers $countUsers,
        CountConfiguredRoles $countRoles,
    ): mixed {
        $canViewAttendance = Gate::allows(Permission::AttendanceView->value);
        // The identity tiles (active users, roles configured) are identity
        // data, so they ride the same permission as the /users screen they
        // summarise. A Teacher or an Accountant has no business reading the
        // size and shape of the staff account list.
        $canViewUsers = Gate::allows(Permission::UserView->value);
        // System health and the backup clock are operator facts. Gated on the
        // permissions that let someone ACT on them - a red light nobody in
        // the room can act on is noise, the same reasoning the alert filter
        // above already uses.
        $canViewHealth = Gate::allows(Permission::SettingView->value);
        $canViewBackup = Gate::allows(Permission::BackupRun->value);

        $tileCount = ($canViewUsers ? 2 : 0)
            + ($canViewHealth ? 1 : 0)
            + ($canViewBackup ? 1 : 0)
            + ($canViewAttendance ? 1 : 0);

        return view('livewire.dashboard', [
            // Only computed for someone who may actually see it - never
            // query a figure the view is about to hide anyway.
            'activeUsers' => $canViewUsers ? $countUsers->handle() : null,
            'roleCount' => $canViewUsers ? $countRoles->handle() : null,
            'healthSummary' => $canViewHealth ? $health->summary() : null,
            'lastBackupAge' => $canViewBackup ? $this->lastBackupAge() : null,
            'alerts' => $this->alerts($health),
            'quickActions' => $this->quickActions(),
            'canViewUsers' => $canViewUsers,
            'canViewHealth' => $canViewHealth,
            'canViewBackup' => $canViewBackup,
            'canViewAttendance' => $canViewAttendance,
            // Only queried for someone who may actually see it - never
            // compute a figure the view is about to hide anyway.
            'todaysAttendanceRate' => $canViewAttendance ? $this->todaysAttendanceRate() : null,
            // Raw, not clamped: zero tiles means the whole strip is hidden
            // rather than rendering an empty grid with a stray gap.
            'tileCount' => $tileCount,
            'signedInAs' => $this->signedInAs(),
            // Built by another module and possibly not routed yet, so the
            // link only appears once the route genuinely exists AND the
            // viewer holds a finance read permission - never offer a
            // shortcut that lands on a 403.
            'financeDashboardUrl' => \Illuminate\Support\Facades\Route::has('finance.dashboard')
                && (Gate::allows(Permission::LedgerView->value) || Gate::allows(Permission::FeeView->value))
                    ? route('finance.dashboard', absolute: false)
                    : null,
        ]);
    }
}

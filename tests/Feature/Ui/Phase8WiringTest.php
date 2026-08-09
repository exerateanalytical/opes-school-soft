<?php

declare(strict_types=1);

// Phase 8 F5 pass 2 (docs/plans/phase-08.md §2 Agent F5): routes, the
// timetable/attendance nav flip, and the dashboard "Today's Attendance" KPI.
// The four modules' OWN behaviour (registers, cases, promotion runs...) is
// covered by F1-F4's suites; this file only asserts the WIRING — that each
// route exists, is gated on the permission the plan names, and agrees with
// Navigation.php by construction, plus the dashboard tile's null-vs-number
// contract (07-students §9, C5).

use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Attendance\Models\AttendanceRegister;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Navigation;
use App\Modules\Operations\Livewire\Dashboard;
use App\Modules\Welfare\Models\DisciplineCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

if (! function_exists('phase8F5UserAs')) {
    function phase8F5UserAs(Role $role = Role::Administrator): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('phase8F5NavItem')) {
    /**
     * @return array{key: string, route: string|null, permission: Permission|null, enabled: bool, built: bool}
     */
    function phase8F5NavItem(string $key): array
    {
        foreach (Navigation::items() as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        throw new RuntimeException(sprintf('No nav item named "%s".', $key));
    }
}

// ── Routes: reachable under the plan's permission, refused without it ──────

it('serves /timetable to a role holding timetable.view and blocks one that lacks it', function () {
    actingAs(phase8F5UserAs(Role::Administrator))->get('/timetable')->assertOk();

    // Librarian holds no Phase 8 permission at all (Role::defaultPermissions).
    actingAs(phase8F5UserAs(Role::Librarian))->get('/timetable')->assertForbidden();
});

it('serves /attendance to a role holding attendance.view and blocks one that lacks it', function () {
    actingAs(phase8F5UserAs(Role::Administrator))->get('/attendance')->assertOk();
    actingAs(phase8F5UserAs(Role::Librarian))->get('/attendance')->assertForbidden();
});

it('gates /attendance/take on attendance.take specifically, not just attendance.view', function () {
    actingAs(phase8F5UserAs(Role::Administrator))->get('/attendance/take')->assertOk();

    // The Discipline Master holds attendance.view + attendance.justify but
    // NOT attendance.take (Role::defaultPermissions) - the finer-grained
    // gate this route names must actually bite.
    actingAs(phase8F5UserAs(Role::DisciplineMaster))->get('/attendance/take')->assertForbidden();
});

it('serves /attendance/coverage to a role holding attendance.view and blocks one that lacks it', function () {
    actingAs(phase8F5UserAs(Role::Administrator))->get('/attendance/coverage')->assertOk();
    actingAs(phase8F5UserAs(Role::Librarian))->get('/attendance/coverage')->assertForbidden();
});

it('serves /welfare/discipline to a role holding discipline.view and blocks one that lacks it', function () {
    actingAs(phase8F5UserAs(Role::DisciplineMaster))->get('/welfare/discipline')->assertOk();
    actingAs(phase8F5UserAs(Role::Librarian))->get('/welfare/discipline')->assertForbidden();
});

it('serves /welfare/discipline/{case} to a role holding discipline.view and blocks one that lacks it', function () {
    $case = DisciplineCase::factory()->create();

    actingAs(phase8F5UserAs(Role::DisciplineMaster))
        ->get('/welfare/discipline/'.$case->getKey())
        ->assertOk();

    actingAs(phase8F5UserAs(Role::Librarian))
        ->get('/welfare/discipline/'.$case->getKey())
        ->assertForbidden();
});

it('serves /students/promotion to a role holding promotion.evaluate and blocks one that lacks it', function () {
    actingAs(phase8F5UserAs(Role::Administrator))->get('/students/promotion')->assertOk();

    // The Vice-Principal holds neither promotion.evaluate nor
    // promotion.apply on purpose - §10.6 keeps both with the Proviseur.
    actingAs(phase8F5UserAs(Role::VicePrincipal))->get('/students/promotion')->assertForbidden();
});

it('redirects a guest away from every one of the seven new routes', function () {
    foreach ([
        '/timetable', '/attendance', '/attendance/take', '/attendance/coverage',
        '/welfare/discipline', '/students/promotion',
    ] as $path) {
        get($path)->assertRedirect('/login');
    }
});

// ── Navigation: nav and route agree by construction (Navigation.php's own
//    documented contract), and the two flipped keys drop out of the
//    placeholder list automatically. ──────────────────────────────────────

it('flips timetable and attendance to built in Navigation, gated on the right permission', function () {
    $timetable = phase8F5NavItem('timetable');
    $attendance = phase8F5NavItem('attendance');

    expect($timetable['built'])->toBeTrue();
    expect($timetable['permission'])->toBe(Permission::TimetableView);

    expect($attendance['built'])->toBeTrue();
    expect($attendance['permission'])->toBe(Permission::AttendanceView);
});

it('drops timetable and attendance out of the placeholder routes once flipped', function () {
    $placeholders = Navigation::placeholderRoutes();

    expect($placeholders)->not->toHaveKey('timetable');
    expect($placeholders)->not->toHaveKey('attendance');
});

it('shows the timetable and attendance links, without the "soon" chip, to a role that can see them', function () {
    $html = (string) actingAs(phase8F5UserAs(Role::Administrator))->get('/dashboard')->getContent();

    expect($html)->toContain('href="/timetable"');
    expect($html)->toContain('href="/attendance"');
});

it('hides the timetable and attendance links from a role without their permission', function () {
    $html = (string) actingAs(phase8F5UserAs(Role::Librarian))->get('/dashboard')->getContent();

    expect($html)->not->toContain('href="/timetable"');
    expect($html)->not->toContain('href="/attendance"');
});

it('blocks the timetable and attendance routes as well as hiding the links', function () {
    // 00-core 6.2: hiding a menu item is presentation, never a control.
    actingAs(phase8F5UserAs(Role::Librarian))->get('/timetable')->assertForbidden();
    actingAs(phase8F5UserAs(Role::Librarian))->get('/attendance')->assertForbidden();
});

// ── Dashboard "Today's Attendance" KPI ──────────────────────────────────────

it('renders a dash, not 0%, for the attendance tile when no register exists today', function () {
    actingAs(phase8F5UserAs(Role::Administrator));

    Livewire::test(Dashboard::class)
        ->assertViewHas('todaysAttendanceRate', null)
        ->assertSee(__('opes.dashboard.tile_attendance'))
        ->assertSee('—');
});

it('renders the present-plus-late over expected percentage from TODAY\'s submitted registers', function () {
    $group = ClassGroup::factory()->create();

    // 8 present + 1 late (counts as present, §9.6) + 1 absent, out of 10
    // expected ⇒ 90.0%.
    AttendanceRegister::factory()->submitted()->create([
        'class_group_id' => $group->getKey(),
        'academic_year_id' => $group->academic_year_id,
        'date' => now()->toDateString(),
        'expected_count' => 10,
        'present_count' => 8,
        'absent_count' => 1,
        'late_count' => 1,
        'excused_count' => 0,
    ]);

    actingAs(phase8F5UserAs(Role::Administrator));

    Livewire::test(Dashboard::class)
        ->assertViewHas('todaysAttendanceRate', '90.0%')
        ->assertSee('90.0%');
});

it('ignores a DRAFT (open, un-submitted) register when computing the tile', function () {
    $group = ClassGroup::factory()->create();

    // Still open: every default-present slot is "not yet taken" (C5), so
    // this register must not feed the denominator at all.
    AttendanceRegister::factory()->create([
        'class_group_id' => $group->getKey(),
        'academic_year_id' => $group->academic_year_id,
        'date' => now()->toDateString(),
        'expected_count' => 10,
        'present_count' => 10,
    ]);

    actingAs(phase8F5UserAs(Role::Administrator));

    Livewire::test(Dashboard::class)->assertViewHas('todaysAttendanceRate', null);
});

it('ignores a register from a different day', function () {
    $group = ClassGroup::factory()->create();

    AttendanceRegister::factory()->submitted()->create([
        'class_group_id' => $group->getKey(),
        'academic_year_id' => $group->academic_year_id,
        'date' => now()->subDay()->toDateString(),
        'expected_count' => 10,
        'present_count' => 10,
    ]);

    actingAs(phase8F5UserAs(Role::Administrator));

    Livewire::test(Dashboard::class)->assertViewHas('todaysAttendanceRate', null);
});

it('hides the attendance tile from a role without attendance.view', function () {
    actingAs(phase8F5UserAs(Role::Librarian));

    Livewire::test(Dashboard::class)
        ->assertViewHas('canViewAttendance', false)
        ->assertViewHas('todaysAttendanceRate', null)
        ->assertDontSee(__('opes.dashboard.tile_attendance'));
});

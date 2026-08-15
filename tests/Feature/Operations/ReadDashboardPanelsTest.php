<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Operations\Actions\ReadDashboardPanels;
use App\Modules\Operations\Domain\RoleDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Reporting/P13CoreHelpers.php';

uses(RefreshDatabase::class);

it('returns a value, label key, tone, icon and route for a panel', function (): void {
    p13coreUserAs(Role::Principal);

    $panel = app(ReadDashboardPanels::class)->read('enrolment_count');

    expect($panel)->toHaveKeys(['key', 'value', 'sub', 'tone', 'icon', 'route']);
});

it('returns NULL as the value where nothing has been recorded, never zero', function (): void {
    // 09-ui 3.3: "no fee has been collected" and "the figure has not been
    // recorded" are different facts. A dashboard that prints 0 for the second
    // is lying, and it is the kind of lie nobody detects.
    p13coreUserAs(Role::Bursar);

    expect(app(ReadDashboardPanels::class)->read('todays_collections')['value'])->toBeNull();
});

it('returns null for a panel the caller may not see', function (): void {
    p13coreUserAs(Role::Teacher);

    expect(app(ReadDashboardPanels::class)->read('unposted_entries'))->toBeNull();
});

it('returns null for an unknown panel rather than throwing', function (): void {
    p13coreUserAs(Role::Principal);

    expect(app(ReadDashboardPanels::class)->read('not_a_panel'))->toBeNull();
});

it('reads every panel any role can be given without throwing', function (): void {
    // The whole risk of this Action is a wrong table or column name in one of
    // ~45 cross-module reads. This test walks all of them.
    p13coreUserAs(Role::SuperAdmin);

    $reader = app(ReadDashboardPanels::class);

    foreach (RoleDashboard::allPanels() as $panel) {
        $reader->read($panel);
    }
})->throwsNoExceptions();

it('gives every panel a tone from the semantic set', function (): void {
    // Four identical-looking cards with four different badge colours is the
    // bug the tone system was added to fix; an unmapped tone silently falls
    // back to green in x-kpi-card and reintroduces it.
    p13coreUserAs(Role::SuperAdmin);

    $reader = app(ReadDashboardPanels::class);

    foreach (RoleDashboard::allPanels() as $panel) {
        $read = $reader->read($panel);

        if ($read !== null) {
            expect(['green', 'blue', 'amber', 'pink', 'purple'])->toContain($read['tone']);
        }
    }
});

it('explains WHY a panel is blank rather than leaving the generic sub-line under a dash', function (): void {
    // Task 44 caught this by looking: a Teacher with no staff record saw three
    // cards reading "—" under sub-lines that described the metric and said
    // nothing about the reason. A dash with no explanation reads as a broken
    // page, which is precisely the "made by an amateur" complaint.
    p13coreUserAs(Role::Teacher);

    foreach (['my_classes', 'my_timetable_today', 'registers_not_taken'] as $panel) {
        $read = app(ReadDashboardPanels::class)->read($panel);

        expect($read['value'])->toBeNull()
            ->and($read['sub'])->not->toBeNull();
    }
});

it('reads the last healthy backup instead of always printing a dash', function (): void {
    p13coreUserAs(Role::SuperAdmin);

    DB::table('backups')->insert([
        'kind' => 'full',
        'status' => 'completed',
        'path' => 'backups/one.sql.gz',
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(30),
        'verified_at' => now()->subMinutes(20),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(ReadDashboardPanels::class)->read('last_backup')['value'])->not->toBeNull();
});

it('says no backup has ever completed rather than showing a bare dash', function (): void {
    p13coreUserAs(Role::SuperAdmin);

    $read = app(ReadDashboardPanels::class)->read('last_backup');

    expect($read['value'])->toBeNull()
        ->and($read['sub'])->not->toBeNull();
});

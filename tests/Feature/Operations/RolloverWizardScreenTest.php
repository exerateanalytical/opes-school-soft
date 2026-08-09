<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Livewire\RolloverWizard;
use App\Modules\Operations\Models\Backup;
use App\Modules\Operations\Models\RolloverRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * P7-F5 screen tests: the /operations/rollover wizard, the /settings/licence
 * route, the sidebar wiring, and the dashboard's "What's open right now"
 * panel (08-operations §6.4). Helpers are function_exists-guarded and
 * prefixed p7f5 (HANDOVER standing rule).
 */

if (! function_exists('p7f5User')) {
    function p7f5User(Role $role = Role::Administrator): User
    {
        (new Database\Seeders\RolePermissionSeeder())->run();

        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('p7f5Year')) {
    /** A far-future outgoing year with nothing blocking pre-flight. */
    function p7f5Year(): int
    {
        return (int) DB::table('academic_years')->insertGetId([
            'code' => '2140-2141-'.Str::lower(Str::random(4)),
            'name' => 'Academic Year 2140/2141',
            'starts_on' => '2140-09-01',
            'ends_on' => '2141-08-31',
            'is_current' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('p7f5Backup')) {
    /** A verified healthy backup - step 0's mandatory precondition. */
    function p7f5Backup(): Backup
    {
        return Backup::query()->create([
            'kind' => 'full',
            'status' => 'healthy',
            'path' => '/tmp/p7f5-backup.sql',
            'sha256' => str_repeat('b', 64),
            'started_at' => now(),
            'completed_at' => now(),
            'verified_at' => now(),
        ]);
    }
}

// ---------------------------------------------------------------- routes

it('redirects a guest away from the rollover wizard', function () {
    get('/operations/rollover')->assertRedirect('/login');
});

it('blocks the rollover route without rollover.run', function () {
    actingAs(p7f5User(Role::Bursar))->get('/operations/rollover')->assertForbidden();
    actingAs(p7f5User(Role::Teacher))->get('/operations/rollover')->assertForbidden();
});

it('opens the wizard for an administrator and shows the eleven stations', function () {
    actingAs(p7f5User())->get('/operations/rollover')
        ->assertOk()
        ->assertSee(__('rollover.wizard.title'))
        ->assertSee(__('rollover.step.0'))
        ->assertSee(__('rollover.step.10'));
});

it('shows the operations nav item to a holder of rollover.run and hides it otherwise', function () {
    // The nav and the route agree by construction (Navigation contract):
    // the Administrator baseline includes rollover.run, a Teacher's does not.
    expect((string) actingAs(p7f5User())->get('/dashboard')->getContent())
        ->toContain('href="/operations/rollover"');

    expect((string) actingAs(p7f5User(Role::Teacher))->get('/dashboard')->getContent())
        ->not->toContain('href="/operations/rollover"');
});

it('gates the licence panel on licence.manage, which a plain administrator lacks', function () {
    // AuthorizationMatrixTest's security property carried through to the
    // route: the plain Administrator is withheld licence.manage on purpose.
    actingAs(p7f5User())->get('/settings/licence')->assertForbidden();

    actingAs(p7f5User(Role::SuperAdmin))->get('/settings/licence')
        ->assertOk()
        ->assertSee(__('licence.panel.title'));
});

// ---------------------------------------------------------------- component

it('refuses the wizard component itself without the permission', function () {
    actingAs(p7f5User(Role::Bursar));

    // A Livewire component can be reached without its route, so mount()
    // re-checks the gate on its own.
    Livewire::test(RolloverWizard::class)->assertForbidden();
});

it('runs pre-flight and opens a run from the screen', function () {
    actingAs(p7f5User());
    $yearId = p7f5Year();
    $backup = p7f5Backup();

    $component = Livewire::test(RolloverWizard::class)
        ->set('fromYearId', (string) $yearId)
        ->set('backupId', (string) $backup->getKey())
        ->call('start')
        ->assertHasNoErrors();

    $run = RolloverRun::query()->where('academic_year_from_id', $yearId)->first();

    expect($run)->not->toBeNull();
    expect($component->get('runId'))->toBe((string) $run?->getKey());
    expect($run?->current_step)->toBe(1);
    expect($component->get('errorMessage'))->toBe('');
});

it('refuses to start without a verified backup, naming the refusal', function () {
    actingAs(p7f5User());
    $yearId = p7f5Year();

    $unverified = Backup::query()->create([
        'kind' => 'full',
        'status' => 'pending',
        'path' => '/tmp/p7f5-unverified.sql',
        'started_at' => now(),
    ]);

    $component = Livewire::test(RolloverWizard::class)
        ->set('fromYearId', (string) $yearId)
        ->set('backupId', (string) $unverified->getKey())
        ->call('start');

    expect($component->get('errorMessage'))->toContain('not verified');
    expect(RolloverRun::query()->count())->toBe(0);
});

it('applies step 1 from the screen and advances the run', function () {
    actingAs(p7f5User());
    $yearId = p7f5Year();
    $backup = p7f5Backup();

    $component = Livewire::test(RolloverWizard::class)
        ->set('fromYearId', (string) $yearId)
        ->set('backupId', (string) $backup->getKey())
        ->call('start')
        ->set('newYearCode', '2141-2142')
        ->set('newYearName', 'Academic Year 2141/2142')
        ->call('apply');

    expect($component->get('errorMessage'))->toBe('');

    $run = RolloverRun::query()->where('academic_year_from_id', $yearId)->firstOrFail();

    expect($run->current_step)->toBe(2);
    expect($run->academic_year_to_id)->not->toBeNull();

    // Contiguity is the delegated Academics rule: the new year starts the
    // day after the outgoing one ends (§6.2 step 1).
    $to = DB::table('academic_years')->where('id', $run->academic_year_to_id)->first();
    expect((string) $to?->starts_on)->toBe('2141-09-01');
});

it('resumes a run from the query string after a reload', function () {
    actingAs(p7f5User());
    $yearId = p7f5Year();
    $backup = p7f5Backup();

    Livewire::test(RolloverWizard::class)
        ->set('fromYearId', (string) $yearId)
        ->set('backupId', (string) $backup->getKey())
        ->call('start');

    $run = RolloverRun::query()->where('academic_year_from_id', $yearId)->firstOrFail();

    // Driven through the REAL page load (as the Admissions wizard test is):
    // a reload is exactly what is being tested - a fresh HTTP request that
    // has only the query string and the row to work from (§6.3).
    get('/operations/rollover?run='.$run->getKey())
        ->assertOk()
        // Step 1's own inputs, so this asserts the STEP resumed and not
        // merely that the row was found.
        ->assertSee(__('rollover.wizard.new_year_code'))
        ->assertSee(__('rollover.wizard.run_label', ['id' => $run->getKey()]));
});

it('offers the resumable run when the wizard opens without one', function () {
    actingAs(p7f5User());
    $yearId = p7f5Year();
    $backup = p7f5Backup();

    Livewire::test(RolloverWizard::class)
        ->set('fromYearId', (string) $yearId)
        ->set('backupId', (string) $backup->getKey())
        ->call('start');

    $run = RolloverRun::query()->where('academic_year_from_id', $yearId)->firstOrFail();

    Livewire::test(RolloverWizard::class)
        ->assertSee(__('rollover.wizard.resume'))
        ->call('resume', (int) $run->getKey())
        ->assertSet('runId', (string) $run->getKey());
});

// ---------------------------------------------------------------- §6.4 panel

it('shows the whats-open panel to the money-and-leadership roles', function () {
    $yearId = p7f5Year();
    DB::table('academic_years')->where('id', $yearId)->update(['is_current' => true]);

    foreach ([Role::Administrator, Role::Bursar] as $role) {
        actingAs(p7f5User($role))->get('/dashboard')
            ->assertOk()
            ->assertSee(__('opes.whats_open.title'))
            ->assertSee(__('opes.whats_open.marks_closed'));
    }
});

it('hides the whats-open panel from roles outside the spec list', function () {
    // §6.4 names Bursar, Accountant, Principal, Administrator. A Teacher
    // holds neither fee.view nor ledger.view, so the panel renders nothing.
    actingAs(p7f5User(Role::Teacher))->get('/dashboard')
        ->assertOk()
        ->assertDontSee(__('opes.whats_open.title'));
});

it('renders honest absences on the whats-open panel, never invented zeros', function () {
    // No fiscal year, no accounting period, no assessment period: the panel
    // says so in words (09-ui 3.3 - null is not zero).
    actingAs(p7f5User())->get('/dashboard')
        ->assertOk()
        ->assertSee(__('opes.whats_open.no_exercice'))
        ->assertSee(__('opes.whats_open.no_period'))
        ->assertSee(__('opes.whats_open.marks_closed'));
});

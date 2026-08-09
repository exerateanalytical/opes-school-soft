<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Navigation;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

if (! function_exists('feesScreensUser')) {
    /** A signed-in user holding the given role's default permissions. */
    function feesScreensUser(Role $role = Role::Bursar): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

// ── The cashier screen at /finance ──────────────────────────────────────

it('renders the cashier screen for a role with fee.view', function () {
    actingAs(feesScreensUser(Role::Bursar))->get('/finance')
        ->assertOk()
        ->assertSee(__('opes.fees_screen.cashier_title'))
        ->assertSee(__('opes.fees_screen.select_student'));
});

it('renders the cashier screen for the read-only principal too', function () {
    // The SCREEN is reachable under fee.view; the ACT of collecting is gated
    // fee.collect inside the component. A Principal may read fee data.
    actingAs(feesScreensUser(Role::Principal))->get('/finance')
        ->assertOk()
        ->assertSee(__('opes.fees_screen.cashier_title'));
});

it('blocks /finance for a role without fee.view', function () {
    actingAs(feesScreensUser(Role::Teacher))->get('/finance')->assertForbidden();
});

it('no longer serves the scheduled-module placeholder at /finance', function () {
    // Phase 6 flipped finance to built: the real cashier screen took over the
    // exact URL the placeholder held, so bookmarks survive the module landing.
    expect(Navigation::placeholderRoutes())->not->toHaveKey('finance');

    actingAs(feesScreensUser(Role::Administrator))->get('/finance')
        ->assertOk()
        ->assertDontSee(__('opes.placeholder.chip'));
});

// ── The invoices list at /finance/invoices ──────────────────────────────

it('renders the invoices list for a role with fee.view', function () {
    actingAs(feesScreensUser(Role::Bursar))->get('/finance/invoices')
        ->assertOk()
        ->assertSee(__('opes.fees_screen.invoices_title'));
});

it('blocks /finance/invoices for a role without fee.view', function () {
    actingAs(feesScreensUser(Role::Teacher))->get('/finance/invoices')->assertForbidden();
});

// ── The student statement ───────────────────────────────────────────────

it('renders the statement for a role with fee.view', function () {
    $user = feesScreensUser(Role::Accountant);
    $student = Student::factory()->create();

    actingAs($user)->get("/finance/students/{$student->id}/statement")
        ->assertOk()
        ->assertSee($student->first_name);
});

it('blocks the statement for a role without fee.view', function () {
    $user = feesScreensUser(Role::Teacher);
    $student = Student::factory()->create();

    actingAs($user)->get("/finance/students/{$student->id}/statement")->assertForbidden();
});

it('answers 404 for a statement of a student that does not exist', function () {
    actingAs(feesScreensUser(Role::Bursar))->get('/finance/students/999999/statement')
        ->assertNotFound();
});

// ── The sidebar ─────────────────────────────────────────────────────────

it('shows the finance link to a role with fee.view', function () {
    $html = (string) actingAs(feesScreensUser(Role::Bursar))->get('/dashboard')->getContent();

    expect($html)->toContain('href="/finance"');
});

it('hides the finance link from a role without fee.view', function () {
    // Hiding is courtesy; the middleware test above is the control.
    $html = (string) actingAs(feesScreensUser(Role::Teacher))->get('/dashboard')->getContent();

    expect($html)->not->toContain('href="/finance"');
});

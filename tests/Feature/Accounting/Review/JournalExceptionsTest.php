<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\Review\JournalExceptions;
use App\Modules\Accounting\Livewire\Review\Journals;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/**
 * Journal review worklist,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.3.
 */
function journalExceptionsUser(Role $role = Role::Accountant): User
{
    (new Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('counts a draft entry as draft', function () {
    actingAs(journalExceptionsUser());

    JournalEntry::factory()->create(['status' => JournalEntry::STATUS_DRAFT]);

    expect(app(JournalExceptions::class)->counts()['draft'])->toBeGreaterThanOrEqual(1);
});

it('counts a posted entry with no posting rule as manual', function () {
    actingAs(journalExceptionsUser());

    // ck_je_piece_when_posted: a posted entry must carry its sequence number.
    JournalEntry::factory()->create([
        'status' => JournalEntry::STATUS_POSTED,
        'piece_no' => 'JX/TEST/000001',
        'posting_rule_id' => null,
    ]);

    expect(app(JournalExceptions::class)->counts()['manual'])->toBeGreaterThanOrEqual(1);
});

it('counts a forward-posted entry', function () {
    actingAs(journalExceptionsUser());

    JournalEntry::factory()->create([
        'status' => JournalEntry::STATUS_POSTED,
        'piece_no' => 'JX/TEST/000002',
        'is_forward_posted' => true,
    ]);

    expect(app(JournalExceptions::class)->counts()['forward_posted'])->toBeGreaterThanOrEqual(1);
});

it('does not count a draft entry among the posted categories', function () {
    actingAs(journalExceptionsUser());

    // A draft has no posting rule either, but it is not a MANUAL POSTED entry -
    // the manual filter reads through postedLedger() precisely so an unposted
    // draft cannot inflate it.
    $before = app(JournalExceptions::class)->counts()['manual'];

    JournalEntry::factory()->create([
        'status' => JournalEntry::STATUS_DRAFT,
        'posting_rule_id' => null,
    ]);

    expect(app(JournalExceptions::class)->counts()['manual'])->toBe($before);
});

it('returns every filter the screen offers', function () {
    actingAs(journalExceptionsUser());

    expect(array_keys(app(JournalExceptions::class)->counts()))
        ->toBe(JournalExceptions::FILTERS);
});

it('refuses without ledger.view', function () {
    actingAs(journalExceptionsUser(Role::Teacher));

    app(JournalExceptions::class)->counts();
})->throws(Illuminate\Auth\Access\AuthorizationException::class);

it('renders the worklist for an accountant', function () {
    actingAs(journalExceptionsUser());

    Livewire::test(Journals::class)->assertOk();
});

it('refuses a teacher at the route, not merely in the sidebar', function () {
    actingAs(journalExceptionsUser(Role::Teacher));

    get('/accounting/review/journals')->assertForbidden();
});

it('rejects a filter the worklist does not offer', function () {
    actingAs(journalExceptionsUser());

    Livewire::test(Journals::class)
        ->set('filter', 'everything')
        ->assertSet('filter', 'draft');
});

it('resolves the whole page in one batch, not once per row', function () {
    actingAs(journalExceptionsUser());

    JournalEntry::factory()->count(20)->create(['status' => JournalEntry::STATUS_DRAFT]);

    DB::enableQueryLog();
    Livewire::test(Journals::class)->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Bounded by registered resolvers and filter counts - never by row count.
    expect($queries)->toBeLessThan(40);
});

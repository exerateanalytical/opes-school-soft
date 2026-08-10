<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\Journal;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * 02-accounting §15, AUDCIF Art. 24 - no accounting record is hard-deleted
 * for 10 years from the end of the fiscal year it belongs to. Exercised
 * against Journal - a real model that now carries the trait - rather than a
 * purpose-built fixture, so the test proves the trait against actual
 * production code instead of a stand-in that might behave differently.
 */

it('refuses to delete a record still inside its 10-year retention window', function (): void {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    $journal = Journal::factory()->create();

    expect(fn () => $journal->delete())->toThrow(RuntimeException::class);

    expect(Journal::query()->find($journal->getKey()))->not->toBeNull();
});

it('allows deletion once the record is more than 10 years old', function (): void {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    $journal = Journal::factory()->create();

    DB::table('journals')
        ->where('id', $journal->getKey())
        ->update(['created_at' => now()->subYears(11)]);

    $journal->refresh()->delete();

    expect(Journal::query()->find($journal->getKey()))->toBeNull();
});

it('names the exact date retention expires in its refusal message', function (): void {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    $journal = Journal::factory()->create();
    $expected = now()->addYears(10)->toDateString();

    try {
        $journal->delete();
        $this->fail('Expected a RuntimeException.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain($expected);
    }
});

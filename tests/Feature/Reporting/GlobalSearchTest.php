<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Actions\Search\SearchThePlatform;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
 * The shell header's global search - was `disabled` with an honest
 * tooltip ("a search box that quietly swallows every query is worse than
 * no search box at all"); this is the real implementation.
 */

it('returns nothing for a query under two characters', function (): void {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    expect(app(SearchThePlatform::class)->handle('a'))->toBe([]);
});

it('finds a student by a partial last-name match', function (): void {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    $student = Student::factory()->create(['first_name' => 'Ferdinand', 'last_name' => 'Nkemtazouang']);

    $results = app(SearchThePlatform::class)->handle('Nkemta');

    $match = collect($results)->firstWhere('url', '/students/'.$student->getKey());

    expect($match)->not->toBeNull()
        ->and($match['group'])->toBe('students')
        ->and($match['label'])->toBe('Ferdinand Nkemtazouang');
});

it('never surfaces a source the caller has no permission to view', function (): void {
    (new \Database\Seeders\RolePermissionSeeder())->run();

    $teacher = User::factory()->create();
    $teacher->assignRole(Role::Teacher->value);
    Auth::setUser($teacher);

    // A teacher holds students.view and staff.view but not
    // procurement.view or fee.view - regardless of what the query string
    // happens to match, those two sources must never appear for them.
    $results = app(SearchThePlatform::class)->handle('a supplier or invoice search term');

    $groups = array_unique(array_column($results, 'group'));

    expect($groups)->not->toContain('suppliers')
        ->not->toContain('invoices');
});

it('treats a literal percent sign in the query as a literal character, not a wildcard', function (): void {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    Student::factory()->create(['first_name' => '100%', 'last_name' => 'Match']);
    Student::factory()->create(['first_name' => 'Should', 'last_name' => 'NotMatch']);

    $results = app(SearchThePlatform::class)->handle('100%');

    $labels = array_column($results, 'label');

    expect($labels)->toContain('100% Match')
        ->not->toContain('Should NotMatch');
});

it('caps results per source rather than returning everything that matches', function (): void {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    for ($i = 0; $i < 10; $i++) {
        Student::factory()->create(['first_name' => 'Zzcapcheck', 'last_name' => "Student{$i}"]);
    }

    $results = app(SearchThePlatform::class)->handle('Zzcapcheck');
    $studentResults = array_filter($results, fn (array $r): bool => $r['group'] === 'students');

    expect($studentResults)->toHaveCount(5);
});

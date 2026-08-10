<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\GenerateSystemDocumentation;
use App\Modules\Accounting\Models\SystemDocumentationSnapshot;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * 02-accounting §14.4 - the documentation du système comptable. Same
 * generate/hash/supersede shape as the statutory books (§14.1), and
 * verified against the same property: a regenerated snapshot supersedes
 * its predecessor rather than replacing it, and every recorded hash
 * matches what is actually on disk.
 */

it('generates a real PDF whose recorded hash matches the file on disk', function (): void {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    $snapshot = app(GenerateSystemDocumentation::class)->handle();

    expect(Storage::disk('local')->exists($snapshot->file_path))->toBeTrue();

    $actualHash = hash('sha256', Storage::disk('local')->get($snapshot->file_path));

    expect($actualHash)->toBe($snapshot->sha256)
        ->and($snapshot->sha256)->toHaveLength(64);
});

it('supersedes rather than replaces on regeneration', function (): void {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    $action = app(GenerateSystemDocumentation::class);

    $first = $action->handle();
    $second = $action->handle();

    expect($second->getKey())->not->toBe($first->getKey())
        ->and($second->supersedes_id)->toBe($first->getKey())
        ->and(SystemDocumentationSnapshot::find($first->getKey()))->not->toBeNull();
});

it('records the current schema version from the migrations table', function (): void {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    $latestMigration = (string) \Illuminate\Support\Facades\DB::table('migrations')
        ->orderByDesc('id')
        ->value('migration');

    $snapshot = app(GenerateSystemDocumentation::class)->handle();

    expect($snapshot->schema_version)->toBe($latestMigration);
});

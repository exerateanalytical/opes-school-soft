<?php

declare(strict_types=1);

use App\Modules\Academics\Actions\CreateSubject;
use App\Modules\Academics\Actions\UpdateSubject;
use App\Modules\Academics\Models\Subject;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

// Renamed from the Identity suite's userAs(): Pest test files share one
// global function namespace, so a same-named helper in two files is a fatal
// redeclaration when the files run together.
function subjectUserAs(Role $role): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('creates a subject and audits it', function () {
    actingAs(subjectUserAs(Role::VicePrincipal));

    $subject = app(CreateSubject::class)->handle(
        code: 'MATH',
        name: 'Mathematics',
        nameFr: 'Mathématiques',
    );

    expect($subject->exists)->toBeTrue()
        ->and($subject->code)->toBe('MATH')
        ->and($subject->name_fr)->toBe('Mathématiques')
        ->and($subject->department_id)->toBeNull()
        ->and($subject->is_active)->toBeTrue();

    expect(
        AuditLog::query()
            ->where('module', 'Academics')
            ->where('action', 'created')
            ->where('auditable_type', Subject::class)
            ->count()
    )->toBe(1);
});

it('rejects a duplicate subject code at the database', function () {
    actingAs(subjectUserAs(Role::VicePrincipal));
    Subject::factory()->create(['code' => 'MATH']);

    app(CreateSubject::class)->handle(code: 'MATH', name: 'Mathematics Again');
})->throws(\Illuminate\Database\UniqueConstraintViolationException::class);

it('updates a subject and audits before and after values', function () {
    actingAs(subjectUserAs(Role::VicePrincipal));
    $subject = Subject::factory()->create(['code' => 'PHY', 'name' => 'Fizics']);

    $updated = app(UpdateSubject::class)->handle($subject, ['name' => 'Physics']);

    expect($updated->name)->toBe('Physics');

    $entry = AuditLog::query()
        ->where('module', 'Academics')
        ->where('action', 'updated')
        ->where('auditable_type', Subject::class)
        ->firstOrFail();

    expect($entry->before)->toBe(['name' => 'Fizics'])
        ->and($entry->after)->toBe(['name' => 'Physics']);
});

it('writes no audit entry when an update changes nothing', function () {
    actingAs(subjectUserAs(Role::VicePrincipal));
    $subject = Subject::factory()->create(['name' => 'Physics']);

    app(UpdateSubject::class)->handle($subject, ['name' => 'Physics']);

    expect(
        AuditLog::query()->where('module', 'Academics')->where('action', 'updated')->count()
    )->toBe(0);
});

it('rejects subject creation by an actor without academics.manage', function () {
    // Teachers hold academics.view only (00-core 9.1): they read the
    // structure, the Censeur shapes it.
    actingAs(subjectUserAs(Role::Teacher));

    app(CreateSubject::class)->handle(code: 'CHEM', name: 'Chemistry');
})->throws(AuthorizationException::class);

it('rejects subject update by an actor without academics.manage', function () {
    actingAs(subjectUserAs(Role::VicePrincipal));
    $subject = Subject::factory()->create();

    actingAs(subjectUserAs(Role::Teacher));

    app(UpdateSubject::class)->handle($subject, ['name' => 'Renamed']);
})->throws(AuthorizationException::class);

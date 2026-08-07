<?php

declare(strict_types=1);

use App\Modules\Academics\Actions\AllocateSubject;
use App\Modules\Academics\Actions\UpdateAllocation;
use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Models\SubjectAllocation;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Database\Factories\SubjectAllocationFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

// Distinct name per Pest file: file-level functions share one global
// namespace, and a duplicate is a fatal redeclaration.
function allocationUserAs(Role $role): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

/**
 * Prerequisite rows are inserted with raw DB writes (via the factory's
 * schema-filtered helpers), never through the AcademicYear / ClassLevel
 * factories - those are being written concurrently by other authors and
 * this suite must not depend on their code.
 *
 * @return array{year: int, level: int, subject: Subject}
 */
function allocationPrerequisites(): array
{
    return [
        'year' => SubjectAllocationFactory::academicYearId(),
        'level' => SubjectAllocationFactory::classLevelId(),
        'subject' => Subject::factory()->create(),
    ];
}

it('allocates a subject, storing a null stream as the 0 sentinel', function () {
    actingAs(allocationUserAs(Role::VicePrincipal));
    ['year' => $year, 'level' => $level, 'subject' => $subject] = allocationPrerequisites();

    $allocation = app(AllocateSubject::class)->handle(
        academicYearId: $year,
        classLevelId: $level,
        streamId: null,
        subjectId: (int) $subject->getKey(),
        coefficient: '4.00',
    );

    expect($allocation->stream_id)->toBe(SubjectAllocation::STREAM_NONE)
        ->and($allocation->isForWholeLevel())->toBeTrue()
        ->and($allocation->coefficient)->toBe('4.00')
        ->and($allocation->version)->toBe(1);

    expect(
        AuditLog::query()
            ->where('module', 'Academics')
            ->where('auditable_type', SubjectAllocation::class)
            ->where('action', 'created')
            ->count()
    )->toBe(1);
});

it('rejects a duplicate allocation for the same year, level, stream and subject', function () {
    actingAs(allocationUserAs(Role::VicePrincipal));
    ['year' => $year, 'level' => $level, 'subject' => $subject] = allocationPrerequisites();

    $allocate = app(AllocateSubject::class);
    $allocate->handle($year, $level, 7, (int) $subject->getKey(), '3.00');

    expect(fn () => $allocate->handle($year, $level, 7, (int) $subject->getKey(), '3.00'))
        ->toThrow(
            DomainException::class,
            'This subject is already allocated to this level/stream for this year.'
        );

    expect(SubjectAllocation::query()->count())->toBe(1);
});

it('makes two null-stream allocations of the same subject collide - the sentinel guarantee', function () {
    // This is exactly what the 0 sentinel exists for: were stream_id NULL,
    // MySQL's UNIQUE index would treat each NULL as distinct and both
    // inserts would succeed, silently double-counting the subject.
    actingAs(allocationUserAs(Role::VicePrincipal));
    ['year' => $year, 'level' => $level, 'subject' => $subject] = allocationPrerequisites();

    $allocate = app(AllocateSubject::class);
    $allocate->handle($year, $level, null, (int) $subject->getKey(), '2.00');

    expect(fn () => $allocate->handle($year, $level, null, (int) $subject->getKey(), '2.00'))
        ->toThrow(DomainException::class);

    expect(SubjectAllocation::query()->count())->toBe(1);
});

it('lets each stream carry its own allocation of the same subject', function () {
    actingAs(allocationUserAs(Role::VicePrincipal));
    ['year' => $year, 'level' => $level, 'subject' => $subject] = allocationPrerequisites();

    $allocate = app(AllocateSubject::class);
    $allocate->handle($year, $level, null, (int) $subject->getKey(), '2.00');
    $a = $allocate->handle($year, $level, 1, (int) $subject->getKey(), '4.00');
    $b = $allocate->handle($year, $level, 2, (int) $subject->getKey(), '6.00');

    expect(SubjectAllocation::query()->count())->toBe(3)
        ->and($a->coefficient)->toBe('4.00')
        ->and($b->coefficient)->toBe('6.00');
});

it('refuses a negative coefficient at allocation', function () {
    actingAs(allocationUserAs(Role::VicePrincipal));
    ['year' => $year, 'level' => $level, 'subject' => $subject] = allocationPrerequisites();

    app(AllocateSubject::class)->handle($year, $level, null, (int) $subject->getKey(), '-1.00');
})->throws(DomainException::class, 'A subject coefficient cannot be negative.');

it('refuses a negative coefficient on update', function () {
    actingAs(allocationUserAs(Role::VicePrincipal));
    $allocation = SubjectAllocation::factory()->create();

    app(UpdateAllocation::class)->handle($allocation, ['coefficient' => '-0.50']);
})->throws(DomainException::class, 'A subject coefficient cannot be negative.');

it('increments version on every update', function () {
    actingAs(allocationUserAs(Role::VicePrincipal));
    $allocation = SubjectAllocation::factory()->create(['coefficient' => '2.00']);

    expect($allocation->version)->toBe(1);

    $updated = app(UpdateAllocation::class)->handle($allocation, ['coefficient' => '3.00']);

    expect($updated->version)->toBe(2)
        ->and($updated->coefficient)->toBe('3.00');

    $again = app(UpdateAllocation::class)->handle($updated, ['is_optional' => true]);

    expect($again->version)->toBe(3)
        ->and($again->fresh()?->version)->toBe(3);
});

it('rejects allocation by an actor without academics.manage', function () {
    actingAs(allocationUserAs(Role::Teacher));
    // Prerequisites are irrelevant: authorization is checked first.
    app(AllocateSubject::class)->handle(1, 1, null, 1, '2.00');
})->throws(AuthorizationException::class);

it('rejects allocation update by an actor without academics.manage', function () {
    actingAs(allocationUserAs(Role::VicePrincipal));
    $allocation = SubjectAllocation::factory()->create();

    actingAs(allocationUserAs(Role::Teacher));

    app(UpdateAllocation::class)->handle($allocation, ['coefficient' => '5.00']);
})->throws(AuthorizationException::class);

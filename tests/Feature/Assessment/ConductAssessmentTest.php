<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\Conduct\RecordConductAssessment;
use App\Modules\Assessment\Models\ConductAssessment;
use App\Modules\Assessment\Models\ConductScale;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Audit\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * 01-assessment §12.3 - the MINESEC conduct block.
 *
 * The invariant these tests exist to protect: conduct is NOT an input to the
 * general average and never enters §10.1. A conduct grade that quietly moved
 * a student's rank would be both invisible and wrong.
 */

function conductActor(): Actor
{
    (new \Database\Seeders\RolePermissionSeeder())->run();

    $user = User::factory()->create();
    $user->assignRole(Role::SuperAdmin->value);
    Auth::setUser($user);

    return new Actor((int) $user->getKey(), (string) $user->name);
}

it('never lets the averaging path read the conduct tables', function (): void {
    // A structural assertion, not a behavioural one: if an averaging Action
    // ever learns the word "conduct", conduct has started influencing a mark.
    $averagingActions = [
        'ComputePeriodResults.php',
        'ComputeRanking.php',
        'ComputeClassStatistics.php',
        'GetAnnualAveragesForEnrollments.php',
    ];

    foreach ($averagingActions as $file) {
        $path = base_path('app/Modules/Assessment/Actions/'.$file);

        expect(file_exists($path))->toBeTrue("{$file} is missing");
        expect(mb_strtolower(file_get_contents($path)))
            ->not->toContain('conduct_assessments')
            ->not->toContain('conductassessment');
    }
});

it('seeds the two scales the spec names, with their levels in order', function (): void {
    (new \Database\Seeders\ConductScaleSeeder())->run();

    $minesec = ConductScale::query()->where('code', 'MINESEC_FR')->firstOrFail();
    $competency = ConductScale::query()->where('code', 'COMPETENCY')->firstOrFail();

    expect($minesec->levels()->pluck('code')->all())->toBe(['TB', 'B', 'AB', 'P', 'M'])
        ->and($competency->levels()->pluck('code')->all())->toBe(['A', 'ECA', 'NA']);
});

it('refuses a level that belongs to a different scale', function (): void {
    $actor = conductActor();
    (new \Database\Seeders\ConductScaleSeeder())->run();

    $minesec = ConductScale::query()->where('code', 'MINESEC_FR')->firstOrFail();
    $competency = ConductScale::query()->where('code', 'COMPETENCY')->firstOrFail();

    $good = (int) $minesec->levels()->where('code', 'TB')->value('id');
    $foreign = (int) $competency->levels()->where('code', 'A')->value('id');

    [$enrollmentId, $periodId, $staffId] = conductFixture();

    expect(fn () => app(RecordConductAssessment::class)->handle(
        $enrollmentId,
        $periodId,
        (int) $minesec->getKey(),
        [
            'conduite' => $good,
            'travail' => $good,
            'assiduite' => $good,
            'discipline' => $good,
            // A level from the OTHER scale - the bulletin would otherwise
            // print a grade from a scale nobody chose.
            'tenue' => $foreign,
        ],
        $staffId,
        $actor,
    ))->toThrow(DomainException::class);
});

it('upserts rather than creating a second row for the same period', function (): void {
    $actor = conductActor();
    (new \Database\Seeders\ConductScaleSeeder())->run();

    $scale = ConductScale::query()->where('code', 'MINESEC_FR')->firstOrFail();
    $tb = (int) $scale->levels()->where('code', 'TB')->value('id');
    $b = (int) $scale->levels()->where('code', 'B')->value('id');

    [$enrollmentId, $periodId, $staffId] = conductFixture();

    $levels = fn (int $id): array => [
        'conduite' => $id, 'travail' => $id, 'assiduite' => $id,
        'discipline' => $id, 'tenue' => $id,
    ];

    $action = app(RecordConductAssessment::class);

    $action->handle($enrollmentId, $periodId, (int) $scale->getKey(), $levels($tb), $staffId, $actor);
    $action->handle($enrollmentId, $periodId, (int) $scale->getKey(), $levels($b), $staffId, $actor);

    expect(ConductAssessment::query()->count())->toBe(1)
        ->and((int) ConductAssessment::query()->value('conduite_level_id'))->toBe($b);
});

/**
 * @return array{0: int, 1: int, 2: int}
 */
function conductFixture(): array
{
    $enrollmentId = (int) DB::table('enrollments')->value('id');
    $periodId = (int) DB::table('assessment_periods')->value('id');
    $staffId = (int) DB::table('staff_members')->value('id');

    if ($enrollmentId === 0 || $periodId === 0 || $staffId === 0) {
        test()->markTestSkipped(
            'Needs an enrollment, an assessment period and a staff member; '
            .'those factories belong to other slices of this phase.'
        );
    }

    return [$enrollmentId, $periodId, $staffId];
}

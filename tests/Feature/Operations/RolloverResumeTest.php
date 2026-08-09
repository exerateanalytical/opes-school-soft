<?php

declare(strict_types=1);

use App\Modules\Academics\Actions\CreateAcademicYear;
use App\Modules\Academics\Domain\AcademicYearStatus;
use App\Modules\Academics\Domain\AssessmentPeriodStatus;
use App\Modules\Academics\Domain\AssessmentPeriodType;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\AssessmentPeriod;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Models\SubjectAllocation;
use App\Modules\Fees\Domain\FeeStructureStatus;
use App\Modules\Fees\Models\FeeItem;
use App\Modules\Fees\Models\FeeStructure;
use App\Modules\Fees\Models\FeeStructureLine;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Actions\Rollover\CopyAssessmentPeriodsStep;
use App\Modules\Operations\Actions\Rollover\CopyClassGroupsStep;
use App\Modules\Operations\Actions\Rollover\CopyFeeStructuresStep;
use App\Modules\Operations\Actions\Rollover\CopySubjectAllocationsStep;
use App\Modules\Operations\Actions\Rollover\CreateNewYearStep;
use App\Modules\Operations\Actions\Rollover\StartRolloverRun;
use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\Backup;
use App\Modules\Operations\Models\RolloverArtifact;
use App\Modules\Operations\Models\RolloverRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('rlvResumeFixture')) {
    /**
     * Outgoing year 2250/2251. Prefixed helper - Pest shares one global
     * function namespace across test files.
     *
     * @return array<string, mixed>
     */
    function rlvResumeFixture(): array
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([StartRolloverRun::PERMISSION, 'academics.manage', 'fee.configure'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo([StartRolloverRun::PERMISSION, 'academics.manage', 'fee.configure']);
        $user = $user->fresh() ?? $user;
        actingAs($user);

        $from = AcademicYear::factory()->create([
            'code' => '2250-2251',
            'name' => 'AY 2250/2251',
            'starts_on' => '2250-09-01',
            'ends_on' => '2251-08-31',
            'status' => AcademicYearStatus::Active,
            'is_current' => true,
        ]);

        $section = SchoolSection::factory()->create();
        $level = ClassLevel::factory()->create(['school_section_id' => $section->id]);

        foreach (['Form 3 A' => 45, 'Form 3 B' => 50, 'Form 3 C' => 55] as $name => $capacity) {
            ClassGroup::factory()->create([
                'academic_year_id' => $from->id,
                'class_level_id' => $level->id,
                'stream_id' => null,
                'name' => $name,
                'capacity' => $capacity,
                'status' => 'active',
            ]);
        }

        $subject = Subject::factory()->create();

        SubjectAllocation::query()->create([
            'academic_year_id' => $from->id,
            'class_level_id' => $level->id,
            'stream_id' => SubjectAllocation::STREAM_NONE,
            'subject_id' => $subject->id,
            'coefficient' => '5.00',
            'required_components' => [],
            'is_active' => true,
            'version' => 1,
        ]);

        $root = AssessmentPeriod::query()->create([
            'academic_year_id' => $from->id,
            'parent_id' => null,
            'type' => AssessmentPeriodType::Year,
            'code' => 'YEAR',
            'name' => 'Year',
            'name_fr' => 'Année',
            'order_index' => 0,
            'starts_on' => '2250-09-01',
            'ends_on' => '2251-08-31',
            'weight' => '1.0000',
            'counts_toward_parent' => true,
            'is_reporting_period' => false,
            'status' => AssessmentPeriodStatus::Closed,
        ]);

        AssessmentPeriod::query()->create([
            'academic_year_id' => $from->id,
            'parent_id' => $root->id,
            'type' => AssessmentPeriodType::Term,
            'code' => 'T1',
            'name' => 'Term 1',
            'name_fr' => 'Trimestre 1',
            'order_index' => 1,
            'starts_on' => '2250-09-01',
            'ends_on' => '2251-08-31',
            'weight' => '1.0000',
            'counts_toward_parent' => true,
            'is_reporting_period' => true,
            'status' => AssessmentPeriodStatus::Closed,
        ]);

        $feeItem = FeeItem::factory()->create();

        $structure = FeeStructure::factory()->create([
            'academic_year_id' => $from->id,
            'school_section_id' => $section->id,
            'status' => FeeStructureStatus::Active,
            'effective_from' => '2250-09-01',
        ]);

        FeeStructureLine::query()->create([
            'fee_structure_id' => $structure->id,
            'fee_item_id' => $feeItem->id,
            'amount' => 120_000,
            'term_id' => 0,
            'is_optional' => false,
            'display_order' => 0,
        ]);

        $backup = Backup::query()->create([
            'kind' => 'full',
            'status' => 'healthy',
            'path' => '/tmp/rlv-resume-backup.sql',
            'sha256' => str_repeat('c', 64),
            'started_at' => now(),
            'completed_at' => now(),
            'verified_at' => now(),
        ]);

        return compact('user', 'from', 'section', 'level', 'subject', 'structure', 'backup');
    }
}

it('resumes step 2 after a simulated crash without duplicating the partially copied rows', function () {
    $fx = rlvResumeFixture();
    $actor = $fx['user']->toAuditActor();

    $run = app(StartRolloverRun::class)->handle($fx['from']->id, $fx['backup']->id, $actor);
    $run = app(CreateNewYearStep::class)->handle($run, '2251-2252', 'AY 2251/2252', $actor, '2252-08-31');
    $toYearId = $run->academic_year_to_id;

    // Simulate the kill: one class group was already committed - row AND its
    // undo-ledger entry - before the process died mid-step.
    $partial = ClassGroup::factory()->create([
        'academic_year_id' => $toYearId,
        'class_level_id' => $fx['level']->id,
        'stream_id' => null,
        'name' => 'Form 3 A',
        'capacity' => 45,
        'status' => 'active',
    ]);
    RolloverArtifact::query()->create([
        'rollover_run_id' => $run->id,
        'entity_type' => 'class_groups',
        'entity_id' => $partial->id,
        'step' => RolloverStep::CopyClassGroups->value,
    ]);

    // Restart: the step re-runs, skips the committed row by natural key, and
    // finishes the remaining two.
    $run = app(CopyClassGroupsStep::class)->handle($run->refresh(), $actor);

    $names = ClassGroup::query()
        ->where('academic_year_id', $toYearId)
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($names)->toBe(['Form 3 A', 'Form 3 B', 'Form 3 C']);
    expect($run->artifacts()->where('entity_type', 'class_groups')->count())->toBe(3);

    $state = ($run->step_states ?? [])[RolloverStep::CopyClassGroups->value];
    expect($state['created'])->toBe(2);
    expect($state['skipped_existing'])->toBe(1);
});

it('adopts a new year a crashed attempt already committed instead of failing contiguity', function () {
    $fx = rlvResumeFixture();
    $actor = $fx['user']->toAuditActor();

    $run = app(StartRolloverRun::class)->handle($fx['from']->id, $fx['backup']->id, $actor);

    // The kill landed AFTER CreateAcademicYear's own transaction committed
    // but BEFORE the run row advanced: the year exists, the run still stands
    // at step 1.
    app(CreateAcademicYear::class)->handle('2251-2252', 'AY 2251/2252', '2251-09-01', '2252-08-31', $actor);

    $run = app(CreateNewYearStep::class)->handle($run->refresh(), '2251-2252', 'AY 2251/2252', $actor, '2252-08-31');

    expect($run->currentStep())->toBe(RolloverStep::CopyClassGroups);
    expect(AcademicYear::query()->where('code', '2251-2252')->count())->toBe(1);

    $state = ($run->step_states ?? [])[RolloverStep::CreateNewYear->value];
    expect($state['adopted_existing'])->toBeTrue();

    // Adopted-with-matching-code is OURS: it entered the undo ledger.
    expect($run->artifacts()->where('entity_type', 'academic_years')->count())->toBe(1);
});

it('re-opens a failed run at the step it died on', function () {
    $fx = rlvResumeFixture();
    $actor = $fx['user']->toAuditActor();

    $run = app(StartRolloverRun::class)->handle($fx['from']->id, $fx['backup']->id, $actor);
    $run = app(CreateNewYearStep::class)->handle($run, '2251-2252', 'AY 2251/2252', $actor, '2252-08-31');
    $run = app(CopyClassGroupsStep::class)->handle($run, $actor);

    // The power cut: the run is marked failed mid-step-3.
    $run->forceFill(['status' => RolloverRunStatus::Failed->value])->save();

    $resumed = app(StartRolloverRun::class)->handle($fx['from']->id, $fx['backup']->id, $actor);

    expect($resumed->id)->toBe($run->id);
    expect($resumed->status())->toBe(RolloverRunStatus::Running);
    expect($resumed->currentStep())->toBe(RolloverStep::CopySubjectAllocations);
});

it('refuses to resume when the outgoing year was edited mid-run', function () {
    $fx = rlvResumeFixture();
    $actor = $fx['user']->toAuditActor();

    app(StartRolloverRun::class)->handle($fx['from']->id, $fx['backup']->id, $actor);

    AcademicYear::query()->whereKey($fx['from']->id)->update(['ends_on' => '2251-08-30']);

    expect(fn () => app(StartRolloverRun::class)->handle($fx['from']->id, $fx['backup']->id, $actor))
        ->toThrow(DomainException::class, 'dates changed');
});

it('produces an identical copied dataset whether interrupted or not', function () {
    $fx = rlvResumeFixture();
    $actor = $fx['user']->toAuditActor();

    $run = app(StartRolloverRun::class)->handle($fx['from']->id, $fx['backup']->id, $actor);
    $run = app(CreateNewYearStep::class)->handle($run, '2251-2252', 'AY 2251/2252', $actor, '2252-08-31');
    $toYearId = $run->academic_year_to_id;

    // Crash simulation: part of step 2 already committed...
    $partial = ClassGroup::factory()->create([
        'academic_year_id' => $toYearId,
        'class_level_id' => $fx['level']->id,
        'stream_id' => null,
        'name' => 'Form 3 B',
        'capacity' => 50,
        'status' => 'active',
    ]);
    RolloverArtifact::query()->create([
        'rollover_run_id' => $run->id,
        'entity_type' => 'class_groups',
        'entity_id' => $partial->id,
        'step' => RolloverStep::CopyClassGroups->value,
    ]);

    // ...then the operator restarts and walks the wizard to the end of the
    // copy phase.
    $run = app(StartRolloverRun::class)->handle($fx['from']->id, $fx['backup']->id, $actor);
    $run = app(CopyClassGroupsStep::class)->handle($run, $actor);
    $run = app(CopySubjectAllocationsStep::class)->handle($run, $actor);
    $run = app(CopyAssessmentPeriodsStep::class)->handle($run, $actor);
    $run = app(CopyFeeStructuresStep::class)->handle($run, $actor, 0);

    // The copied surface equals the source surface exactly - what an
    // uninterrupted run would have produced (§6.3 acceptance criterion).
    $sourceGroups = ClassGroup::query()->where('academic_year_id', $fx['from']->id)
        ->orderBy('name')->get()->map(fn (ClassGroup $g): array => [$g->name, $g->capacity])->all();
    $copiedGroups = ClassGroup::query()->where('academic_year_id', $toYearId)
        ->orderBy('name')->get()->map(fn (ClassGroup $g): array => [$g->name, $g->capacity])->all();
    expect($copiedGroups)->toBe($sourceGroups);

    expect(SubjectAllocation::query()->where('academic_year_id', $toYearId)->count())->toBe(1);
    expect(AssessmentPeriod::query()->where('academic_year_id', $toYearId)->pluck('code')->sort()->values()->all())
        ->toBe(['T1', 'YEAR']);
    expect(FeeStructure::query()->where('academic_year_id', $toYearId)->count())->toBe(1);
    expect((int) FeeStructureLine::query()
        ->whereIn('fee_structure_id', FeeStructure::query()->where('academic_year_id', $toYearId)->select('id'))
        ->sum('amount'))->toBe(120_000);

    // No duplicates anywhere: the ledger holds each created row once, and
    // the copied group count matches the source count one-for-one.
    $total = RolloverArtifact::query()->where('rollover_run_id', $run->id)->count();
    $distinct = (int) RolloverArtifact::query()
        ->where('rollover_run_id', $run->id)
        ->selectRaw('COUNT(DISTINCT entity_type, entity_id) AS n')
        ->value('n');
    expect($total)->toBe($distinct);
    expect(ClassGroup::query()->where('academic_year_id', $toYearId)->count())->toBe(3);
});

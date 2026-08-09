<?php

declare(strict_types=1);

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
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Assessment\Models\AssessmentComponent;
use App\Modules\Assessment\Models\Mark;
use App\Modules\Fees\Domain\FeeStructureStatus;
use App\Modules\Fees\Domain\InstallmentBasis;
use App\Modules\Fees\Models\FeeItem;
use App\Modules\Fees\Models\FeeStructure;
use App\Modules\Fees\Models\FeeStructureLine;
use App\Modules\Fees\Models\InstallmentPlan;
use App\Modules\Fees\Models\Payment;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Actions\Rollover\CopyAssessmentPeriodsStep;
use App\Modules\Operations\Actions\Rollover\CopyClassGroupsStep;
use App\Modules\Operations\Actions\Rollover\CopyFeeStructuresStep;
use App\Modules\Operations\Actions\Rollover\CopySubjectAllocationsStep;
use App\Modules\Operations\Actions\Rollover\CreateNewYearStep;
use App\Modules\Operations\Actions\Rollover\FlipActiveYearStep;
use App\Modules\Operations\Actions\Rollover\StartRolloverRun;
use App\Modules\Operations\Actions\Rollover\UndoRollover;
use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\Backup;
use App\Modules\Operations\Models\RolloverRun;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('rlvUndoFixture')) {
    /**
     * Outgoing year 2200/2201 with the full copyable surface, plus a run
     * walked through steps 1-5. Prefixed helper - Pest shares one global
     * function namespace.
     *
     * @return array<string, mixed>
     */
    function rlvUndoFixture(): array
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
            'code' => '2200-2201',
            'name' => 'AY 2200/2201',
            'starts_on' => '2200-09-01',
            'ends_on' => '2201-08-31',
            'status' => AcademicYearStatus::Active,
            'is_current' => true,
        ]);

        $section = SchoolSection::factory()->create();
        $level = ClassLevel::factory()->create(['school_section_id' => $section->id]);

        ClassGroup::factory()->create([
            'academic_year_id' => $from->id,
            'class_level_id' => $level->id,
            'stream_id' => null,
            'name' => 'Form 2 A',
            'capacity' => 50,
            'status' => 'active',
        ]);

        $subject = Subject::factory()->create();

        SubjectAllocation::query()->create([
            'academic_year_id' => $from->id,
            'class_level_id' => $level->id,
            'stream_id' => SubjectAllocation::STREAM_NONE,
            'subject_id' => $subject->id,
            'coefficient' => '3.00',
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
            'starts_on' => '2200-09-01',
            'ends_on' => '2201-08-31',
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
            'starts_on' => '2200-09-01',
            'ends_on' => '2201-08-31',
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
            'effective_from' => '2200-09-01',
        ]);

        FeeStructureLine::query()->create([
            'fee_structure_id' => $structure->id,
            'fee_item_id' => $feeItem->id,
            'amount' => 80_000,
            'term_id' => 0,
            'is_optional' => false,
            'display_order' => 0,
        ]);

        $plan = InstallmentPlan::query()->create([
            'academic_year_id' => $from->id,
            'name' => 'Two tranches',
            'fee_structure_id' => $structure->id,
            'basis' => InstallmentBasis::Percentage,
            'is_default' => false,
        ]);

        foreach ([[1, 600_000, '2200-09-15'], [2, 400_000, '2201-01-15']] as [$seq, $bp, $due]) {
            $plan->lines()->create([
                'sequence_no' => $seq,
                'label' => 'Instalment '.$seq,
                'label_fr' => 'Versement '.$seq,
                'percentage_bp' => $bp,
                'due_date' => $due,
            ]);
        }

        $backup = Backup::query()->create([
            'kind' => 'full',
            'status' => 'healthy',
            'path' => '/tmp/rlv-undo-backup.sql',
            'sha256' => str_repeat('b', 64),
            'started_at' => now(),
            'completed_at' => now(),
            'verified_at' => now(),
        ]);

        return compact('user', 'from', 'section', 'level', 'subject', 'backup');
    }
}

if (! function_exists('rlvUndoRunThroughStep5')) {
    /**
     * @param  array<string, mixed>  $fx
     */
    function rlvUndoRunThroughStep5(array $fx): RolloverRun
    {
        /** @var User $user */
        $user = $fx['user'];
        $actor = $user->toAuditActor();

        $run = app(StartRolloverRun::class)->handle($fx['from']->id, $fx['backup']->id, $actor);
        $run = app(CreateNewYearStep::class)->handle($run, '2201-2202', 'AY 2201/2202', $actor, '2202-08-31');
        $run = app(CopyClassGroupsStep::class)->handle($run, $actor);
        $run = app(CopySubjectAllocationsStep::class)->handle($run, $actor);
        $run = app(CopyAssessmentPeriodsStep::class)->handle($run, $actor);

        return app(CopyFeeStructuresStep::class)->handle($run, $actor, 0);
    }
}

it('undoes a mid-flight run: every created row disappears, in reverse order', function () {
    $fx = rlvUndoFixture();
    $run = rlvUndoRunThroughStep5($fx);
    $toYearId = $run->academic_year_to_id;

    expect($run->artifacts()->count())->toBeGreaterThan(5);

    $run = app(UndoRollover::class)->handle($run, $fx['user']->toAuditActor());

    expect($run->status())->toBe(RolloverRunStatus::Undone);
    expect($run->academic_year_to_id)->toBeNull();
    expect($run->artifacts()->count())->toBe(0);

    // The whole copied surface is gone, the new year row included.
    expect(AcademicYear::query()->find($toYearId))->toBeNull();
    expect(ClassGroup::query()->where('academic_year_id', $toYearId)->count())->toBe(0);
    expect(SubjectAllocation::query()->where('academic_year_id', $toYearId)->count())->toBe(0);
    expect(AssessmentPeriod::query()->where('academic_year_id', $toYearId)->count())->toBe(0);
    expect(FeeStructure::query()->where('academic_year_id', $toYearId)->count())->toBe(0);
    expect(InstallmentPlan::query()->where('academic_year_id', $toYearId)->count())->toBe(0);

    // The outgoing year is untouched.
    $from = AcademicYear::query()->findOrFail((int) $fx['from']->id);
    expect($from->is_current)->toBeTrue();
    expect($from->status)->toBe(AcademicYearStatus::Active);
});

it('undoes a completed run: the flip is restored exactly', function () {
    $fx = rlvUndoFixture();
    $run = rlvUndoRunThroughStep5($fx);

    $run->forceFill(['current_step' => RolloverStep::FlipActiveYear->value])->save();
    $run = app(FlipActiveYearStep::class)->handle($run->refresh(), $fx['user']->toAuditActor());

    expect(AcademicYear::query()->findOrFail((int) $fx['from']->id)->is_current)->toBeFalse();

    $run = app(UndoRollover::class)->handle($run, $fx['user']->toAuditActor());

    $from = AcademicYear::query()->findOrFail((int) $fx['from']->id);
    expect($from->is_current)->toBeTrue();
    expect($from->status)->toBe(AcademicYearStatus::Active);
    expect(AcademicYear::query()->where('is_current', true)->count())->toBe(1);
    expect($run->status())->toBe(RolloverRunStatus::Undone);
});

it('refuses undo once the new year records its first payment, naming it', function () {
    $fx = rlvUndoFixture();
    $run = rlvUndoRunThroughStep5($fx);

    Payment::factory()->create([
        'academic_year_id' => $run->academic_year_to_id,
        'receipt_no' => 'RCPT/2201/000042',
    ]);

    expect(fn () => app(UndoRollover::class)->handle($run, $fx['user']->toAuditActor()))
        ->toThrow(DomainException::class, 'RCPT/2201/000042');

    expect($run->refresh()->status())->toBe(RolloverRunStatus::Running);
    expect(AcademicYear::query()->find($run->academic_year_to_id))->not->toBeNull();
});

it('refuses undo once the new year records its first mark', function () {
    $fx = rlvUndoFixture();
    $run = rlvUndoRunThroughStep5($fx);
    $toYearId = $run->academic_year_to_id;

    $component = AssessmentComponent::factory()->create();
    $allocation = SubjectAllocation::query()->where('academic_year_id', $toYearId)->firstOrFail();
    $period = AssessmentPeriod::query()->where('academic_year_id', $toYearId)->where('code', 'T1')->firstOrFail();
    $enrollment = Enrollment::factory()->create(['academic_year_id' => $toYearId]);

    Mark::query()->create([
        'enrollment_id' => $enrollment->id,
        'subject_allocation_id' => $allocation->id,
        'assessment_period_id' => $period->id,
        'component_id' => $component->id,
        'state' => 'pending',
        'workflow_state' => 'draft',
        'attempt_no' => 1,
        'version' => 1,
    ]);

    expect(fn () => app(UndoRollover::class)->handle($run, $fx['user']->toAuditActor()))
        ->toThrow(DomainException::class, 'first mark');
});

it('refuses undo once the new year records its first journal entry', function () {
    $fx = rlvUndoFixture();
    $run = rlvUndoRunThroughStep5($fx);

    // A calendar for a date inside the NEW year (2201-10-15).
    $fiscalYear = FiscalYear::factory()->create([
        'code' => 'FY2201X',
        'starts_on' => '2201-01-01',
        'ends_on' => '2201-12-31',
    ]);
    $period = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_month' => '2201-10-01',
        'starts_on' => '2201-10-01',
        'ends_on' => '2201-10-31',
    ]);
    JournalEntry::query()->create([
        'journal_id' => Journal::factory()->create()->id,
        'piece_no' => 'OD-2201-000001',
        'date' => '2201-10-15',
        'value_date' => '2201-10-15',
        'accounting_period_id' => $period->id,
        'fiscal_year_id' => $fiscalYear->id,
        'academic_year_id' => $run->academic_year_to_id,
        'label' => 'First entry of the new year',
        'status' => 'posted',
        'total_debit' => 0,
        'total_credit' => 0,
        'posted_at' => now(),
    ]);

    expect(fn () => app(UndoRollover::class)->handle($run, $fx['user']->toAuditActor()))
        ->toThrow(DomainException::class, 'first journal entry');
});

it('refuses to undo a run twice', function () {
    $fx = rlvUndoFixture();
    $run = rlvUndoRunThroughStep5($fx);

    $run = app(UndoRollover::class)->handle($run, $fx['user']->toAuditActor());

    expect(fn () => app(UndoRollover::class)->handle($run, $fx['user']->toAuditActor()))
        ->toThrow(DomainException::class, 'already been undone');
});

it('allows a fresh rollover of the same outgoing year after an undo', function () {
    $fx = rlvUndoFixture();
    $run = rlvUndoRunThroughStep5($fx);

    app(UndoRollover::class)->handle($run, $fx['user']->toAuditActor());

    $second = app(StartRolloverRun::class)->handle($fx['from']->id, $fx['backup']->id, $fx['user']->toAuditActor());

    expect($second->id)->not->toBe($run->id);
    expect($second->status())->toBe(RolloverRunStatus::Running);
    expect(RolloverRun::query()->count())->toBe(2);
});

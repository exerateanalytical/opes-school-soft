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
use App\Modules\Fees\Domain\FeeStructureStatus;
use App\Modules\Fees\Domain\InstallmentBasis;
use App\Modules\Fees\Models\FeeItem;
use App\Modules\Fees\Models\FeeStructure;
use App\Modules\Fees\Models\FeeStructureLine;
use App\Modules\Fees\Models\InstallmentPlan;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Actions\Rollover\CopyAssessmentPeriodsStep;
use App\Modules\Operations\Actions\Rollover\CopyClassGroupsStep;
use App\Modules\Operations\Actions\Rollover\CopyFeeStructuresStep;
use App\Modules\Operations\Actions\Rollover\CopySubjectAllocationsStep;
use App\Modules\Operations\Actions\Rollover\CreateNewYearStep;
use App\Modules\Operations\Actions\Rollover\FlipActiveYearStep;
use App\Modules\Operations\Actions\Rollover\PreviewStep;
use App\Modules\Operations\Actions\Rollover\StartRolloverRun;
use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\Backup;
use App\Modules\Operations\Models\RolloverRun;
use App\Modules\Students\Models\Enrollment;
use Database\Factories\InvoiceFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('rlvStepsOperator')) {
    /**
     * Prefixed helper (Pest shares one global function namespace across test
     * files). The rollover operator needs the rollover permission plus the
     * two doors' permissions - the wizard drives Academics and Fees Actions.
     */
    function rlvStepsOperator(bool $withRolloverPermission = true): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([StartRolloverRun::PERMISSION, 'academics.manage', 'fee.configure'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo(['academics.manage', 'fee.configure']);

        if ($withRolloverPermission) {
            $user->givePermissionTo(StartRolloverRun::PERMISSION);
        }

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('rlvStepsFixture')) {
    /**
     * A complete outgoing year: closed reporting periods, class groups,
     * subject allocation, an active fee structure with a fixed instalment
     * plan, a year-global percentage plan, and a verified backup.
     *
     * @return array<string, mixed>
     */
    function rlvStepsFixture(): array
    {
        $user = rlvStepsOperator();
        actingAs($user);

        $from = AcademicYear::factory()->create([
            'code' => '2150-2151',
            'name' => 'AY 2150/2151',
            'starts_on' => '2150-09-01',
            'ends_on' => '2151-08-31',
            'status' => AcademicYearStatus::Active,
            'is_current' => true,
        ]);

        $section = SchoolSection::factory()->create();
        $level = ClassLevel::factory()->create(['school_section_id' => $section->id]);

        $groupA = ClassGroup::factory()->create([
            'academic_year_id' => $from->id,
            'class_level_id' => $level->id,
            'stream_id' => null,
            'name' => 'Form 1 A',
            'capacity' => 60,
            'class_teacher_staff_id' => 424242,
            'room_id' => null,
            'status' => 'active',
        ]);

        $groupB = ClassGroup::factory()->create([
            'academic_year_id' => $from->id,
            'class_level_id' => $level->id,
            'stream_id' => null,
            'name' => 'Form 1 B',
            'capacity' => 55,
            'class_teacher_staff_id' => null,
            'room_id' => null,
            'status' => 'active',
        ]);

        $subject = Subject::factory()->create();

        $allocation = SubjectAllocation::query()->create([
            'academic_year_id' => $from->id,
            'class_level_id' => $level->id,
            'stream_id' => SubjectAllocation::STREAM_NONE,
            'subject_id' => $subject->id,
            'coefficient' => '4.00',
            'required_components' => [],
            'is_optional' => false,
            'counts_toward_average' => true,
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
            'starts_on' => '2150-09-01',
            'ends_on' => '2151-08-31',
            'weight' => '1.0000',
            'counts_toward_parent' => true,
            'is_reporting_period' => false,
            'status' => AssessmentPeriodStatus::Closed,
        ]);

        $terms = [];

        foreach ([['T1', '2150-09-01', '2151-01-31'], ['T2', '2151-02-01', '2151-08-31']] as $i => [$code, $starts, $ends]) {
            $terms[] = AssessmentPeriod::query()->create([
                'academic_year_id' => $from->id,
                'parent_id' => $root->id,
                'type' => AssessmentPeriodType::Term,
                'code' => $code,
                'name' => 'Term '.($i + 1),
                'name_fr' => 'Trimestre '.($i + 1),
                'order_index' => $i + 1,
                'starts_on' => $starts,
                'ends_on' => $ends,
                'weight' => '1.0000',
                'counts_toward_parent' => true,
                'is_reporting_period' => true,
                'status' => AssessmentPeriodStatus::Closed,
            ]);
        }

        $feeItem = FeeItem::factory()->create();

        $structure = FeeStructure::factory()->create([
            'academic_year_id' => $from->id,
            'school_section_id' => $section->id,
            'status' => FeeStructureStatus::Active,
            'effective_from' => '2150-09-01',
        ]);

        FeeStructureLine::query()->create([
            'fee_structure_id' => $structure->id,
            'fee_item_id' => $feeItem->id,
            'amount' => 100_000,
            'term_id' => $terms[0]->id,
            'is_optional' => false,
            'display_order' => 0,
        ]);

        $fixedPlan = InstallmentPlan::query()->create([
            'academic_year_id' => $from->id,
            'name' => 'Three tranches (fixed)',
            'fee_structure_id' => $structure->id,
            'basis' => InstallmentBasis::Fixed,
            'is_default' => false,
        ]);

        foreach ([[1, 40_000, '2150-09-15'], [2, 30_000, '2151-01-15'], [3, 30_000, '2151-04-15']] as [$seq, $amount, $due]) {
            $fixedPlan->lines()->create([
                'sequence_no' => $seq,
                'label' => 'Tranche '.$seq,
                'label_fr' => 'Tranche '.$seq,
                'fixed_amount' => $amount,
                'due_date' => $due,
            ]);
        }

        $globalPlan = InstallmentPlan::query()->create([
            'academic_year_id' => $from->id,
            'name' => 'Standard split',
            'fee_structure_id' => InstallmentPlan::GLOBAL,
            'basis' => InstallmentBasis::Percentage,
            'is_default' => true,
        ]);

        foreach ([[1, 500_000, '2150-09-15'], [2, 300_000, '2151-01-15'], [3, 200_000, '2151-04-15']] as [$seq, $bp, $due]) {
            $globalPlan->lines()->create([
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
            'path' => '/tmp/rlv-steps-backup.sql',
            'sha256' => str_repeat('a', 64),
            'started_at' => now(),
            'completed_at' => now(),
            'verified_at' => now(),
        ]);

        return compact('user', 'from', 'section', 'level', 'groupA', 'groupB', 'subject', 'allocation', 'root', 'terms', 'feeItem', 'structure', 'fixedPlan', 'globalPlan', 'backup');
    }
}

if (! function_exists('rlvStepsStart')) {
    /**
     * @param  array<string, mixed>  $fx
     */
    function rlvStepsStart(array $fx): RolloverRun
    {
        /** @var AcademicYear $from */
        $from = $fx['from'];
        /** @var Backup $backup */
        $backup = $fx['backup'];
        /** @var User $user */
        $user = $fx['user'];

        return app(StartRolloverRun::class)->handle($from->id, $backup->id, $user->toAuditActor());
    }
}

if (! function_exists('rlvStepsWalkTo')) {
    /**
     * Executes steps 1..$lastStep in order and returns the refreshed run.
     *
     * @param  array<string, mixed>  $fx
     */
    function rlvStepsWalkTo(array $fx, int $lastStep): RolloverRun
    {
        /** @var User $user */
        $user = $fx['user'];
        $actor = $user->toAuditActor();

        $run = rlvStepsStart($fx);

        if ($lastStep >= 1) {
            $run = app(CreateNewYearStep::class)->handle($run, '2151-2152', 'AY 2151/2152', $actor, '2152-08-31');
        }

        if ($lastStep >= 2) {
            $run = app(CopyClassGroupsStep::class)->handle($run, $actor);
        }

        if ($lastStep >= 3) {
            $run = app(CopySubjectAllocationsStep::class)->handle($run, $actor);
        }

        if ($lastStep >= 4) {
            $run = app(CopyAssessmentPeriodsStep::class)->handle($run, $actor);
        }

        if ($lastStep >= 5) {
            $run = app(CopyFeeStructuresStep::class)->handle($run, $actor, 500);
        }

        return $run;
    }
}

// ---------------------------------------------------------------------------
// Step 0 - pre-flight
// ---------------------------------------------------------------------------

it('opens a run at step 1 with the pre-flight recorded and audited', function () {
    $fx = rlvStepsFixture();

    $run = rlvStepsStart($fx);

    expect($run->currentStep())->toBe(RolloverStep::CreateNewYear);
    expect($run->status())->toBe(RolloverRunStatus::Running);
    expect($run->academic_year_to_id)->toBeNull();
    expect($run->backup_id)->toBe($fx['backup']->id);
    expect($run->inputs_hash)->toHaveLength(64);

    $preflight = ($run->step_states ?? [])[RolloverStep::Preflight->value];
    expect($preflight['backup_id'])->toBe($fx['backup']->id);
    expect((string) $preflight['cash_desk_check'])->toContain('deferred');

    expect(DB::table('audit_logs')->where('module', 'Operations')->where('auditable_type', RolloverRun::class)->count())->toBe(1);
});

it('refuses to start without a verified backup, with no override', function () {
    $fx = rlvStepsFixture();
    $fx['backup']->update(['status' => 'running', 'verified_at' => null]);

    expect(fn () => rlvStepsStart($fx))
        ->toThrow(DomainException::class, 'not verified healthy');

    expect(RolloverRun::query()->count())->toBe(0);
});

it('refuses to start while a reporting period is unpublished, naming it', function () {
    $fx = rlvStepsFixture();
    $fx['terms'][1]->update(['status' => AssessmentPeriodStatus::Open]);

    expect(fn () => rlvStepsStart($fx))
        ->toThrow(DomainException::class, 'T2');
});

it('refuses to start while the outgoing year has a draft journal entry', function () {
    $fx = rlvStepsFixture();

    $fiscalYear = \App\Modules\Accounting\Models\FiscalYear::factory()->create([
        'code' => 'FY2150X',
        'starts_on' => '2150-01-01',
        'ends_on' => '2150-12-31',
    ]);
    $period = \App\Modules\Accounting\Models\AccountingPeriod::factory()->create([
        'fiscal_year_id' => $fiscalYear->id,
        'period_month' => '2150-10-01',
        'starts_on' => '2150-10-01',
        'ends_on' => '2150-10-31',
    ]);
    \App\Modules\Accounting\Models\JournalEntry::query()->create([
        'journal_id' => \App\Modules\Accounting\Models\Journal::factory()->create()->id,
        'date' => '2150-10-15',
        'value_date' => '2150-10-15',
        'accounting_period_id' => $period->id,
        'fiscal_year_id' => $fiscalYear->id,
        'academic_year_id' => $fx['from']->id,
        'label' => 'Unfinished cash entry',
        'status' => 'draft',
        'total_debit' => 0,
        'total_credit' => 0,
    ]);

    expect(fn () => rlvStepsStart($fx))
        ->toThrow(DomainException::class, 'draft journal entr');
});

it('returns the same resumable run when started twice', function () {
    $fx = rlvStepsFixture();

    $first = rlvStepsStart($fx);
    $second = rlvStepsStart($fx);

    expect($second->id)->toBe($first->id);
    expect(RolloverRun::query()->count())->toBe(1);
});

it('denies starting a rollover without the rollover.run permission', function () {
    $fx = rlvStepsFixture();
    $user = rlvStepsOperator(withRolloverPermission: false);
    actingAs($user);

    expect(fn () => app(StartRolloverRun::class)->handle($fx['from']->id, $fx['backup']->id, $user->toAuditActor()))
        ->toThrow(AuthorizationException::class);
});

// ---------------------------------------------------------------------------
// Step 1 - create the new year
// ---------------------------------------------------------------------------

it('creates the contiguous new year through the Academics door and advances', function () {
    $fx = rlvStepsFixture();
    $run = rlvStepsWalkTo($fx, 1);

    expect($run->academic_year_to_id)->not->toBeNull();
    expect($run->currentStep())->toBe(RolloverStep::CopyClassGroups);

    $to = AcademicYear::query()->findOrFail((int) $run->academic_year_to_id);
    expect($to->starts_on->toDateString())->toBe('2151-09-01');
    expect($to->ends_on->toDateString())->toBe('2152-08-31');
    expect($to->is_current)->toBeFalse();

    expect($run->artifacts()->where('entity_type', 'academic_years')->where('entity_id', $to->id)->exists())->toBeTrue();
});

it('refuses to run a step out of order', function () {
    $fx = rlvStepsFixture();
    $run = rlvStepsWalkTo($fx, 1);

    // Step 1 again, and step 4 ahead of time, both refuse.
    expect(fn () => app(CreateNewYearStep::class)->handle($run, 'X', 'X', $fx['user']->toAuditActor()))
        ->toThrow(DomainException::class, 'strictly in order');

    expect(fn () => app(CopyAssessmentPeriodsStep::class)->handle($run, $fx['user']->toAuditActor()))
        ->toThrow(DomainException::class, 'strictly in order');
});

// ---------------------------------------------------------------------------
// Step 2 - class groups
// ---------------------------------------------------------------------------

it('copies class group shells preserving names and capacities, flagging teachers for review', function () {
    $fx = rlvStepsFixture();
    $run = rlvStepsWalkTo($fx, 2);

    $toYearId = $run->academic_year_to_id;

    expect(ClassGroup::query()->where('academic_year_id', $toYearId)->count())->toBe(2);

    $copyA = ClassGroup::query()->where('academic_year_id', $toYearId)->where('name', 'Form 1 A')->firstOrFail();
    $copyB = ClassGroup::query()->where('academic_year_id', $toYearId)->where('name', 'Form 1 B')->firstOrFail();

    expect($copyA->capacity)->toBe(60);
    expect($copyA->class_teacher_staff_id)->toBe(424242);
    expect($copyB->capacity)->toBe(55);

    expect($run->artifacts()->where('entity_type', 'class_groups')->count())->toBe(2);

    $state = ($run->step_states ?? [])[RolloverStep::CopyClassGroups->value];
    expect($state['created'])->toBe(2);
    expect($state['class_teacher_review_ids'])->toBe([$copyA->id]);
});

// ---------------------------------------------------------------------------
// Step 3 - subject allocations
// ---------------------------------------------------------------------------

it('copies active subject allocations with their coefficients, clearing period-bound windows', function () {
    $fx = rlvStepsFixture();

    // A period-bounded source allocation: its window references outgoing-year
    // periods that cannot exist yet in the new year.
    $fx['allocation']->update(['effective_from_period_id' => $fx['terms'][0]->id]);

    // An inactive allocation must NOT be copied.
    $otherSubject = Subject::factory()->create();
    SubjectAllocation::query()->create([
        'academic_year_id' => $fx['from']->id,
        'class_level_id' => $fx['level']->id,
        'stream_id' => SubjectAllocation::STREAM_NONE,
        'subject_id' => $otherSubject->id,
        'coefficient' => '2.00',
        'required_components' => [],
        'is_active' => false,
        'version' => 1,
    ]);

    $run = rlvStepsWalkTo($fx, 3);

    expect(SubjectAllocation::query()->where('academic_year_id', $run->academic_year_to_id)->count())->toBe(1);

    $copy = SubjectAllocation::query()->where('academic_year_id', $run->academic_year_to_id)->firstOrFail();
    expect($copy->subject_id)->toBe($fx['subject']->id);
    expect($copy->coefficient)->toBe('4.00');
    expect($copy->effective_from_period_id)->toBeNull();
    expect($copy->is_active)->toBeTrue();

    $state = ($run->step_states ?? [])[RolloverStep::CopySubjectAllocations->value];
    expect($state['created'])->toBe(1);
    expect($state['effective_window_cleared_subject_ids'])->toBe([$fx['subject']->id]);
});

// ---------------------------------------------------------------------------
// Step 4 - assessment periods
// ---------------------------------------------------------------------------

it('copies the period tree shifted by the year offset, statuses reset to planned', function () {
    $fx = rlvStepsFixture();
    $run = rlvStepsWalkTo($fx, 4);

    $toYearId = $run->academic_year_to_id;
    $newRoot = AssessmentPeriod::query()->where('academic_year_id', $toYearId)->where('code', 'YEAR')->firstOrFail();
    $newT1 = AssessmentPeriod::query()->where('academic_year_id', $toYearId)->where('code', 'T1')->firstOrFail();

    // 2150-09-01 -> 2151-09-01 is 365 days; every date shifts by exactly that.
    expect($newT1->starts_on->toDateString())->toBe('2151-09-01');
    expect($newT1->parent_id)->toBe($newRoot->id);
    expect($newT1->status)->toBe(AssessmentPeriodStatus::Planned);
    expect($newT1->weight)->toBe('1.0000');
    expect($newT1->is_reporting_period)->toBeTrue();
    expect($newT1->marks_entry_opens_at)->toBeNull();

    expect($run->artifacts()->where('entity_type', 'assessment_periods')->count())->toBe(3);
});

it('refuses to copy a period tree whose participating weights sum to zero', function () {
    $fx = rlvStepsFixture();

    foreach ($fx['terms'] as $term) {
        $term->update(['counts_toward_parent' => false]);
    }

    $run = rlvStepsWalkTo($fx, 3);

    expect(fn () => app(CopyAssessmentPeriodsStep::class)->handle($run, $fx['user']->toAuditActor()))
        ->toThrow(DomainException::class, 'none counts toward it');

    expect(AssessmentPeriod::query()->where('academic_year_id', $run->academic_year_to_id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Step 5 - fee structures and instalment plans
// ---------------------------------------------------------------------------

it('copies fee structures with a 5% uplift, residual to the last tranche, terms re-mapped', function () {
    $fx = rlvStepsFixture();
    $run = rlvStepsWalkTo($fx, 5);

    $toYearId = $run->academic_year_to_id;

    $newStructure = FeeStructure::query()->where('academic_year_id', $toYearId)->firstOrFail();
    expect($newStructure->status)->toBe(FeeStructureStatus::Active);
    expect($newStructure->version)->toBe(1);
    expect($newStructure->effective_from->toDateString())->toBe('2151-09-01');

    // 100 000 x 1.05 = 105 000, and the term-scoped line points at the NEW
    // year's T1, never the outgoing one's.
    $newLine = $newStructure->lines()->firstOrFail();
    $newT1Id = (int) DB::table('assessment_periods')->where('academic_year_id', $toYearId)->where('code', 'T1')->value('id');
    expect($newLine->amount)->toBe(105_000);
    expect($newLine->term_id)->toBe($newT1Id);

    // Fixed plan: 40 000/30 000/30 000 -> uplift each (42 000/31 500), the
    // LAST tranche absorbs the residual so the total is exactly 105 000.
    $newFixed = InstallmentPlan::query()
        ->where('academic_year_id', $toYearId)
        ->where('fee_structure_id', $newStructure->id)
        ->firstOrFail();
    $amounts = $newFixed->lines->pluck('fixed_amount', 'sequence_no');
    expect($amounts[1])->toBe(42_000);
    expect($amounts[2])->toBe(31_500);
    expect($amounts[3])->toBe(31_500);
    expect((int) $newFixed->lines()->sum('fixed_amount'))->toBe(105_000);

    // Global percentage plan: bp untouched (Σ = 1 000 000), due dates shifted.
    $newGlobal = InstallmentPlan::query()
        ->where('academic_year_id', $toYearId)
        ->where('fee_structure_id', InstallmentPlan::GLOBAL)
        ->firstOrFail();
    expect((int) $newGlobal->lines()->sum('percentage_bp'))->toBe(1_000_000);
    expect($newGlobal->lines->first()?->due_date?->toDateString())->toBe('2151-09-15');
    expect($newGlobal->is_default)->toBeTrue();

    // Every created row is in the undo ledger.
    expect($run->artifacts()->where('entity_type', 'fee_structures')->count())->toBe(1);
    expect($run->artifacts()->where('entity_type', 'fee_structure_lines')->count())->toBe(1);
    expect($run->artifacts()->where('entity_type', 'installment_plans')->count())->toBe(2);
    expect($run->artifacts()->where('entity_type', 'installment_plan_lines')->count())->toBe(6);
});

// ---------------------------------------------------------------------------
// Step 10 - flip
// ---------------------------------------------------------------------------

it('flips the active year, activates the new one and closes a settled outgoing year', function () {
    $fx = rlvStepsFixture();
    $run = rlvStepsWalkTo($fx, 5);

    $run->forceFill(['current_step' => RolloverStep::FlipActiveYear->value])->save();
    $run = app(FlipActiveYearStep::class)->handle($run->refresh(), $fx['user']->toAuditActor());

    $from = AcademicYear::query()->findOrFail((int) $fx['from']->id);
    $to = AcademicYear::query()->findOrFail((int) $run->academic_year_to_id);

    expect($to->is_current)->toBeTrue();
    expect($to->status)->toBe(AcademicYearStatus::Active);
    expect($from->is_current)->toBeFalse();
    expect($from->status)->toBe(AcademicYearStatus::Closed);
    expect(AcademicYear::query()->where('is_current', true)->count())->toBe(1);

    expect($run->status())->toBe(RolloverRunStatus::Completed);

    $state = ($run->step_states ?? [])[RolloverStep::FlipActiveYear->value];
    expect($state['previous_current_year_id'])->toBe($fx['from']->id);
    expect($state['outgoing_closed'])->toBeTrue();
});

it('flips but leaves the outgoing year open while a student owes with no recorded outcome', function () {
    $fx = rlvStepsFixture();
    $run = rlvStepsWalkTo($fx, 5);

    $enrollment = Enrollment::factory()->create(['academic_year_id' => $fx['from']->id]);
    $invoice = InvoiceFactory::new()->issued('INV/2150/000001')->createOne(['enrollment_id' => $enrollment->id]);

    // Direct insert: InvoiceLineFactory's definition eagerly resolves
    // Invoice::factory(), which the Invoice model does not expose.
    DB::table('invoice_lines')->insert([
        'invoice_id' => $invoice->id,
        'line_no' => 1,
        'description' => 'Tuition Fee',
        'collection_basis' => 'own_revenue',
        'quantity' => 1,
        'unit_amount' => 50_000,
        'amount' => 50_000,
        'tax_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $run->forceFill(['current_step' => RolloverStep::FlipActiveYear->value])->save();
    $run = app(FlipActiveYearStep::class)->handle($run->refresh(), $fx['user']->toAuditActor());

    $from = AcademicYear::query()->findOrFail((int) $fx['from']->id);
    $to = AcademicYear::query()->findOrFail((int) $run->academic_year_to_id);

    expect($to->is_current)->toBeTrue();
    expect($from->status)->toBe(AcademicYearStatus::Active);

    $state = ($run->step_states ?? [])[RolloverStep::FlipActiveYear->value];
    expect($state['outgoing_closed'])->toBeFalse();
    expect($state['close_blockers'])->toHaveCount(1);
    expect((string) $state['close_blockers'][0])->toContain('still owes');
});

// ---------------------------------------------------------------------------
// Preview - dry-run diff
// ---------------------------------------------------------------------------

it('previews a step as counts and rows with zero writes', function () {
    $fx = rlvStepsFixture();
    $run = rlvStepsWalkTo($fx, 1);

    $groupsBefore = ClassGroup::query()->count();

    $preview = app(PreviewStep::class)->handle($run, RolloverStep::CopyClassGroups);

    expect($preview['counts']['class_groups'])->toBe(2);
    expect($preview['rows'])->toHaveCount(2);
    expect($preview['rows'][0]['name'])->toBe('Form 1 A');
    expect($preview['rows'][0]['class_teacher_review'])->toBeTrue();

    // Zero writes: same numbers, same tables.
    expect(ClassGroup::query()->count())->toBe($groupsBefore);
    expect($run->refresh()->currentStep())->toBe(RolloverStep::CopyClassGroups);

    // After applying the step the same preview shows nothing left to create.
    $run = app(CopyClassGroupsStep::class)->handle($run, $fx['user']->toAuditActor());
    $after = app(PreviewStep::class)->handle($run, RolloverStep::CopyClassGroups);
    expect($after['counts']['class_groups'])->toBe(0);
});

it('previews the fee step including year-global plans', function () {
    $fx = rlvStepsFixture();
    $run = rlvStepsWalkTo($fx, 4);

    $preview = app(PreviewStep::class)->handle($run, RolloverStep::CopyFeeStructures);

    expect($preview['counts']['fee_structures'])->toBe(1);
    expect($preview['counts']['global_installment_plans'])->toBe(1);
    expect(FeeStructure::query()->where('academic_year_id', $run->academic_year_to_id)->count())->toBe(0);
});

<?php

declare(strict_types=1);

use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\Stream;
use App\Modules\Fees\Actions\CreateFeeStructure;
use App\Modules\Fees\Actions\SaveInstallmentPlan;
use App\Modules\Fees\Actions\UpdateFeeStructure;
use App\Modules\Fees\Domain\BoardingScope;
use App\Modules\Fees\Domain\EnrollmentStatusScope;
use App\Modules\Fees\Domain\FeeStructureStatus;
use App\Modules\Fees\Domain\InstallmentBasis;
use App\Modules\Fees\Models\FeeItem;
use App\Modules\Fees\Models\FeeStructure;
use App\Modules\Fees\Models\FeeStructureLine;
use App\Modules\Fees\Models\InstallmentPlan;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('feeStructureUserAs')) {
    function feeStructureUserAs(Role $role = Role::Accountant): User
    {
        (new Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('feesStructureAssertNotNull')) {
    /**
     * Null-narrowing assertion (see AccountingTestHelpers::assertNotNull):
     * throws on null and hands back a value PHPStan trusts as non-null.
     *
     * @template T
     *
     * @param  T|null  $value
     * @return T
     */
    function feesStructureAssertNotNull(mixed $value, string $message = 'Expected a non-null value.'): mixed
    {
        if ($value === null) {
            throw new RuntimeException($message);
        }

        return $value;
    }
}

it('creates a draft v1 structure with lines through CreateFeeStructure', function () {
    actingAs(feeStructureUserAs());

    $year = AcademicYear::factory()->create();
    $section = SchoolSection::factory()->create();
    $item = FeeItem::factory()->create();

    $structure = app(CreateFeeStructure::class)->handle(
        academicYearId: (int) $year->id,
        schoolSectionId: (int) $section->id,
        name: 'Form 1 fees',
        effectiveFrom: '2026-09-01',
        lines: [['fee_item_id' => (int) $item->id, 'amount' => 350_000]],
    );

    expect($structure->status)->toBe(FeeStructureStatus::Draft)
        ->and($structure->version)->toBe(1)
        ->and($structure->class_level_id)->toBe(FeeStructure::ANY)
        ->and($structure->stream_id)->toBe(FeeStructure::ANY)
        ->and($structure->lines)->toHaveCount(1)
        ->and(feesStructureAssertNotNull($structure->lines[0])->amount)->toBe(350_000)
        ->and(feesStructureAssertNotNull($structure->lines[0])->term_id)->toBe(FeeStructureLine::ANNUAL);
});

it('denies structure configuration to the Bursar', function () {
    actingAs(feeStructureUserAs(Role::Bursar));

    expect(fn () => app(CreateFeeStructure::class)->handle(1, 1, 'X', '2026-09-01'))
        ->toThrow(AuthorizationException::class);
});

it('sentinel decision: two "any level, any stream" structures with identical scope collide on the UNIQUE key', function () {
    // This is the whole point of NOT NULL DEFAULT 0: with nullable columns
    // MySQL would treat each NULL as distinct and admit unlimited duplicate
    // scopes - and then two structures match one student with no
    // resolution order (04-fees §2.5).
    $first = FeeStructure::factory()->create();

    expect(fn () => FeeStructure::factory()->create([
        'academic_year_id' => $first->academic_year_id,
        'school_section_id' => $first->school_section_id,
        'effective_from' => $first->effective_from,
    ]))->toThrow(QueryException::class);
});

it('rejects a class level or stream from another section, and unknown ids', function () {
    actingAs(feeStructureUserAs());

    $year = AcademicYear::factory()->create();
    $section = SchoolSection::factory()->create();
    $otherSection = SchoolSection::factory()->create();
    $foreignLevel = ClassLevel::factory()->create(['school_section_id' => $otherSection->id]);
    $foreignStream = Stream::factory()->create(['school_section_id' => $otherSection->id]);

    expect(fn () => app(CreateFeeStructure::class)->handle(
        academicYearId: (int) $year->id,
        schoolSectionId: (int) $section->id,
        name: 'Bad level',
        effectiveFrom: '2026-09-01',
        classLevelId: (int) $foreignLevel->id,
    ))->toThrow(DomainException::class, 'school section');

    expect(fn () => app(CreateFeeStructure::class)->handle(
        academicYearId: (int) $year->id,
        schoolSectionId: (int) $section->id,
        name: 'Bad stream',
        effectiveFrom: '2026-09-01',
        streamId: (int) $foreignStream->id,
    ))->toThrow(DomainException::class, 'school section');

    expect(fn () => app(CreateFeeStructure::class)->handle(
        academicYearId: (int) $year->id,
        schoolSectionId: (int) $section->id,
        name: 'Ghost level',
        effectiveFrom: '2026-09-01',
        classLevelId: 999_999,
    ))->toThrow(DomainException::class, 'does not exist');
});

it('two annual lines of the same item collide - the term_id sentinel closes the NULL loophole', function () {
    $structure = FeeStructure::factory()->create();
    $item = FeeItem::factory()->create();

    FeeStructureLine::factory()->create([
        'fee_structure_id' => $structure->id,
        'fee_item_id' => $item->id,
    ]);

    expect(fn () => FeeStructureLine::factory()->create([
        'fee_structure_id' => $structure->id,
        'fee_item_id' => $item->id,
    ]))->toThrow(QueryException::class);
});

it('scores specificity per the §2.5 resolution weights', function () {
    $level = ClassLevel::factory()->create();

    $broad = FeeStructure::factory()->make();
    $narrow = FeeStructure::factory()->make([
        'class_level_id' => $level->id,
        'boarding_scope' => BoardingScope::Boarding,
        'enrollment_status_scope' => EnrollmentStatusScope::New,
    ]);

    expect($broad->specificityScore())->toBe(0)
        ->and($narrow->specificityScore())->toBe(8 + 2 + 1);
});

it('draft edits do not bump the version; publishing requires lines; published edits do bump it', function () {
    actingAs(feeStructureUserAs());

    $structure = FeeStructure::factory()->create();

    // Draft rename: no bump.
    $structure = app(UpdateFeeStructure::class)->handle((int) $structure->id, name: 'Renamed');
    expect($structure->version)->toBe(1)->and($structure->name)->toBe('Renamed');

    // Publishing an empty structure would bill nothing.
    expect(fn () => app(UpdateFeeStructure::class)->handle((int) $structure->id, status: FeeStructureStatus::Active))
        ->toThrow(DomainException::class, 'no lines');

    $item = FeeItem::factory()->create();
    FeeStructureLine::factory()->create(['fee_structure_id' => $structure->id, 'fee_item_id' => $item->id]);

    $structure = app(UpdateFeeStructure::class)->handle((int) $structure->id, status: FeeStructureStatus::Active);
    expect($structure->status)->toBe(FeeStructureStatus::Active)->and($structure->version)->toBe(1);

    // §2.5: version is bumped on every published change - invoices stamp it.
    $structure = app(UpdateFeeStructure::class)->handle((int) $structure->id, name: 'Renamed again');
    expect($structure->version)->toBe(2);
});

it('refuses edits to an archived structure and illegal status moves', function () {
    actingAs(feeStructureUserAs());

    $item = FeeItem::factory()->create();
    $active = FeeStructure::factory()->active()->create();
    FeeStructureLine::factory()->create(['fee_structure_id' => $active->id, 'fee_item_id' => $item->id]);

    // active -> draft is not a transition.
    expect(fn () => app(UpdateFeeStructure::class)->handle((int) $active->id, status: FeeStructureStatus::Draft))
        ->toThrow(DomainException::class, 'cannot move');

    $archived = app(UpdateFeeStructure::class)->handle((int) $active->id, status: FeeStructureStatus::Archived);

    expect(fn () => app(UpdateFeeStructure::class)->handle((int) $archived->id, name: 'Nope'))
        ->toThrow(DomainException::class, 'archived');
});

it('a percentage instalment plan must sum to exactly 1 000 000 bp', function () {
    actingAs(feeStructureUserAs());
    $year = AcademicYear::factory()->create();

    $lines = [
        ['sequence_no' => 1, 'label' => '1st', 'label_fr' => '1ere tranche', 'percentage_bp' => 400_000, 'due_date' => '2026-09-15'],
        ['sequence_no' => 2, 'label' => '2nd', 'label_fr' => '2eme tranche', 'percentage_bp' => 300_000, 'due_date' => '2027-01-10'],
        ['sequence_no' => 3, 'label' => '3rd', 'label_fr' => '3eme tranche', 'percentage_bp' => 299_999, 'due_date' => '2027-04-10'],
    ];

    expect(fn () => app(SaveInstallmentPlan::class)->handle((int) $year->id, 'Off by one', InstallmentBasis::Percentage, $lines))
        ->toThrow(DomainException::class, '1 000 000');

    $lines[2]['percentage_bp'] = 300_000;
    $plan = app(SaveInstallmentPlan::class)->handle((int) $year->id, 'Three tranches', InstallmentBasis::Percentage, $lines);

    expect($plan->lines)->toHaveCount(3)
        ->and($plan->lines->sum('percentage_bp'))->toBe(1_000_000);
});

it('each tranche carries exactly one of due_date or due_offset_days, and sequences are gapless', function () {
    actingAs(feeStructureUserAs());
    $year = AcademicYear::factory()->create();

    expect(fn () => app(SaveInstallmentPlan::class)->handle((int) $year->id, 'Both dates', InstallmentBasis::Percentage, [
        ['sequence_no' => 1, 'label' => '1st', 'label_fr' => '1ere', 'percentage_bp' => 1_000_000, 'due_date' => '2026-09-15', 'due_offset_days' => 10],
    ]))->toThrow(DomainException::class, 'exactly one');

    expect(fn () => app(SaveInstallmentPlan::class)->handle((int) $year->id, 'Gapped', InstallmentBasis::Percentage, [
        ['sequence_no' => 2, 'label' => '2nd', 'label_fr' => '2eme', 'percentage_bp' => 1_000_000, 'due_date' => '2026-09-15'],
    ]))->toThrow(DomainException::class, 'numbered 1..n');
});

it('admits one default plan per (year, structure) scope - the generated-column UNIQUE', function () {
    actingAs(feeStructureUserAs());
    $year = AcademicYear::factory()->create();

    $lines = [['sequence_no' => 1, 'label' => 'Full', 'label_fr' => 'Totalite', 'percentage_bp' => 1_000_000, 'due_date' => '2026-09-15']];

    app(SaveInstallmentPlan::class)->handle((int) $year->id, 'Default global', InstallmentBasis::Percentage, $lines, isDefault: true);

    // Second global default for the same year: refused by the index.
    expect(fn () => app(SaveInstallmentPlan::class)->handle((int) $year->id, 'Second default', InstallmentBasis::Percentage, $lines, isDefault: true))
        ->toThrow(QueryException::class);

    // A default scoped to a real structure coexists.
    $structure = FeeStructure::factory()->create(['academic_year_id' => $year->id]);
    $scoped = app(SaveInstallmentPlan::class)->handle((int) $year->id, 'Structure default', InstallmentBasis::Percentage, $lines, feeStructureId: (int) $structure->id, isDefault: true);

    expect($scoped->is_default)->toBeTrue();

    // Non-default plans are unlimited (generated column is NULL for them).
    $extra = app(SaveInstallmentPlan::class)->handle((int) $year->id, 'Extra plan', InstallmentBasis::Percentage, $lines);
    expect($extra->is_default)->toBeFalse();
});

it('a fixed-basis plan carries fixed amounts, never percentages', function () {
    actingAs(feeStructureUserAs());
    $year = AcademicYear::factory()->create();

    expect(fn () => app(SaveInstallmentPlan::class)->handle((int) $year->id, 'Mixed up', InstallmentBasis::Fixed, [
        ['sequence_no' => 1, 'label' => '1st', 'label_fr' => '1ere', 'percentage_bp' => 1_000_000, 'due_date' => '2026-09-15'],
    ]))->toThrow(DomainException::class, 'fixed_amount');

    $plan = app(SaveInstallmentPlan::class)->handle((int) $year->id, 'Fixed', InstallmentBasis::Fixed, [
        ['sequence_no' => 1, 'label' => '1st', 'label_fr' => '1ere', 'fixed_amount' => 120_000, 'due_date' => '2026-09-15'],
        ['sequence_no' => 2, 'label' => '2nd', 'label_fr' => '2eme', 'fixed_amount' => 230_000, 'due_offset_days' => 30],
    ]);

    expect($plan->basis)->toBe(InstallmentBasis::Fixed)
        ->and($plan->lines)->toHaveCount(2);
});

it('effective_to is exclusive and must follow effective_from', function () {
    actingAs(feeStructureUserAs());
    $year = AcademicYear::factory()->create();
    $section = SchoolSection::factory()->create();

    expect(fn () => app(CreateFeeStructure::class)->handle(
        academicYearId: (int) $year->id,
        schoolSectionId: (int) $section->id,
        name: 'Backwards',
        effectiveFrom: '2026-09-01',
        effectiveTo: '2026-09-01',
    ))->toThrow(DomainException::class, 'exclusive');
});

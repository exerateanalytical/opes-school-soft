<?php

declare(strict_types=1);

use App\Modules\Academics\Actions\CreateAcademicYear;
use App\Modules\Academics\Actions\DefineTermStructure;
use App\Modules\Academics\Domain\AssessmentPeriodType;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\AssessmentPeriod;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('academicsUserAs')) {
    /**
     * Duplicated (behind a function_exists guard) rather than imported from
     * AcademicYearTest.php: Pest files share one global function namespace and
     * their load order is not guaranteed, so each file must be able to stand
     * alone. Named academicsUserAs, not userAs, to avoid colliding with the
     * Identity suite's helper.
     */
    function academicsUserAs(bool $withPermission = true): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(CreateAcademicYear::PERMISSION, 'web');

        $user = User::factory()->create();

        if ($withPermission) {
            $user->givePermissionTo(CreateAcademicYear::PERMISSION);
        }

        return $user->fresh() ?? $user;
    }
}

/**
 * A 2026/2027 year created through the real Action so the invariants hold.
 */
function academicsYear(User $user): AcademicYear
{
    return app(CreateAcademicYear::class)->handle(
        code: '2026-2027',
        name: 'Academic Year 2026/2027',
        startsOn: '2026-09-01',
        endsOn: '2027-08-31',
        actor: $user->toAuditActor(),
    );
}

it('creates a year root plus three contiguous terms within the year', function () {
    $user = academicsUserAs();
    actingAs($user);
    $year = academicsYear($user);

    $root = app(DefineTermStructure::class)->handle($year->id, 3, [
        ['starts_on' => '2026-09-01', 'ends_on' => '2026-12-19'],
        ['starts_on' => '2026-12-20', 'ends_on' => '2027-03-27'],
        ['starts_on' => '2027-03-28', 'ends_on' => '2027-07-15'],
    ], $user->toAuditActor());

    expect($root->type)->toBe(AssessmentPeriodType::Year);
    expect($root->parent_id)->toBeNull();
    expect($root->starts_on->toDateString())->toBe('2026-09-01');
    expect($root->ends_on->toDateString())->toBe('2027-08-31');

    $terms = $root->children->sortBy('order_index')->values();

    expect($terms)->toHaveCount(3);
    expect($terms->pluck('code')->all())->toBe(['T1', 'T2', 'T3']);
    expect($terms->pluck('name')->all())->toBe(['Term 1', 'Term 2', 'Term 3']);
    expect($terms->pluck('name_fr')->all())->toBe(['Trimestre 1', 'Trimestre 2', 'Trimestre 3']);

    foreach ($terms as $term) {
        expect($term->type)->toBe(AssessmentPeriodType::Term);
        expect($term->parent_id)->toBe($root->id);
        expect($term->is_reporting_period)->toBeTrue();
    }

    // Contiguity: each term starts the day after the previous one ends.
    [$t1, $t2, $t3] = $terms->all();
    expect($t2->starts_on->toDateString())
        ->toBe($t1->ends_on->copy()->addDay()->toDateString());
    expect($t3->starts_on->toDateString())
        ->toBe($t2->ends_on->copy()->addDay()->toDateString());

    expect(AssessmentPeriod::query()->where('academic_year_id', $year->id)->count())->toBe(4);
});

it('creates a two-term structure with Semestre naming', function () {
    $user = academicsUserAs();
    actingAs($user);
    $year = academicsYear($user);

    $root = app(DefineTermStructure::class)->handle($year->id, 2, [
        ['starts_on' => '2026-09-01', 'ends_on' => '2027-01-31'],
        ['starts_on' => '2027-02-01', 'ends_on' => '2027-07-15'],
    ], $user->toAuditActor());

    expect($root->children)->toHaveCount(2);
    expect($root->children->sortBy('order_index')->pluck('name_fr')->all())
        ->toBe(['Semestre 1', 'Semestre 2']);
});

it('rejects overlapping term dates', function () {
    $user = academicsUserAs();
    actingAs($user);
    $year = academicsYear($user);

    expect(fn () => app(DefineTermStructure::class)->handle($year->id, 2, [
        ['starts_on' => '2026-09-01', 'ends_on' => '2027-01-31'],
        ['starts_on' => '2027-01-15', 'ends_on' => '2027-07-15'], // overlaps T1
    ], $user->toAuditActor()))->toThrow(DomainException::class, 'contiguous');

    expect(AssessmentPeriod::query()->count())->toBe(0);
});

it('rejects a gap between terms', function () {
    $user = academicsUserAs();
    actingAs($user);
    $year = academicsYear($user);

    expect(fn () => app(DefineTermStructure::class)->handle($year->id, 2, [
        ['starts_on' => '2026-09-01', 'ends_on' => '2027-01-31'],
        ['starts_on' => '2027-02-10', 'ends_on' => '2027-07-15'], // 9-day gap
    ], $user->toAuditActor()))->toThrow(DomainException::class, 'must start on 2027-02-01');
});

it('rejects terms that fall outside the academic year', function () {
    $user = academicsUserAs();
    actingAs($user);
    $year = academicsYear($user);

    expect(fn () => app(DefineTermStructure::class)->handle($year->id, 2, [
        ['starts_on' => '2026-08-15', 'ends_on' => '2027-01-31'], // before the year starts
        ['starts_on' => '2027-02-01', 'ends_on' => '2027-07-15'],
    ], $user->toAuditActor()))->toThrow(DomainException::class, 'outside academic year');
});

it('rejects an invalid term count', function () {
    $user = academicsUserAs();
    actingAs($user);
    $year = academicsYear($user);

    expect(fn () => app(DefineTermStructure::class)->handle($year->id, 4, [
        ['starts_on' => '2026-09-01', 'ends_on' => '2026-11-30'],
        ['starts_on' => '2026-12-01', 'ends_on' => '2027-02-28'],
        ['starts_on' => '2027-03-01', 'ends_on' => '2027-05-31'],
        ['starts_on' => '2027-06-01', 'ends_on' => '2027-07-15'],
    ], $user->toAuditActor()))->toThrow(DomainException::class, '2 or 3 terms');
});

it('rejects a second structure for the same year', function () {
    $user = academicsUserAs();
    actingAs($user);
    $year = academicsYear($user);
    $define = app(DefineTermStructure::class);

    $dates = [
        ['starts_on' => '2026-09-01', 'ends_on' => '2027-01-31'],
        ['starts_on' => '2027-02-01', 'ends_on' => '2027-07-15'],
    ];

    $define->handle($year->id, 2, $dates, $user->toAuditActor());

    expect(fn () => $define->handle($year->id, 2, $dates, $user->toAuditActor()))
        ->toThrow(DomainException::class, 'already exists');

    expect(AssessmentPeriod::query()->where('academic_year_id', $year->id)->count())->toBe(3);
});

it('denies structure definition to a user without academics.manage', function () {
    $admin = academicsUserAs();
    actingAs($admin);
    $year = academicsYear($admin);

    $unprivileged = academicsUserAs(withPermission: false);
    actingAs($unprivileged);

    expect(fn () => app(DefineTermStructure::class)->handle($year->id, 2, [
        ['starts_on' => '2026-09-01', 'ends_on' => '2027-01-31'],
        ['starts_on' => '2027-02-01', 'ends_on' => '2027-07-15'],
    ], $unprivileged->toAuditActor()))->toThrow(AuthorizationException::class);

    expect(AssessmentPeriod::query()->count())->toBe(0);
});

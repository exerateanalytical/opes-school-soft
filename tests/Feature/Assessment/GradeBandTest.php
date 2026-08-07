<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\ConfigureGradeBands;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\GradeBand;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Score\Score;
use Database\Factories\GradeBandFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function bandUserAs(Role $role): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

/**
 * The Family A worked example of 01-assessment 3.3, with one band's bounds
 * replaced so the ladder breaks in exactly one place.
 *
 * @return list<array<string, mixed>>
 */
function ladderWithBrokenBand(int $index, ?string $min, ?string $max): array
{
    $ladder = GradeBandFactory::familyAInternalLadder();

    if ($min !== null) {
        $ladder[$index]['min_score'] = $min;
    }

    if ($max !== null) {
        $ladder[$index]['max_score'] = $max;
    }

    return $ladder;
}

it('saves the complete Family A ladder', function () {
    $user = bandUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();

    $bands = app(ConfigureGradeBands::class)->handle(
        (int) $framework->getKey(),
        GradeBandFactory::familyAInternalLadder(),
        $user->toAuditActor(),
    );

    expect($bands)->toHaveCount(6);
    expect($bands[0]->min_score)->toBe('0.000');
    expect($bands[5]->max_score)->toBe('20.000');
    // order_index is assigned from the SORTED ladder, so the printed order
    // always matches the numeric order regardless of input order.
    expect($bands[5]->order_index)->toBe(6);
});

// --- T12, the three cases the obligation names ------------------------------

it('T12 rejects a gap in the ladder', function () {
    $user = bandUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();

    // Band [10, 12) shortened to [10, 11): 11..12 bands to nothing.
    expect(fn () => app(ConfigureGradeBands::class)->handle(
        (int) $framework->getKey(),
        ladderWithBrokenBand(2, null, '11.000'),
        $user->toAuditActor(),
    ))->toThrow(DomainException::class, 'Gap in the grade ladder');

    // Nothing was written: the ladder is saved as a set or not at all.
    expect(GradeBand::query()->count())->toBe(0);
});

it('T12 rejects an overlap in the ladder', function () {
    $user = bandUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();

    // Band [10, 12) stretched to [10, 13): 12..13 bands two ways.
    expect(fn () => app(ConfigureGradeBands::class)->handle(
        (int) $framework->getKey(),
        ladderWithBrokenBand(2, null, '13.000'),
        $user->toAuditActor(),
    ))->toThrow(DomainException::class, 'Overlap in the grade ladder');

    expect(GradeBand::query()->count())->toBe(0);
});

it('T12 rejects an open top band', function () {
    $user = bandUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();

    // The ladder stops at 18 on a /20 framework, so 19 prints a blank
    // mention - v1's blank-grade bug arriving by another door.
    expect(fn () => app(ConfigureGradeBands::class)->handle(
        (int) $framework->getKey(),
        ladderWithBrokenBand(5, null, '18.000'),
        $user->toAuditActor(),
    ))->toThrow(DomainException::class, 'The top band is open');

    expect(GradeBand::query()->count())->toBe(0);
});

// --- the neighbouring failures the same validator has to catch --------------

it('rejects a ladder that does not start at zero', function () {
    $user = bandUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();

    expect(fn () => app(ConfigureGradeBands::class)->handle(
        (int) $framework->getKey(),
        ladderWithBrokenBand(0, '1.000', null),
        $user->toAuditActor(),
    ))->toThrow(DomainException::class, 'must start at 0');
});

it('rejects a top band running past the scale ceiling', function () {
    $user = bandUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();

    expect(fn () => app(ConfigureGradeBands::class)->handle(
        (int) $framework->getKey(),
        ladderWithBrokenBand(5, null, '25.000'),
        $user->toAuditActor(),
    ))->toThrow(DomainException::class, 'past the scale ceiling');
});

it('rejects an empty or inverted band and an empty ladder', function () {
    $user = bandUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();
    $id = (int) $framework->getKey();
    $actor = $user->toAuditActor();

    expect(fn () => app(ConfigureGradeBands::class)->handle($id, ladderWithBrokenBand(2, '12.000', null), $actor))
        ->toThrow(DomainException::class, 'empty or inverted');

    expect(fn () => app(ConfigureGradeBands::class)->handle($id, [], $actor))
        ->toThrow(DomainException::class, 'at least one band');
});

it('measures the ceiling against the basis, not the framework, for percentage bands', function () {
    // The v1 defect this guards: a /20 framework matched no percentage band
    // and printed a blank grade, because nothing said which basis a band was
    // expressed in.
    $user = bandUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();

    // A /20 ladder is an OPEN top band when read as a percentage ladder.
    expect(fn () => app(ConfigureGradeBands::class)->handle(
        (int) $framework->getKey(),
        GradeBandFactory::familyAInternalLadder(),
        $user->toAuditActor(),
        GradeBand::PURPOSE_INTERNAL,
        GradeBand::BASIS_PERCENTAGE,
    ))->toThrow(DomainException::class, 'the scale runs to 100.000');
});

it('replaces one tuple without touching another on the same framework', function () {
    // An internal /20 ladder and an O-Level percentage ladder coexist on one
    // framework (01-assessment 3.3) and must not delete each other.
    $user = bandUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();
    $id = (int) $framework->getKey();
    $actor = $user->toAuditActor();

    app(ConfigureGradeBands::class)->handle($id, GradeBandFactory::familyAInternalLadder(), $actor);

    app(ConfigureGradeBands::class)->handle($id, [
        ['min_score' => '0.000', 'max_score' => '50.000', 'label' => 'Fail', 'label_fr' => 'Échec'],
        ['min_score' => '50.000', 'max_score' => '100.000', 'label' => 'Pass', 'label_fr' => 'Admis'],
    ], $actor, GradeBand::PURPOSE_EXAM_O_LEVEL, GradeBand::BASIS_PERCENTAGE);

    expect(GradeBand::query()->where('purpose', GradeBand::PURPOSE_INTERNAL)->count())->toBe(6);
    expect(GradeBand::query()->where('purpose', GradeBand::PURPOSE_EXAM_O_LEVEL)->count())->toBe(2);

    // Re-saving the internal ladder replaces it in place, no duplicates.
    app(ConfigureGradeBands::class)->handle($id, GradeBandFactory::familyAInternalLadder(), $actor);
    expect(GradeBand::query()->count())->toBe(8);
});

it('bands 12.00 as Assez Bien and 20.00 as Très Bien', function () {
    // 01-assessment 3.3's worked example. Half-open below, closed at the top,
    // so a perfect score bands instead of falling off the end.
    $user = bandUserAs(Role::VicePrincipal);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();

    $bands = app(ConfigureGradeBands::class)->handle(
        (int) $framework->getKey(),
        GradeBandFactory::familyAInternalLadder(),
        $user->toAuditActor(),
    );

    $last = count($bands) - 1;

    expect($bands[3]->contains(Score::of('12.000'), false))->toBeTrue();
    expect($bands[2]->contains(Score::of('12.000'), false))->toBeFalse();
    expect($bands[3]->label_fr)->toBe('Assez Bien');

    expect($bands[$last]->contains(Score::of('20.000'), true))->toBeTrue();
    // The same band with an open upper bound would drop a perfect score.
    expect($bands[$last]->contains(Score::of('20.000'), false))->toBeFalse();
    expect($bands[$last]->label_fr)->toBe('Très Bien');
});

it('refuses to configure bands without assessment.configure', function () {
    $user = bandUserAs(Role::Teacher);
    actingAs($user);

    $framework = AssessmentFramework::factory()->create();

    app(ConfigureGradeBands::class)->handle(
        (int) $framework->getKey(),
        GradeBandFactory::familyAInternalLadder(),
        $user->toAuditActor(),
    );
})->throws(AuthorizationException::class);

it('compares band bounds in thousandths, so a hairline gap is still a gap', function () {
    // 00-core 7.1: a float comparison cannot tell 12.000 from 11.999.
    $ladder = GradeBandFactory::familyAInternalLadder();
    $ladder[2]['max_score'] = '11.999';

    expect(fn () => ConfigureGradeBands::validateCoverage($ladder, Score::of('20.000')))
        ->toThrow(DomainException::class, 'Gap in the grade ladder');
});

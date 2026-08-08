<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\SaveMark;
use App\Modules\Assessment\Actions\SaveMarkBatch;
use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Models\Mark;
use App\Modules\Identity\Domain\Role;
use Database\Factories\MarkFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('markStateUserAs')) {
    function markStateUserAs(Role $role): App\Modules\Identity\Models\User
    {
        (new \Database\Seeders\RolePermissionSeeder)->run();
        $user = App\Modules\Identity\Models\User::factory()->create(['name' => 'Mme Fotso']);
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

/**
 * 6.4's semantics table, asserted as a table. These four predicates are what
 * the composition stage reads, so a change to any of them silently moves every
 * student in the school.
 */
it('carries the 6.4 semantics table faithfully', function () {
    $expected = [
        // state => [retainsWeight, renormalises, requiresScore, blocksPublication, printedMarker]
        'scored' => [true, false, true, false, null],
        'absent_unjustified' => [true, false, false, false, 'AbNJ'],
        'absent_justified' => [false, true, false, false, 'AbJ'],
        'exempt' => [false, true, false, false, 'Disp.'],
        'pending' => [false, false, false, true, null],
    ];

    foreach ($expected as $value => [$retains, $renorm, $needsScore, $blocks, $marker]) {
        $state = MarkState::from($value);

        expect($state->retainsWeight())->toBe($retains, "retainsWeight for {$value}")
            ->and($state->renormalises())->toBe($renorm, "renormalises for {$value}")
            ->and($state->requiresScore())->toBe($needsScore, "requiresScore for {$value}")
            ->and($state->blocksPublication())->toBe($blocks, "blocksPublication for {$value}")
            ->and($state->printedMarker())->toBe($marker, "printedMarker for {$value}");
    }
});

it('never lets a missing component collapse into a zero', function () {
    // An unexcused absence keeps its weight and scores 0; a certified absence
    // and an exemption vanish from the denominator. Reading "score is null" as
    // one fact would make all three the same, and 6.4's worked cases differ by
    // 8.40 points out of 20 on exactly that distinction.
    expect(MarkState::AbsentUnjustified->retainsWeight())->toBeTrue()
        ->and(MarkState::AbsentJustified->retainsWeight())->toBeFalse()
        ->and(MarkState::Exempt->retainsWeight())->toBeFalse()
        ->and(MarkState::Pending->retainsWeight())->toBeFalse()
        ->and(MarkState::Pending->isResolved())->toBeFalse()
        ->and(MarkState::AbsentJustified->isResolved())->toBeTrue();
});

it('persists all four resolved states with a NULL score for three of them', function () {
    actingAs(markStateUserAs(Role::ExamsOfficer));
    $scenario = MarkFactory::scenario(students: 4);

    $rows = [
        ['state' => MarkState::Scored, 'score' => '14.000', 'comment' => null],
        ['state' => MarkState::AbsentUnjustified, 'score' => null, 'comment' => null],
        ['state' => MarkState::AbsentJustified, 'score' => null, 'comment' => 'Medical certificate on file'],
        ['state' => MarkState::Exempt, 'score' => null, 'comment' => 'Medical exemption from EPS'],
    ];

    foreach ($rows as $i => $row) {
        $mark = Mark::query()->findOrFail($scenario['marks'][$i]);

        expect(app(SaveMark::class)->handle($mark, $row['state'], $row['score'], $row['comment']))->toBeNull();

        $mark->refresh();

        expect($mark->state)->toBe($row['state'])
            ->and($mark->score)->toBe($row['score']);
    }

    // Three of the four carry no number, and all three mean something
    // different. The column cannot tell them apart; `state` can.
    expect(Mark::query()->whereNull('score')->count())->toBe(3)
        ->and(Mark::query()->where('state', 'pending')->count())->toBe(0);
});

it('refuses to certify an absence or grant an exemption without a reason', function () {
    actingAs(markStateUserAs(Role::ExamsOfficer));
    $scenario = MarkFactory::scenario(students: 2);

    $justified = Mark::query()->findOrFail($scenario['marks'][0]);
    $exempt = Mark::query()->findOrFail($scenario['marks'][1]);

    expect(fn () => app(SaveMark::class)->handle($justified, MarkState::AbsentJustified))
        ->toThrow(DomainException::class, 'requires a reason');

    expect(fn () => app(SaveMark::class)->handle($exempt, MarkState::Exempt))
        ->toThrow(DomainException::class, 'requires a reason');

    // An unexcused absence needs none - it is the default consequence of not
    // turning up, not a decision someone must justify.
    expect(app(SaveMark::class)->handle(
        Mark::query()->findOrFail($scenario['marks'][0]),
        MarkState::AbsentUnjustified,
    ))->toBeNull();
});

it('refuses a score arriving under a state that cannot carry one', function () {
    actingAs(markStateUserAs(Role::ExamsOfficer));
    $scenario = MarkFactory::scenario(students: 1);

    expect(fn () => app(SaveMarkBatch::class)->handle(
        subjectAllocationId: $scenario['allocation'],
        assessmentPeriodId: $scenario['period'],
        rows: [[
            'mark_id' => $scenario['marks'][0],
            'version' => 1,
            'state' => MarkState::AbsentUnjustified->value,
            'score' => '0.000',
        ]],
    ))->toThrow(DomainException::class, 'a missing component is not a zero');

    expect(fn () => app(SaveMarkBatch::class)->handle(
        subjectAllocationId: $scenario['allocation'],
        assessmentPeriodId: $scenario['period'],
        rows: [[
            'mark_id' => $scenario['marks'][0],
            'version' => 1,
            'state' => MarkState::Scored->value,
            'score' => null,
        ]],
    ))->toThrow(DomainException::class, 'must carry a number');
});

it('clears a cell back to pending, which is not the same as a zero', function () {
    actingAs(markStateUserAs(Role::ExamsOfficer));
    $scenario = MarkFactory::scenario(students: 1);

    $mark = Mark::query()->findOrFail($scenario['marks'][0]);
    app(SaveMark::class)->handle($mark, MarkState::Scored, '14.000');
    $mark->refresh();

    app(SaveMark::class)->handle($mark, MarkState::Pending);
    $mark->refresh();

    expect($mark->state)->toBe(MarkState::Pending)
        ->and($mark->score)->toBeNull()
        ->and($mark->state->blocksPublication())->toBeTrue();

    expect(DB::table('marks')->where('id', $mark->getKey())->value('score'))->toBeNull();
});

it('offers exactly the four states the entry grid can set', function () {
    expect(MarkState::enterableCases())->toBe([
        MarkState::Scored,
        MarkState::AbsentUnjustified,
        MarkState::AbsentJustified,
        MarkState::Exempt,
    ]);
});

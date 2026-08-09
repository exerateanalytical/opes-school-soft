<?php

declare(strict_types=1);

use App\Modules\Operations\Domain\Licensing\EntitlementDecision;
use App\Modules\Operations\Domain\Licensing\LicenceState;
use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\Licence;
use App\Modules\Operations\Models\RolloverArtifact;
use App\Modules\Operations\Models\RolloverBalanceCarry;
use App\Modules\Operations\Models\RolloverRun;
use App\Modules\Students\Models\PromotionDecision;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// rollover_runs
// ---------------------------------------------------------------------------

it('persists a rollover run and reads its enums back', function () {
    $run = RolloverRun::factory()->create();

    expect($run->status())->toBe(RolloverRunStatus::Running);
    expect($run->currentStep())->toBe(RolloverStep::CopyClassGroups);
    expect($run->step_states)->toBeArray();
    expect($run->inputs_hash)->toHaveLength(64);
    expect($run->academic_year_to_id)->not->toBeNull();
});

it('leaves the target year null at pre-flight, as StartRolloverRun will', function () {
    $run = RolloverRun::factory()->atPreflight()->create();

    expect($run->academic_year_to_id)->toBeNull();
    expect($run->currentStep())->toBe(RolloverStep::Preflight);
    expect($run->step_states)->toBeNull();
});

it('refuses a second run for the same from-to year pair by constraint', function () {
    // 08-operations §6.3: "Idempotent. UNIQUE(academic_year_from_id,
    // academic_year_to_id) on RolloverRun."
    $run = RolloverRun::factory()->create();

    expect(fn () => RolloverRun::factory()->create([
        'academic_year_from_id' => $run->academic_year_from_id,
        'academic_year_to_id' => $run->academic_year_to_id,
    ]))->toThrow(QueryException::class);
});

it('rejects a status outside the four-state lifecycle at the column', function () {
    $run = RolloverRun::factory()->create();

    expect(fn () => DB::table('rollover_runs')
        ->where('id', $run->id)
        ->update(['status' => 'paused']))->toThrow(QueryException::class);
});

it('refuses to delete an academic year a run references', function () {
    $run = RolloverRun::factory()->create();

    expect(fn () => DB::table('academic_years')
        ->where('id', $run->academic_year_from_id)
        ->delete())->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// rollover_artifacts
// ---------------------------------------------------------------------------

it('records an artifact once per (run, entity type, entity id)', function () {
    $artifact = RolloverArtifact::factory()->create();

    expect($artifact->step())->toBe(RolloverStep::CopyClassGroups);

    // Idempotent resume: re-recording the same created row must fail loudly
    // at the schema so the step Action can skip-by-natural-key instead.
    expect(fn () => RolloverArtifact::factory()->create([
        'rollover_run_id' => $artifact->rollover_run_id,
        'entity_type' => $artifact->entity_type,
        'entity_id' => $artifact->entity_id,
    ]))->toThrow(QueryException::class);
});

it('allows the same entity to appear under two different runs', function () {
    // An undone rollover re-run creates the same natural rows with new ids,
    // but a scratch dry-run may legitimately log identical (type, id) pairs
    // under its own run - uniqueness is per run, not global.
    $first = RolloverArtifact::factory()->create();

    $second = RolloverArtifact::factory()->create([
        'entity_type' => $first->entity_type,
        'entity_id' => $first->entity_id,
    ]);

    expect($second->rollover_run_id)->not->toBe($first->rollover_run_id);
});

it('exposes artifacts and carries from the run', function () {
    $run = RolloverRun::factory()->create();
    RolloverArtifact::factory()->count(2)->create(['rollover_run_id' => $run->id]);
    RolloverBalanceCarry::factory()->create(['rollover_run_id' => $run->id]);

    expect($run->artifacts()->count())->toBe(2);
    expect($run->balanceCarries()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// promotion_decisions
// ---------------------------------------------------------------------------

it('records exactly one promotion decision per enrollment', function () {
    $decision = PromotionDecision::factory()->create();

    expect($decision->decision)->toBe(PromotionDecision::DECISION_PROMOTED);
    expect($decision->enrollment()->value('id'))->toBe($decision->enrollment_id);

    // §6.2 step 6 consumes ONE decision per enrollment; a second is a
    // constraint violation, not a silent overwrite.
    expect(fn () => PromotionDecision::factory()->create([
        'enrollment_id' => $decision->enrollment_id,
    ]))->toThrow(QueryException::class);
});

it('rejects a decision outside the four-value vocabulary at the column', function () {
    $decision = PromotionDecision::factory()->create();

    expect(fn () => DB::table('promotion_decisions')
        ->where('id', $decision->id)
        ->update(['decision' => 'conditional']))->toThrow(QueryException::class);
});

it('allows a graduated decision to carry no target class group', function () {
    $decision = PromotionDecision::factory()->graduated()->create();

    expect($decision->decision)->toBe(PromotionDecision::DECISION_GRADUATED);
    expect($decision->target_class_group_key)->toBeNull();
});

// ---------------------------------------------------------------------------
// licences
// ---------------------------------------------------------------------------

it('caches a file licence with its payload and signature verbatim', function () {
    $licence = Licence::factory()->create();

    expect($licence->source)->toBe(Licence::SOURCE_FILE);
    expect($licence->payload)->toBeArray();
    expect($licence->payload['product'])->toBe('opes-school');
    expect($licence->fingerprint)->toBeNull();
    expect($licence->revoked_at)->toBeNull();
});

it('binds an activated licence to a machine fingerprint', function () {
    $licence = Licence::factory()->activated()->create();

    expect($licence->source)->toBe(Licence::SOURCE_ACTIVATION);
    // SHA-256 lowercase hex (08-operations §4.3).
    expect($licence->fingerprint)->toMatch('/^[0-9a-f]{64}$/');
    expect($licence->next_check_after)->not->toBeNull();
});

it('rejects a licence source outside file or activation', function () {
    $licence = Licence::factory()->create();

    expect(fn () => DB::table('licences')
        ->where('id', $licence->id)
        ->update(['source' => 'crack']))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// rollover_balance_carries
// ---------------------------------------------------------------------------

it('records one step-7 outcome per student, kind and run', function () {
    $carry = RolloverBalanceCarry::factory()->create();

    expect($carry->kind)->toBe(RolloverBalanceCarry::KIND_CREDIT_CARRY);
    expect($carry->amount)->toBeGreaterThan(0);
    expect($carry->journal_entry_id)->toBeNull();

    expect(fn () => RolloverBalanceCarry::factory()->create([
        'rollover_run_id' => $carry->rollover_run_id,
        'student_id' => $carry->student_id,
        'kind' => $carry->kind,
    ]))->toThrow(QueryException::class);
});

it('rejects a negative carry amount at the schema', function () {
    // Never nets across students (04-fees C9): the amount is always the
    // absolute value the kind acts on - a negative here would smuggle a net.
    $carry = RolloverBalanceCarry::factory()->create();

    expect(fn () => DB::table('rollover_balance_carries')
        ->where('id', $carry->id)
        ->update(['amount' => -500]))->toThrow(QueryException::class);
});

it('accepts a block outcome with no journal reference', function () {
    $block = RolloverBalanceCarry::factory()->block()->create(['amount' => 0]);

    expect($block->kind)->toBe(RolloverBalanceCarry::KIND_BLOCK);
    expect($block->journal_entry_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Domain enums
// ---------------------------------------------------------------------------

it('walks the eleven rollover steps strictly in order', function () {
    $step = RolloverStep::Preflight;
    $visited = [];

    while ($step !== null) {
        $visited[] = $step->value;
        $step = $step->next();
    }

    expect($visited)->toBe(range(0, 10));
    expect(RolloverStep::Preflight->isFirst())->toBeTrue();
    expect(RolloverStep::FlipActiveYear->isLast())->toBeTrue();
    expect(RolloverStep::FlipActiveYear->next())->toBeNull();
});

it('marks only the copy and people steps as artifact-creating', function () {
    // Preflight validates and FlipActiveYear mutates in place - neither
    // leaves a row for the undo ledger.
    expect(RolloverStep::Preflight->createsArtifacts())->toBeFalse();
    expect(RolloverStep::FlipActiveYear->createsArtifacts())->toBeFalse();

    foreach (range(1, 9) as $ordinal) {
        expect(RolloverStep::from($ordinal)->createsArtifacts())->toBeTrue();
    }
});

it('labels every rollover step distinctly in English and French', function () {
    foreach (['en', 'fr'] as $locale) {
        $labels = array_map(
            fn (RolloverStep $step): string => $step->label($locale),
            RolloverStep::cases(),
        );

        expect(count(array_unique($labels)))->toBe(count($labels));

        foreach ($labels as $label) {
            expect($label)->not->toContain('rollover.step');
        }
    }
});

it('treats running and failed runs as resumable, completed and undone as final', function () {
    expect(RolloverRunStatus::Running->isResumable())->toBeTrue();
    expect(RolloverRunStatus::Failed->isResumable())->toBeTrue();
    expect(RolloverRunStatus::Completed->isResumable())->toBeFalse();
    expect(RolloverRunStatus::Undone->isResumable())->toBeFalse();

    expect(RolloverRunStatus::Completed->isTerminal())->toBeTrue();
    expect(RolloverRunStatus::Undone->isTerminal())->toBeTrue();
    expect(RolloverRunStatus::Running->isTerminal())->toBeFalse();
    expect(RolloverRunStatus::Failed->isTerminal())->toBeFalse();

    expect(RolloverRunStatus::Undone->isUndoable())->toBeFalse();
    expect(RolloverRunStatus::Completed->isUndoable())->toBeTrue();
});

it('blocks entitlement only when enforced or revoked, per the 4.4 table', function () {
    // Valid, trial, expiring and GRACE all allow everything - an expired
    // licence never stops a Tuesday (08-operations §4.4).
    expect(LicenceState::Valid->decision())->toBe(EntitlementDecision::Allowed);
    expect(LicenceState::Trial->decision())->toBe(EntitlementDecision::Allowed);
    expect(LicenceState::Expiring->decision())->toBe(EntitlementDecision::Allowed);
    expect(LicenceState::Grace->decision())->toBe(EntitlementDecision::Allowed);

    expect(LicenceState::Enforced->decision())->toBe(EntitlementDecision::Blocked);
    expect(LicenceState::Revoked->decision())->toBe(EntitlementDecision::Blocked);

    expect(EntitlementDecision::Allowed->allows())->toBeTrue();
    expect(EntitlementDecision::Blocked->allows())->toBeFalse();
});

it('shows a banner for every state past valid and trial', function () {
    expect(LicenceState::Valid->showsBanner())->toBeFalse();
    expect(LicenceState::Trial->showsBanner())->toBeFalse();
    expect(LicenceState::Expiring->showsBanner())->toBeTrue();
    expect(LicenceState::Grace->showsBanner())->toBeTrue();
    expect(LicenceState::Enforced->showsBanner())->toBeTrue();
    expect(LicenceState::Revoked->showsBanner())->toBeTrue();
});

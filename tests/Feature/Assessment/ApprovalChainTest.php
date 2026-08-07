<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\RejectMarks;
use App\Modules\Assessment\Actions\SaveMark;
use App\Modules\Assessment\Actions\SubmitMarksForValidation;
use App\Modules\Assessment\Actions\ValidateMarks;
use App\Modules\Assessment\Domain\ApprovalDecision;
use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Domain\WorkflowState;
use App\Modules\Assessment\Models\Mark;
use App\Modules\Assessment\Models\MarkApproval;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Database\Factories\MarkFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('approvalUserAs')) {
    function approvalUserAs(Role $role, string $name): User
    {
        (new \Database\Seeders\RolePermissionSeeder)->run();
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

/**
 * @return array{scenario: array{framework: int, component: int, period: int, allocation: int, class_group: int, year: int, level: int, section: int, enrollments: list<int>, marks: list<int>}, entrant: User, approver: User}
 */
function approvalScenario(int $students = 3): array
{
    // Two people, deliberately: 7.2's flow is two-person and the
    // Vice-Principal holds marks.validate WITHOUT marks.enter, so an approver
    // cannot also be the author.
    $entrant = approvalUserAs(Role::ExamsOfficer, 'Mme Fotso');
    $approver = approvalUserAs(Role::VicePrincipal, 'M. Njoya');

    $scenario = MarkFactory::scenario(students: $students);

    actingAs($entrant);

    foreach ($scenario['marks'] as $i => $markId) {
        app(SaveMark::class)->handle(
            Mark::query()->findOrFail($markId),
            MarkState::Scored,
            sprintf('%d.000', 10 + $i),
        );
    }

    return ['scenario' => $scenario, 'entrant' => $entrant, 'approver' => $approver];
}

it('submits a grid, writing a batch header that names the submitter', function () {
    ['scenario' => $s, 'entrant' => $entrant] = approvalScenario();

    actingAs($entrant);
    $result = app(SubmitMarksForValidation::class)->handle(
        $s['allocation'],
        $s['period'],
        $s['class_group'],
    );

    expect($result['submitted'])->toBe(3)
        ->and($result['still_pending'])->toBe(0)
        ->and($result['approval']->status)->toBe(MarkApproval::STATUS_SUBMITTED)
        ->and($result['approval']->last_decision)->toBe(ApprovalDecision::Submit)
        ->and($result['approval']->submitted_by)->toBe((int) $entrant->getKey())
        ->and($result['approval']->submitted_at)->not->toBeNull();

    expect(Mark::query()->where('workflow_state', WorkflowState::Submitted->value)->count())->toBe(3);

    expect(AuditLog::query()
        ->where('module', 'Assessment')
        ->where('auditable_type', MarkApproval::class)
        ->count())->toBe(1);
});

it('counts pending marks rather than sweeping them along with the submission', function () {
    ['scenario' => $s, 'entrant' => $entrant] = approvalScenario();

    DB::table('marks')->where('id', $s['marks'][2])->update([
        'state' => MarkState::Pending->value,
        'score' => null,
    ]);

    actingAs($entrant);
    $result = app(SubmitMarksForValidation::class)->handle(
        $s['allocation'],
        $s['period'],
        $s['class_group'],
    );

    expect($result['submitted'])->toBe(2)
        ->and($result['still_pending'])->toBe(1);

    // The pending row stays in draft; 13.2's publication gate is what finally
    // stops on it, and it must still be visible there.
    expect(Mark::query()->findOrFail($s['marks'][2])->workflow_state)->toBe(WorkflowState::Draft);
});

it('validates a submitted grid, stamping the validator on every mark', function () {
    ['scenario' => $s, 'entrant' => $entrant, 'approver' => $approver] = approvalScenario();

    actingAs($entrant);
    app(SubmitMarksForValidation::class)->handle($s['allocation'], $s['period'], $s['class_group']);

    actingAs($approver);
    $result = app(ValidateMarks::class)->handle($s['allocation'], $s['period'], $s['class_group']);

    expect($result['validated'])->toBe(3)
        ->and($result['approval']->status)->toBe(MarkApproval::STATUS_VALIDATED)
        ->and($result['approval']->validated_by)->toBe((int) $approver->getKey())
        ->and($result['approval']->last_decision)->toBe(ApprovalDecision::Validate);

    $mark = Mark::query()->findOrFail($s['marks'][0]);

    expect($mark->workflow_state)->toBe(WorkflowState::Validated)
        ->and($mark->validated_by)->toBe((int) $approver->getKey())
        // A validated mark is still a 10.000 - the two axes never merged.
        ->and($mark->state)->toBe(MarkState::Scored)
        ->and($mark->score)->toBe('10.000');
});

it('refuses to validate marks that were never submitted', function () {
    ['scenario' => $s, 'approver' => $approver] = approvalScenario();

    actingAs($approver);

    expect(fn () => app(ValidateMarks::class)->handle($s['allocation'], $s['period'], $s['class_group']))
        ->toThrow(DomainException::class, 'have not been submitted');

    expect(Mark::query()->where('workflow_state', WorkflowState::Validated->value)->count())->toBe(0);
});

it('refuses a second validation of the same batch', function () {
    ['scenario' => $s, 'entrant' => $entrant, 'approver' => $approver] = approvalScenario();

    actingAs($entrant);
    app(SubmitMarksForValidation::class)->handle($s['allocation'], $s['period'], $s['class_group']);

    actingAs($approver);
    app(ValidateMarks::class)->handle($s['allocation'], $s['period'], $s['class_group']);

    expect(fn () => app(ValidateMarks::class)->handle($s['allocation'], $s['period'], $s['class_group']))
        ->toThrow(DomainException::class, "state 'validated' cannot be validated");
});

it('refuses to submit a batch twice', function () {
    ['scenario' => $s, 'entrant' => $entrant] = approvalScenario();

    actingAs($entrant);
    app(SubmitMarksForValidation::class)->handle($s['allocation'], $s['period'], $s['class_group']);

    expect(fn () => app(SubmitMarksForValidation::class)->handle($s['allocation'], $s['period'], $s['class_group']))
        ->toThrow(DomainException::class, 'already submitted');
});

it('returns a submitted grid to draft with a reason, clearing the submission stamp', function () {
    ['scenario' => $s, 'entrant' => $entrant, 'approver' => $approver] = approvalScenario();

    actingAs($entrant);
    app(SubmitMarksForValidation::class)->handle($s['allocation'], $s['period'], $s['class_group']);

    actingAs($approver);
    $result = app(RejectMarks::class)->handle(
        $s['allocation'],
        $s['period'],
        $s['class_group'],
        'Component CA looks transposed for rows 4 to 9.',
    );

    expect($result['returned'])->toBe(3)
        ->and($result['approval']->status)->toBe(MarkApproval::STATUS_RETURNED)
        ->and($result['approval']->last_decision)->toBe(ApprovalDecision::Reject)
        ->and($result['approval']->returned_by)->toBe((int) $approver->getKey())
        ->and($result['approval']->return_reason)->toBe('Component CA looks transposed for rows 4 to 9.');

    $mark = Mark::query()->findOrFail($s['marks'][0]);

    expect($mark->workflow_state)->toBe(WorkflowState::Draft)
        ->and($mark->submitted_by)->toBeNull()
        ->and($mark->submitted_at)->toBeNull();

    // Returned means re-submittable, which is the point of returning it.
    actingAs($entrant);
    expect(app(SubmitMarksForValidation::class)
        ->handle($s['allocation'], $s['period'], $s['class_group'])['submitted'])->toBe(3);
});

it('refuses a return with no reason, at the Action and at the database', function () {
    ['scenario' => $s, 'entrant' => $entrant, 'approver' => $approver] = approvalScenario();

    actingAs($entrant);
    app(SubmitMarksForValidation::class)->handle($s['allocation'], $s['period'], $s['class_group']);

    actingAs($approver);

    expect(fn () => app(RejectMarks::class)->handle($s['allocation'], $s['period'], $s['class_group'], '   '))
        ->toThrow(DomainException::class, 'requires a reason');

    $approval = MarkApproval::query()->firstOrFail();

    expect(fn () => DB::table('mark_approvals')->where('id', $approval->getKey())->update([
        'status' => MarkApproval::STATUS_RETURNED,
        'return_reason' => null,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('refuses to return a batch that is not under review', function () {
    ['scenario' => $s, 'entrant' => $entrant, 'approver' => $approver] = approvalScenario();

    actingAs($entrant);
    app(SubmitMarksForValidation::class)->handle($s['allocation'], $s['period'], $s['class_group']);

    actingAs($approver);
    app(ValidateMarks::class)->handle($s['allocation'], $s['period'], $s['class_group']);

    expect(fn () => app(RejectMarks::class)->handle($s['allocation'], $s['period'], $s['class_group'], 'Too late'))
        ->toThrow(DomainException::class, "state 'validated' cannot be returned");
});

it('keeps entry and approval in different hands', function () {
    ['scenario' => $s, 'entrant' => $entrant, 'approver' => $approver] = approvalScenario();

    // The Vice-Principal holds marks.validate but not marks.enter.
    actingAs($approver);
    expect(fn () => app(SubmitMarksForValidation::class)->handle($s['allocation'], $s['period'], $s['class_group']))
        ->toThrow(AuthorizationException::class);

    // A Teacher holds marks.enter but not marks.validate.
    actingAs(approvalUserAs(Role::Teacher, 'Mr Tabi'));
    expect(fn () => app(ValidateMarks::class)->handle($s['allocation'], $s['period'], $s['class_group']))
        ->toThrow(AuthorizationException::class);

    expect(MarkApproval::query()->count())->toBe(0);
});

it('un-submits a mark that is edited while under review, and refuses to edit a validated one', function () {
    ['scenario' => $s, 'entrant' => $entrant, 'approver' => $approver] = approvalScenario();

    actingAs($entrant);
    app(SubmitMarksForValidation::class)->handle($s['allocation'], $s['period'], $s['class_group']);

    // 7.4: editing a submitted mark returns it to draft and clears submitted_*.
    $mark = Mark::query()->findOrFail($s['marks'][0]);
    expect(app(SaveMark::class)->handle($mark, MarkState::Scored, '11.500'))->toBeNull();

    $mark->refresh();
    expect($mark->workflow_state)->toBe(WorkflowState::Draft)
        ->and($mark->submitted_by)->toBeNull()
        ->and($mark->score)->toBe('11.500');

    // 7.4: editing a validated mark is refused; it must be returned first.
    actingAs($approver);
    app(ValidateMarks::class)->handle($s['allocation'], $s['period'], $s['class_group']);

    actingAs($entrant);
    $validated = Mark::query()->findOrFail($s['marks'][1]);

    expect(fn () => app(SaveMark::class)->handle($validated, MarkState::Scored, '19.000'))
        ->toThrow(DomainException::class, 'must be returned first');
});

it('states the legal workflow transitions and only those', function () {
    expect(WorkflowState::Draft->canTransitionTo(WorkflowState::Submitted))->toBeTrue()
        // Nothing is validated that was never submitted.
        ->and(WorkflowState::Draft->canTransitionTo(WorkflowState::Validated))->toBeFalse()
        ->and(WorkflowState::Submitted->canTransitionTo(WorkflowState::Validated))->toBeTrue()
        ->and(WorkflowState::Submitted->canTransitionTo(WorkflowState::Draft))->toBeTrue()
        ->and(WorkflowState::Validated->canTransitionTo(WorkflowState::Draft))->toBeTrue()
        ->and(WorkflowState::Validated->canTransitionTo(WorkflowState::Submitted))->toBeFalse()
        ->and(WorkflowState::Validated->isEditable())->toBeFalse()
        ->and(WorkflowState::Submitted->isEditable())->toBeTrue()
        ->and(ApprovalDecision::Validate->isLegalFrom(WorkflowState::Draft))->toBeFalse()
        ->and(ApprovalDecision::Reject->requiresReason())->toBeTrue()
        ->and(ApprovalDecision::Submit->requiredPermission())->toBe('marks.enter')
        ->and(ApprovalDecision::Validate->requiredPermission())->toBe('marks.validate');
});

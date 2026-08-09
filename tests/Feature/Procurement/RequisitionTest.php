<?php

declare(strict_types=1);

use App\Modules\Procurement\Actions\ApproveRequisition;
use App\Modules\Procurement\Actions\CancelRequisition;
use App\Modules\Procurement\Actions\RejectRequisition;
use App\Modules\Procurement\Actions\SaveProcurementSettings;
use App\Modules\Procurement\Actions\SubmitRequisition;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\RequisitionStatus;
use App\Modules\Procurement\Models\PurchaseRequisition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

// ── Drafting & numbering ────────────────────────────────────────────────

it('creates a draft with an allocated REQ number and a derived estimated total', function () {
    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, f2ProcCalendar());

    expect($requisition->requisition_no)->toStartWith('REQ/2031/')
        ->and($requisition->status)->toBe(RequisitionStatus::Draft)
        // 40 x 3250, rounded once at the line: 130 000 exactly.
        ->and($requisition->estimated_total)->toBe(130_000)
        ->and($requisition->lines()->count())->toBe(1);
});

// ── Segregation of duties (test obligation 14) ──────────────────────────

it('refuses the requester approving their own requisition, whatever they hold', function () {
    $calendar = f2ProcCalendar();
    $requester = f2ProcUser(ProcurementPermission::VIEW, ProcurementPermission::REQUISITION_APPROVE);
    $requisition = f2ProcRequisition($requester, $calendar);
    app(SubmitRequisition::class)->handle($requisition->id, f2ProcActor($requester));

    // Still signed in as the requester, approval permission and all.
    app(ApproveRequisition::class)->handle($requisition->id, f2ProcActor($requester));
})->throws(ValidationException::class, 'segregation of duties');

it('lets a different holder of the approval permission approve', function () {
    $calendar = f2ProcCalendar();
    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, $calendar);
    app(SubmitRequisition::class)->handle($requisition->id, f2ProcActor($requester));

    $approver = f2ProcUser(ProcurementPermission::REQUISITION_APPROVE);
    $result = app(ApproveRequisition::class)->handle($requisition->id, f2ProcActor($approver));

    expect($result->requisition->status)->toBe(RequisitionStatus::Approved)
        ->and($result->requisition->approved_by)->toBe($approver->id)
        ->and($result->warnings)->toBe([]);
});

it('demands a reason to reject', function () {
    $calendar = f2ProcCalendar();
    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, $calendar);
    app(SubmitRequisition::class)->handle($requisition->id, f2ProcActor($requester));

    $approver = f2ProcUser(ProcurementPermission::REQUISITION_APPROVE);

    expect(fn () => app(RejectRequisition::class)->handle($requisition->id, '   ', f2ProcActor($approver)))
        ->toThrow(ValidationException::class);

    $rejected = app(RejectRequisition::class)->handle($requisition->id, 'No budget this term', f2ProcActor($approver));

    expect($rejected->status)->toBe(RequisitionStatus::Rejected)
        ->and($rejected->rejected_reason)->toBe('No budget this term');
});

// ── Budget enforcement (§4.1) ───────────────────────────────────────────

it('blocks approval under budget_enforcement=block when the budget line cannot be checked', function () {
    // The budget model is a later Accounting phase: a school that switched
    // the control ON gets a loud configuration error, never a silent pass
    // (empty-and-blocking).
    $calendar = f2ProcCalendar();
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);
    app(SaveProcurementSettings::class)->handle(['budget_enforcement' => 'block'], f2ProcActor($manager));

    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, $calendar, ['budget_line_id' => 42]);
    app(SubmitRequisition::class)->handle($requisition->id, f2ProcActor($requester));

    $approver = f2ProcUser(ProcurementPermission::REQUISITION_APPROVE);

    app(ApproveRequisition::class)->handle($requisition->id, f2ProcActor($approver));
})->throws(ValidationException::class, 'not configured');

it('approves WITH a warning under budget_enforcement=warn', function () {
    $calendar = f2ProcCalendar();
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);
    app(SaveProcurementSettings::class)->handle(['budget_enforcement' => 'warn'], f2ProcActor($manager));

    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, $calendar, ['budget_line_id' => 42]);
    app(SubmitRequisition::class)->handle($requisition->id, f2ProcActor($requester));

    $approver = f2ProcUser(ProcurementPermission::REQUISITION_APPROVE);
    $result = app(ApproveRequisition::class)->handle($requisition->id, f2ProcActor($approver));

    expect($result->requisition->status)->toBe(RequisitionStatus::Approved)
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0])->toContain('not configured');
});

it('ignores the budget check entirely when no budget line is named', function () {
    $calendar = f2ProcCalendar();
    $manager = f2ProcUser(ProcurementPermission::SUPPLIER_MANAGE);
    app(SaveProcurementSettings::class)->handle(['budget_enforcement' => 'block'], f2ProcActor($manager));

    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, $calendar);
    app(SubmitRequisition::class)->handle($requisition->id, f2ProcActor($requester));

    $approver = f2ProcUser(ProcurementPermission::REQUISITION_APPROVE);
    $result = app(ApproveRequisition::class)->handle($requisition->id, f2ProcActor($approver));

    expect($result->requisition->status)->toBe(RequisitionStatus::Approved);
});

// ── §9 draft-only deletion, at BOTH layers ──────────────────────────────

it('deletes a draft (lines cascade), by Eloquent and by raw SQL', function () {
    $calendar = f2ProcCalendar();
    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, $calendar);
    $requisitionId = $requisition->id;

    $requisition->delete();

    expect(DB::table('purchase_requisitions')->where('id', $requisitionId)->exists())->toBeFalse()
        ->and(DB::table('purchase_requisition_lines')->where('requisition_id', $requisitionId)->exists())->toBeFalse();
});

it('refuses deleting a submitted requisition at the model observer', function () {
    $calendar = f2ProcCalendar();
    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, $calendar);
    app(SubmitRequisition::class)->handle($requisition->id, f2ProcActor($requester));

    $requisition->refresh()->delete();
})->throws(RuntimeException::class);

it('refuses deleting a submitted requisition at the DATABASE trigger too', function () {
    // The §9 BEFORE DELETE trigger is the guard that raw write paths cannot
    // dodge - this goes straight through the query builder, past Eloquent.
    $calendar = f2ProcCalendar();
    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, $calendar);
    app(SubmitRequisition::class)->handle($requisition->id, f2ProcActor($requester));

    expect(fn () => DB::table('purchase_requisitions')->where('id', $requisition->id)->delete())
        ->toThrow(Illuminate\Database\QueryException::class, 'only be deleted while draft');
});

it('cancels an approved requisition instead of deleting it', function () {
    $calendar = f2ProcCalendar();
    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, $calendar);
    app(SubmitRequisition::class)->handle($requisition->id, f2ProcActor($requester));

    $approver = f2ProcUser(ProcurementPermission::REQUISITION_APPROVE);
    app(ApproveRequisition::class)->handle($requisition->id, f2ProcActor($approver));

    actingAs($requester);
    $cancelled = app(CancelRequisition::class)->handle($requisition->id, f2ProcActor($requester));

    expect($cancelled->status)->toBe(RequisitionStatus::Cancelled)
        ->and(PurchaseRequisition::query()->whereKey($requisition->id)->exists())->toBeTrue();
});

it('only edits and submits drafts', function () {
    $calendar = f2ProcCalendar();
    $requester = f2ProcUser(ProcurementPermission::VIEW);
    $requisition = f2ProcRequisition($requester, $calendar);
    app(SubmitRequisition::class)->handle($requisition->id, f2ProcActor($requester));

    // Second submit refuses...
    expect(fn () => app(SubmitRequisition::class)->handle($requisition->id, f2ProcActor($requester)))
        ->toThrow(ValidationException::class);

    // ...and so does re-editing through SaveRequisition.
    expect(fn () => app(\App\Modules\Procurement\Actions\SaveRequisition::class)->handle(
        [],
        [['description' => 'Chalk', 'quantity' => '1', 'estimated_unit_price' => 500, 'expense_account_id' => f2ProcExpenseAccountId()]],
        f2ProcActor($requester),
        $requisition->id,
    ))->toThrow(ValidationException::class);
});

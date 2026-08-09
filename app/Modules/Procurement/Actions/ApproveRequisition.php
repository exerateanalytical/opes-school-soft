<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\BudgetEnforcement;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\RequisitionApprovalResult;
use App\Modules\Procurement\Domain\RequisitionStatus;
use App\Modules\Procurement\Models\ProcurementSettings;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.1 - approve a submitted requisition.
 *
 * Segregation of duties (§4.2, test obligation 14): the REQUESTER cannot
 * approve their own requisition, whatever permissions they hold.
 *
 * Budget check: when a budget line is named and enforcement is warn/block,
 * consumption is measured AT READ TIME (posted invoices + open PO value -
 * commitment accounting is explicitly out of scope for v2). The Accounting
 * budget tables are a later phase in this checkout, so an absent budget
 * model counts as UNCONFIGURED: `block` refuses with a configuration error
 * (a control the school switched on must fail loudly, never silently pass
 * - the 00-core §16 empty-and-blocking discipline), `warn` returns the
 * warning. The consumption queries below activate as their source tables
 * land (open POs now, posted supplier invoices with the F3 package).
 */
final class ApproveRequisition
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $requisitionId, Actor $actor): RequisitionApprovalResult
    {
        Gate::authorize(ProcurementPermission::REQUISITION_APPROVE);

        return DB::transaction(function () use ($requisitionId, $actor): RequisitionApprovalResult {
            /** @var PurchaseRequisition $requisition */
            $requisition = PurchaseRequisition::query()->whereKey($requisitionId)->lockForUpdate()->firstOrFail();

            if ($requisition->status !== RequisitionStatus::Submitted) {
                throw ValidationException::withMessages([
                    'status' => sprintf('Only a submitted requisition can be approved; %s is %s.', $requisition->requisition_no, $requisition->status->value),
                ]);
            }

            if ($actor->id !== null && $requisition->requested_by === $actor->id) {
                throw ValidationException::withMessages([
                    'approved_by' => 'The requester cannot approve their own requisition (03-tax-procurement 4.2, segregation of duties).',
                ]);
            }

            $warnings = $this->checkBudget($requisition);

            $requisition->status = RequisitionStatus::Approved;
            $requisition->approved_by = $actor->id;
            $requisition->approved_at = now();
            $requisition->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: PurchaseRequisition::class,
                auditableId: (int) $requisition->getKey(),
                after: [
                    'status' => RequisitionStatus::Approved->value,
                    'budget_warnings' => $warnings,
                ],
                actor: $actor,
            );

            return new RequisitionApprovalResult($requisition, $warnings);
        });
    }

    /**
     * @return list<string>
     */
    private function checkBudget(PurchaseRequisition $requisition): array
    {
        $settings = ProcurementSettings::current();
        $enforcement = $settings->budget_enforcement;

        if ($enforcement === BudgetEnforcement::None || $requisition->budget_line_id === null) {
            return [];
        }

        $annual = Schema::hasTable('budget_lines')
            ? DB::table('budget_lines')->where('id', $requisition->budget_line_id)->value('annual_amount')
            : null;

        if ($annual === null) {
            $message = sprintf(
                'Budget enforcement is [%s] but budget line %d is not configured - the budget model has no approved amount to check against.',
                $enforcement->value,
                $requisition->budget_line_id,
            );

            if ($enforcement === BudgetEnforcement::Block) {
                throw ValidationException::withMessages(['budget' => $message]);
            }

            return [$message];
        }

        $consumed = $this->openPurchaseOrderValue((int) $requisition->budget_line_id)
            + $this->postedInvoiceValue((int) $requisition->budget_line_id);

        $projected = $consumed + $requisition->estimated_total;

        if ($projected <= (int) $annual) {
            return [];
        }

        $message = sprintf(
            'Approving would take budget line %d to %d FCFA against an annual budget of %d FCFA.',
            $requisition->budget_line_id,
            $projected,
            (int) $annual,
        );

        if ($enforcement === BudgetEnforcement::Block) {
            throw ValidationException::withMessages(['budget' => $message]);
        }

        return [$message];
    }

    /**
     * Open PO value on the budget line: PO lines whose requisition line
     * belongs to a requisition on this budget line, on POs still in flight.
     */
    private function openPurchaseOrderValue(int $budgetLineId): int
    {
        return (int) DB::table('purchase_order_lines as pol')
            ->join('purchase_orders as po', 'po.id', '=', 'pol.purchase_order_id')
            ->join('purchase_requisition_lines as prl', 'prl.id', '=', 'pol.requisition_line_id')
            ->join('purchase_requisitions as pr', 'pr.id', '=', 'prl.requisition_id')
            ->where('pr.budget_line_id', $budgetLineId)
            ->whereIn('po.status', ['approved', 'sent', 'partially_received', 'received', 'partially_invoiced'])
            ->sum('pol.amount_ht');
    }

    /** Posted supplier invoices arrive with the F3 package; 0 until then. */
    private function postedInvoiceValue(int $budgetLineId): int
    {
        if (! Schema::hasTable('supplier_invoices')) {
            return 0;
        }

        return (int) DB::table('supplier_invoice_lines as sil')
            ->join('supplier_invoices as si', 'si.id', '=', 'sil.supplier_invoice_id')
            ->join('purchase_order_lines as pol', 'pol.id', '=', 'sil.purchase_order_line_id')
            ->join('purchase_requisition_lines as prl', 'prl.id', '=', 'pol.requisition_line_id')
            ->join('purchase_requisitions as pr', 'pr.id', '=', 'prl.requisition_id')
            ->where('pr.budget_line_id', $budgetLineId)
            ->where('si.status', 'posted')
            ->sum('sil.amount_ht');
    }
}

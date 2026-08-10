<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\Requisitions;

use App\Modules\Procurement\Actions\ApproveRequisition;
use App\Modules\Procurement\Actions\CancelRequisition;
use App\Modules\Procurement\Actions\RejectRequisition;
use App\Modules\Procurement\Actions\SaveRequisition;
use App\Modules\Procurement\Actions\SubmitRequisition;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\RequisitionStatus;
use DomainException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - the requisition list doubling as
 * the approve queue. Submitting is the requester's own move; approve /
 * reject appear only to holders of `procurement.requisition_approve`, and
 * the SoD rule (requester never approves their own) is enforced in the
 * Action, not the template - hiding a button is not a control.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $status = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public string $rejectReason = '';

    /** @var list<string> */
    public array $warnings = [];

    // ── Save-requisition toggle form ────────────────────────────────────
    public bool $showForm = false;

    public string $formNeededBy = '';

    public function mount(): void
    {
        Gate::authorize(ProcurementPermission::VIEW);
    }

    public function toggleForm(): void
    {
        $this->showForm = ! $this->showForm;

        if ($this->showForm && $this->formNeededBy === '') {
            $this->formNeededBy = now()->addWeek()->toDateString();
        }
    }

    /**
     * The whole line grid rides along in one request (Alpine-local rows,
     * mirroring PurchaseOrders\Edit::save).
     *
     * @param  list<array{description?: string, quantity?: string, estimated_unit_price?: int|string, expense_account_id?: int|string|null}>  $rows
     */
    public function saveRequisition(array $rows, SaveRequisition $save): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $lines = [];

        foreach ($rows as $row) {
            $description = trim((string) ($row['description'] ?? ''));

            if ($description === '') {
                continue;
            }

            $lines[] = [
                'description' => $description,
                'quantity' => (string) ($row['quantity'] ?? '1'),
                'estimated_unit_price' => (int) ($row['estimated_unit_price'] ?? 0),
                'expense_account_id' => (int) ($row['expense_account_id'] ?? 0),
            ];
        }

        try {
            $save->handle(
                [
                    'requested_on' => now()->toDateString(),
                    'needed_by' => $this->formNeededBy === '' ? null : $this->formNeededBy,
                ],
                $lines,
                $user->toAuditActor(),
            );
        } catch (ValidationException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        } catch (DomainException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        $this->showForm = false;
        $this->formNeededBy = '';
        $this->page = 1;
        session()->flash('status', 'Requisition saved.');
    }

    public function cancel(int $requisitionId, CancelRequisition $cancel): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        try {
            $cancel->handle($requisitionId, $user->toAuditActor());
        } catch (DomainException $e) {
            $this->addError('cancel', $e->getMessage());

            return;
        }

        session()->flash('status', 'Requisition cancelled.');
    }

    public function resetFilters(): void
    {
        $this->reset(['status']);
        $this->page = 1;
    }

    public function updatedStatus(): void
    {
        $this->page = 1;
    }

    public function submit(int $requisitionId): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        app(SubmitRequisition::class)->handle($requisitionId, $user->toAuditActor());
    }

    public function approve(int $requisitionId): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $result = app(ApproveRequisition::class)->handle($requisitionId, $user->toAuditActor());
        $this->warnings = $result->warnings;
    }

    public function reject(int $requisitionId): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        app(RejectRequisition::class)->handle($requisitionId, $this->rejectReason, $user->toAuditActor());
        $this->rejectReason = '';
    }

    private function baseQuery(): QueryBuilder
    {
        $query = DB::table('purchase_requisitions as r')
            ->join('users as u', 'u.id', '=', 'r.requested_by');

        if (RequisitionStatus::tryFrom($this->status) !== null) {
            $query->where('r.status', $this->status);
        }

        return $query;
    }

    public function render(): mixed
    {
        $paginator = $this->baseQuery()
            ->select([
                'r.id', 'r.requisition_no', 'r.requested_on', 'r.needed_by', 'r.status',
                'r.estimated_total', 'r.rejected_reason', 'u.name as requested_by_name',
            ])
            ->orderByDesc('r.requested_on')
            ->orderByDesc('r.id')
            ->paginate($this->perPage, ['*'], 'page', $this->page);

        $kpis = [
            'pending' => DB::table('purchase_requisitions')->where('status', 'submitted')->count(),
            'approved' => DB::table('purchase_requisitions')->where('status', 'approved')->count(),
        ];

        return view('livewire.procurement.requisitions.index', [
            'requisitions' => $paginator,
            'kpis' => $kpis,
            'statusOptions' => array_map(static fn (RequisitionStatus $s): string => $s->value, RequisitionStatus::cases()),
            'canApprove' => Gate::allows(ProcurementPermission::REQUISITION_APPROVE),
            'expenseAccounts' => DB::table('chart_of_accounts')
                ->where('is_postable', true)
                ->where(function (QueryBuilder $q): void {
                    $q->where('code', 'like', '6%')->orWhere('code', 'like', '2%');
                })
                ->orderBy('code')
                ->limit(400)
                ->get(['id', 'code', 'name']),
        ]);
    }
}

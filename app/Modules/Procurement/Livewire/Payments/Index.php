<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\Payments;

use App\Modules\Procurement\Actions\ApproveSupplierPayment;
use App\Modules\Procurement\Actions\PaySupplierPayment;
use App\Modules\Procurement\Actions\VoidSupplierPayment;
use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use DomainException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - the supplier-payment worklist:
 * drafts awaiting approval, approved awaiting execution, the paid trail.
 * The §11.14 segregation pairs live in the Actions - this screen only
 * surfaces the buttons; a recorder pressing Approve on their own draft
 * gets the Action's refusal, not a silent success.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public ?int $voidingId = null;

    public string $voidReason = '';

    public function mount(): void
    {
        Gate::authorize(SupplierPaymentPermission::RECORD);
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedStatus(): void
    {
        $this->page = 1;
    }

    public function approve(int $paymentId): void
    {
        $this->run(function () use ($paymentId): void {
            /** @var \App\Modules\Identity\Models\User $user */
            $user = auth()->user();
            app(ApproveSupplierPayment::class)->handle($paymentId, $user->toAuditActor());
            session()->flash('status', __('opes.supplier_payment_screen.approved'));
        });
    }

    public function pay(int $paymentId): void
    {
        $this->run(function () use ($paymentId): void {
            /** @var \App\Modules\Identity\Models\User $user */
            $user = auth()->user();
            app(PaySupplierPayment::class)->handle($paymentId, $user->toAuditActor());
            session()->flash('status', __('opes.supplier_payment_screen.paid'));
        });
    }

    public function startVoid(int $paymentId): void
    {
        $this->voidingId = $paymentId;
        $this->voidReason = '';
    }

    public function confirmVoid(): void
    {
        $paymentId = $this->voidingId;

        if ($paymentId === null) {
            return;
        }

        $this->run(function () use ($paymentId): void {
            /** @var \App\Modules\Identity\Models\User $user */
            $user = auth()->user();
            app(VoidSupplierPayment::class)->handle($paymentId, $this->voidReason, $user->toAuditActor());
            $this->voidingId = null;
            $this->voidReason = '';
            session()->flash('status', __('opes.supplier_payment_screen.voided'));
        });
    }

    private function run(callable $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->addError('payment', implode(' ', array_map(
                static fn (array $messages): string => implode(' ', $messages),
                $exception->errors(),
            )));
        } catch (DomainException $exception) {
            $this->addError('payment', $exception->getMessage());
        }
    }

    private function baseQuery(): QueryBuilder
    {
        $query = DB::table('supplier_payments as p')
            ->join('suppliers as s', 's.id', '=', 'p.supplier_id');

        if ($this->search !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $this->search).'%';
            $query->where(function (QueryBuilder $inner) use ($term): void {
                $inner->where('p.payment_no', 'like', $term)
                    ->orWhere('p.reference', 'like', $term)
                    ->orWhere('s.name', 'like', $term);
            });
        }

        if ($this->status !== '') {
            $query->where('p.status', $this->status);
        }

        return $query;
    }

    public function render(): mixed
    {
        $paginator = $this->baseQuery()
            ->select([
                'p.id', 'p.payment_no', 'p.payment_date', 'p.payment_method', 'p.reference',
                'p.gross_amount', 'p.withholding_amount', 'p.net_amount', 'p.status',
                's.name as supplier_name',
            ])
            ->orderByDesc('p.payment_date')
            ->orderByDesc('p.id')
            ->paginate($this->perPage, ['*'], 'page', $this->page);

        $kpis = [
            'draft' => DB::table('supplier_payments')->where('status', 'draft')->count(),
            'approved' => DB::table('supplier_payments')->where('status', 'approved')->count(),
            'paid' => DB::table('supplier_payments')->where('status', 'paid')->count(),
            'withheld' => (int) DB::table('supplier_payments')->where('status', 'paid')->sum('withholding_amount'),
        ];

        return view('livewire.procurement.payments.index', [
            'payments' => $paginator,
            'kpis' => $kpis,
            'canApprove' => Gate::allows(SupplierPaymentPermission::APPROVE),
            'canVoid' => Gate::allows(SupplierPaymentPermission::VOID),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\Suppliers;

use App\Modules\Procurement\Actions\ArchiveSupplier;
use App\Modules\Procurement\Actions\SaveSupplier;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\RegimeFiscal;
use App\Modules\Procurement\Domain\SupplierType;
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
 * docs/specs/03-tax-procurement.md §10 - the supplier list: search by
 * name / NIU / code, filter active vs archived, duplicate-risk visible via
 * the withholding/NIU columns. Gated `procurement.view`; managing needs
 * `procurement.supplier_manage` and lives on the profile.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $state = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Save-supplier toggle form ───────────────────────────────────────
    public bool $showForm = false;

    public ?int $editingSupplierId = null;

    public string $formName = '';

    public string $formSupplierType = 'company';

    public string $formNiu = '';

    public string $formRegimeFiscal = '';

    public string $formPayableAccountId = '';

    public string $formCategoryId = '';

    public string $formPaymentTermsDays = '30';

    public string $formCurrency = 'XAF';

    public string $formPhone = '';

    public string $formEmail = '';

    // ── Archive-supplier reason prompt ──────────────────────────────────
    public ?int $archivingSupplierId = null;

    public string $archiveReason = '';

    public function mount(): void
    {
        Gate::authorize(ProcurementPermission::VIEW);
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'state']);
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedState(): void
    {
        $this->page = 1;
    }

    public function toggleForm(): void
    {
        Gate::authorize(ProcurementPermission::SUPPLIER_MANAGE);

        $this->showForm = ! $this->showForm;

        if (! $this->showForm) {
            $this->resetForm();
        }
    }

    public function editSupplier(int $supplierId): void
    {
        Gate::authorize(ProcurementPermission::SUPPLIER_MANAGE);

        /** @var object{id:int, name:string, supplier_type:string, niu:?string, regime_fiscal:?string, payable_account_id:int, category_id:?int, payment_terms_days:int, currency:string, phone:?string, email:?string}|null $supplier */
        $supplier = DB::table('suppliers')->where('id', $supplierId)->first([
            'id', 'name', 'supplier_type', 'niu', 'regime_fiscal', 'payable_account_id',
            'category_id', 'payment_terms_days', 'currency', 'phone', 'email',
        ]);

        if ($supplier === null) {
            return;
        }

        $this->editingSupplierId = $supplier->id;
        $this->formName = $supplier->name;
        $this->formSupplierType = $supplier->supplier_type;
        $this->formNiu = $supplier->niu ?? '';
        $this->formRegimeFiscal = $supplier->regime_fiscal ?? '';
        $this->formPayableAccountId = (string) $supplier->payable_account_id;
        $this->formCategoryId = $supplier->category_id !== null ? (string) $supplier->category_id : '';
        $this->formPaymentTermsDays = (string) $supplier->payment_terms_days;
        $this->formCurrency = $supplier->currency;
        $this->formPhone = $supplier->phone ?? '';
        $this->formEmail = $supplier->email ?? '';
        $this->showForm = true;
    }

    public function saveSupplier(SaveSupplier $save): void
    {
        Gate::authorize(ProcurementPermission::SUPPLIER_MANAGE);

        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $payload = [
            'name' => trim($this->formName),
            'supplier_type' => $this->formSupplierType,
            'niu' => $this->formNiu === '' ? null : trim($this->formNiu),
            'regime_fiscal' => $this->formRegimeFiscal === '' ? null : $this->formRegimeFiscal,
            'payable_account_id' => $this->formPayableAccountId === '' ? null : (int) $this->formPayableAccountId,
            'category_id' => $this->formCategoryId === '' ? null : (int) $this->formCategoryId,
            'payment_terms_days' => (int) $this->formPaymentTermsDays,
            'currency' => $this->formCurrency,
            'phone' => $this->formPhone === '' ? null : $this->formPhone,
            'email' => $this->formEmail === '' ? null : $this->formEmail,
        ];

        try {
            $save->handle($payload, $user->toAuditActor(), $this->editingSupplierId);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('form'.str_replace('_', '', ucwords($field, '_')), (string) ($messages[0] ?? $e->getMessage()));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('formName', $e->getMessage());

            return;
        }

        $this->resetForm();
        $this->showForm = false;
        $this->page = 1;
        session()->flash('status', 'Supplier saved.');
    }

    public function archiveSupplier(ArchiveSupplier $archive): void
    {
        Gate::authorize(ProcurementPermission::SUPPLIER_MANAGE);

        $user = Auth::user();

        if ($user === null || $this->archivingSupplierId === null) {
            return;
        }

        try {
            $archive->handle($this->archivingSupplierId, $user->toAuditActor(), $this->archiveReason === '' ? null : $this->archiveReason);
        } catch (DomainException $e) {
            $this->addError('archiveReason', $e->getMessage());

            return;
        }

        $this->archivingSupplierId = null;
        $this->archiveReason = '';
        session()->flash('status', 'Supplier archived.');
    }

    public function promptArchive(int $supplierId): void
    {
        Gate::authorize(ProcurementPermission::SUPPLIER_MANAGE);

        $this->archivingSupplierId = $supplierId;
        $this->archiveReason = '';
    }

    public function cancelArchive(): void
    {
        $this->archivingSupplierId = null;
        $this->archiveReason = '';
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingSupplierId', 'formName', 'formSupplierType', 'formNiu', 'formRegimeFiscal',
            'formPayableAccountId', 'formCategoryId', 'formPhone', 'formEmail',
        ]);
        $this->formPaymentTermsDays = '30';
        $this->formCurrency = 'XAF';
    }

    private function baseQuery(): QueryBuilder
    {
        $query = DB::table('suppliers as s')
            ->leftJoin('supplier_categories as c', 'c.id', '=', 's.category_id');

        if ($this->search !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $this->search).'%';
            $query->where(function (QueryBuilder $inner) use ($term): void {
                $inner->where('s.name', 'like', $term)
                    ->orWhere('s.code', 'like', $term)
                    ->orWhere('s.niu', 'like', $term);
            });
        }

        if ($this->state === 'active') {
            $query->where('s.is_active', true)->where('s.is_archived', false);
        }

        if ($this->state === 'archived') {
            $query->where('s.is_archived', true);
        }

        return $query;
    }

    public function render(): mixed
    {
        $paginator = $this->baseQuery()
            ->select([
                's.id', 's.code', 's.name', 's.niu', 's.niu_status', 's.supplier_type',
                's.phone', 's.is_active', 's.is_archived', 'c.name as category_name',
            ])
            ->orderBy('s.name')
            ->paginate($this->perPage, ['*'], 'page', $this->page);

        $kpis = [
            'total' => DB::table('suppliers')->count(),
            'active' => DB::table('suppliers')->where('is_active', true)->where('is_archived', false)->count(),
            'archived' => DB::table('suppliers')->where('is_archived', true)->count(),
        ];

        return view('livewire.procurement.suppliers.index', [
            'suppliers' => $paginator,
            'kpis' => $kpis,
            'canManage' => Gate::allows(ProcurementPermission::SUPPLIER_MANAGE),
            'categories' => DB::table('supplier_categories')->orderBy('name')->get(['id', 'name']),
            'payableAccounts' => DB::table('chart_of_accounts')
                ->where('is_collective', true)
                ->where(function (QueryBuilder $q): void {
                    $q->where('code', 'like', '401%')->orWhere('code', 'like', '48%');
                })
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'supplierTypes' => array_map(static fn (SupplierType $t): string => $t->value, SupplierType::cases()),
            'regimeFiscalOptions' => array_map(static fn (RegimeFiscal $r): string => $r->value, RegimeFiscal::cases()),
        ]);
    }
}

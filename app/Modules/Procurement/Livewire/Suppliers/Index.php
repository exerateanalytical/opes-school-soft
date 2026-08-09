<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\Suppliers;

use App\Modules\Procurement\Domain\ProcurementPermission;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        ]);
    }
}

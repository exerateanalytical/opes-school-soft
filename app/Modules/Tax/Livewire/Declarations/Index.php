<?php

declare(strict_types=1);

namespace App\Modules\Tax\Livewire\Declarations;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Models\TaxDeclaration;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * docs/specs/03-tax-procurement.md §10 - the declarations register at
 * /tax/declarations. Read-only: declarations are generated, filed and
 * amended through the audited Actions; this screen makes the register
 * legible, filterable by status and type.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    public function mount(): void
    {
        Gate::authorize(Permission::TaxDeclare->value);
    }

    public function render(): mixed
    {
        $declarations = TaxDeclaration::query()
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->type !== '', fn ($query) => $query->where('declaration_type', $this->type))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.tax.declarations.index', [
            'declarations' => $declarations,
        ]);
    }
}

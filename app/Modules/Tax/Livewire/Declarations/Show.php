<?php

declare(strict_types=1);

namespace App\Modules\Tax\Livewire\Declarations;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Domain\DeclarationTypeCode;
use App\Modules\Tax\Models\TaxDeclaration;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - one declaration: header, lines,
 * the §7.3 per-supplier annex, the inputs_hash and the amendment chain.
 * Carries the §7.1 "not yet mapped to the official form" banner while the
 * type's DGI box mapping is unverified (dsf_annual excepted - its line
 * codes come from ChartOfAccount.dsf_line_code, the verified mechanism).
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public int $declarationId;

    public function mount(int $declaration): void
    {
        Gate::authorize(Permission::TaxDeclare->value);

        $this->declarationId = $declaration;
    }

    public function render(): mixed
    {
        /** @var TaxDeclaration $declaration */
        $declaration = TaxDeclaration::query()
            ->with(['lines', 'type'])
            ->findOrFail($this->declarationId);

        $formBoxes = $declaration->type()->first()?->form_boxes;

        $unmappedForm = $declaration->declaration_type !== DeclarationTypeCode::DsfAnnual->value
            && ($formBoxes === null || $formBoxes === []);

        return view('livewire.tax.declarations.show', [
            'declaration' => $declaration,
            'unmappedForm' => $unmappedForm,
        ]);
    }
}

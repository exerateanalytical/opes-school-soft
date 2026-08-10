<?php

declare(strict_types=1);

namespace App\Modules\Tax\Livewire\Declarations;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Actions\GenerateDsf;
use App\Modules\Tax\Actions\GenerateTvaDeclaration;
use App\Modules\Tax\Actions\GenerateWithholdingDeclaration;
use App\Modules\Tax\Domain\DeclarationTypeCode;
use App\Modules\Tax\Models\TaxDeclaration;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * docs/specs/03-tax-procurement.md §10 - the declarations register at
 * /tax/declarations. Declarations are filed and amended elsewhere; this
 * screen also carries the toggle-form that GENERATES a period's figures
 * through GenerateTvaDeclaration / GenerateWithholdingDeclaration /
 * GenerateDsf - each gated `tax.declare` internally, mirrored here so the
 * form does not render for a user without the right.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    public bool $showGenerateForm = false;

    public string $genType = 'tva_monthly';

    public string $genYear = '';

    public string $genMonth = '';

    public string $genFiscalYearId = '';

    public function mount(): void
    {
        Gate::authorize(Permission::TaxDeclare->value);
    }

    public function toggleGenerateForm(): void
    {
        Gate::authorize(Permission::TaxDeclare->value);

        $this->showGenerateForm = ! $this->showGenerateForm;
    }

    public function generate(
        GenerateTvaDeclaration $tva,
        GenerateWithholdingDeclaration $withholding,
        GenerateDsf $dsf,
    ): void {
        Gate::authorize(Permission::TaxDeclare->value);

        try {
            match ($this->genType) {
                DeclarationTypeCode::TvaMonthly->value => $tva->handle(
                    (int) $this->genYear,
                    (int) $this->genMonth,
                    $this->actor(),
                ),
                DeclarationTypeCode::WithholdingMonthly->value => $withholding->handle(
                    (int) $this->genYear,
                    (int) $this->genMonth,
                    $this->actor(),
                ),
                DeclarationTypeCode::DsfAnnual->value => $dsf->handle(
                    (int) $this->genFiscalYearId,
                    $this->actor(),
                ),
                default => throw new DomainException('Unknown declaration type.'),
            };
        } catch (DomainException $exception) {
            $this->addError('generate', $exception->getMessage());

            return;
        }

        $this->reset(['showGenerateForm', 'genYear', 'genMonth', 'genFiscalYearId']);
        session()->flash('status', 'Declaration generated.');
    }

    private function actor(): Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = Auth::user();

        return $user->toAuditActor();
    }

    /**
     * @return list<object{id: int|string, code: string}>
     */
    public function fiscalYears(): array
    {
        return DB::table('fiscal_years')
            ->whereIn('status', ['closing', 'closed'])
            ->orderByDesc('ends_on')
            ->get(['id', 'code'])
            ->all();
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
            'availableFiscalYears' => $this->fiscalYears(),
        ]);
    }
}

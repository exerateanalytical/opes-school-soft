<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Review;

use App\Modules\Accounting\Actions\Review\AuxiliaryControlChecks;
use App\Modules\Accounting\Actions\Review\ConfigurationGates;
use App\Modules\Identity\Domain\Permission;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Accounting Review landing screen,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.
 *
 * Answers one question: do I trust these books right now?
 *
 * Read-only assurance. Every number comes from an Action; this component
 * filters and presents, exactly as Reports\TrialBalance does, and decides
 * nothing.
 *
 * Both the axis and the as_of date are rendered, per §5 - the fiscal and
 * academic answers differ by a full term, and an accountant reading the
 * wrong one misreports to the proprietor.
 */
#[Layout('layouts.app')]
final class ControlCentre extends Component
{
    #[Url]
    public string $axis = 'fiscal_year';

    #[Url]
    public string $asOf = '';

    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);

        if ($this->asOf === '') {
            $this->asOf = BusinessDate::today();
        }
    }

    /** The two axes 02-accounting §7 recognises. Anything else is rejected. */
    public function updatedAxis(string $value): void
    {
        if (! in_array($value, ['fiscal_year', 'academic_year'], true)) {
            $this->axis = 'fiscal_year';
        }
    }

    public function render(): mixed
    {
        $checks = app(AuxiliaryControlChecks::class)->handle($this->asOf, $this->axis);
        $gates = app(ConfigurationGates::class)->handle();

        return view('livewire.accounting.review.control-centre', [
            'checks' => $checks,
            'gates' => $gates,
            'brokenCount' => $checks->filter(fn ($c): bool => $c->difference !== 0)->count(),
            'openGateCount' => count(array_filter($gates, fn (array $g): bool => ! $g['configured'])),
        ]);
    }
}

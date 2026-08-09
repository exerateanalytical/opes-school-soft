<?php

declare(strict_types=1);

namespace App\Modules\Tax\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Actions\ComplianceCalendar;
use App\Modules\Tax\Models\TaxCredit;
use App\Modules\Tax\Models\TaxDeclaration;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §7.4 / §10 - the tax dashboard at
 * /tax: the compliance calendar (upcoming obligations with their
 * T−15/7/1 alerts), recent declarations and the open TVA credit. Every
 * figure comes from the query Action or the models directly - the
 * dashboard invents no second definition of anything, and no wording may
 * imply the system files anything (§7.4: the bursar files on impots.cm).
 */
#[Layout('layouts.app')]
final class TaxDashboard extends Component
{
    public function mount(): void
    {
        Gate::authorize(Permission::TaxView->value);
    }

    public function render(): mixed
    {
        $calendar = app(ComplianceCalendar::class)->handle();

        $recentDeclarations = TaxDeclaration::query()
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->limit(12)
            ->get();

        $openCredit = (int) TaxCredit::query()->open()->sum('amount');

        return view('livewire.tax.tax-dashboard', [
            'calendarItems' => $calendar['items'],
            'calendarNotes' => $calendar['notes'],
            'recentDeclarations' => $recentDeclarations,
            'openCredit' => $openCredit,
        ]);
    }
}

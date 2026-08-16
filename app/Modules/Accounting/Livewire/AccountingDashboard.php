<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire;

use App\Modules\Accounting\Actions\Review\ControlAccountChecks;
use App\Modules\Accounting\Actions\Review\JournalExceptions;
use App\Modules\Accounting\Actions\Review\SuspenseBalances;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
final class AccountingDashboard extends Component
{
    public function mount(): void
    {
        Gate::authorize(Permission::LedgerView->value);
    }

    public function render(): mixed
    {
        $checks = app(ControlAccountChecks::class)->handle();
        $draftCount = app(JournalExceptions::class)->query('draft')->count();
        $suspense = app(SuspenseBalances::class)->handle();

        $closingYear = FiscalYear::query()
            ->where('status', 'closing')
            ->first();

        $draftEntries = app(JournalExceptions::class)
            ->query('draft')
            ->orderByDesc('date')
            ->limit(10)
            ->get();

        return view('livewire.accounting.accounting-dashboard', [
            'brokenCount' => $checks->filter(fn ($c): bool => $c->difference !== 0)->count(),
            'draftCount' => $draftCount,
            'suspense' => $suspense,
            'closingYear' => $closingYear,
            'draftEntries' => $draftEntries,
        ]);
    }
}

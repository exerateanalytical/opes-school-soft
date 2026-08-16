<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire;

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
        return view('livewire.accounting.accounting-dashboard');
    }
}

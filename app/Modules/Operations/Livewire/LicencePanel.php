<?php

declare(strict_types=1);

namespace App\Modules\Operations\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Operations\Actions\Licensing\ActivateOnline;
use App\Modules\Operations\Actions\Licensing\DeactivateLicence;
use App\Modules\Operations\Actions\Licensing\ImportLicenceFile;
use App\Modules\Operations\Actions\Licensing\OpportunisticRecheck;
use App\Modules\Operations\Licensing\LicenceStatus;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The Licence panel (docs/specs/08-operations.md §4). The screen contract:
 * the current state and its expiry, the two routes to a licensed state
 * (import a .opeslic file, activate online with a key), deactivation with
 * the honest seat message, and the "never blocked" commitment in writing.
 *
 * mount() is THE one place the opportunistic re-check fires (§4.3) - not on
 * boot, not on a schedule, not from any status check. The Action itself
 * re-verifies its own preconditions (licence cached, server configured,
 * next_check_after passed) and is silent about every failure.
 *
 * The licence KEY property is cleared after every attempt, successful or
 * not: it must never sit in the component's snapshot longer than the one
 * request that used it, and it is never logged or echoed back (§4.3).
 */
#[Layout('layouts.app')]
final class LicencePanel extends Component
{
    public string $importContents = '';

    public string $licenceKey = '';

    public string $successMessage = '';

    public string $errorMessage = '';

    public string $warningMessage = '';

    public function mount(OpportunisticRecheck $recheck): void
    {
        Gate::authorize(Permission::LicenceManage->value);

        $recheck->handle();
    }

    public function importFile(ImportLicenceFile $import): void
    {
        Gate::authorize(Permission::LicenceManage->value);
        $this->resetMessages();

        try {
            $licence = $import->handle($this->importContents, $this->actor());

            $this->importContents = '';
            $this->successMessage = (string) __('licence.import.done', [
                'date' => $licence->expires_at?->toDateString() ?? '',
            ]);
        } catch (DomainException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function activate(ActivateOnline $activate): void
    {
        Gate::authorize(Permission::LicenceManage->value);
        $this->resetMessages();

        $key = $this->licenceKey;
        // Cleared BEFORE the attempt so no failure path leaves the key in
        // the rendered response or the component snapshot.
        $this->licenceKey = '';

        try {
            $licence = $activate->handle($key, $this->actor());

            $this->successMessage = (string) __('licence.activate.done', [
                'date' => $licence->expires_at?->toDateString() ?? '',
            ]);
        } catch (DomainException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function deactivate(DeactivateLicence $deactivate): void
    {
        Gate::authorize(Permission::LicenceManage->value);
        $this->resetMessages();

        try {
            $result = $deactivate->handle($this->actor());

            $this->successMessage = (string) __(match ($result['seat_released']) {
                true => 'licence.deactivate.seat_released',
                false => 'licence.deactivate.done',
                null => 'licence.deactivate.done',
            });

            if ($result['seat_released'] === false) {
                // §4.3: saying nothing is how a three-seat school quietly
                // runs out of seats.
                $this->warningMessage = (string) __('licence.deactivate.seat_not_released');
            }
        } catch (DomainException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function render(LicenceStatus $status): View
    {
        return view('livewire.operations.licence-panel', [
            'evaluation' => $status->evaluate(),
        ]);
    }

    private function actor(): Actor
    {
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        return new Actor((int) $user->getAuthIdentifier(), (string) $user->getAttribute('name'));
    }

    private function resetMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
        $this->warningMessage = '';
    }
}

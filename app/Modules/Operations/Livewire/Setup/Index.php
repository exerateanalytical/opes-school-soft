<?php

declare(strict_types=1);

namespace App\Modules\Operations\Livewire\Setup;

use App\Modules\Operations\Actions\EvaluateSetupReadiness;
use App\Modules\Operations\Domain\SetupCheckStatus;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The go-live readiness console (00-core §16).
 *
 * Deliberately read-only. Every row is evaluated against live data, so
 * nothing here can be ticked to make a red row go green - a school gets
 * green by configuring the thing, not by acknowledging it. That is the whole
 * point of §16: a wrong value that looks authoritative is more dangerous
 * than an empty field.
 */
final class Index extends Component
{
    public function render(): View
    {
        $checks = app(EvaluateSetupReadiness::class)->handle();

        $blocked = count(array_filter(
            $checks,
            static fn (array $c): bool => $c['status'] === SetupCheckStatus::Blocked
        ));

        $warnings = count(array_filter(
            $checks,
            static fn (array $c): bool => $c['status'] === SetupCheckStatus::Warning
        ));

        return view('livewire.operations.setup.index', [
            'checks' => $checks,
            'blocked' => $blocked,
            'warnings' => $warnings,
            'ready' => $blocked === 0,
        ]);
    }
}

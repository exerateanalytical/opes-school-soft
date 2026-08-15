<?php

declare(strict_types=1);

namespace App\Modules\Operations\Livewire\Setup;

use App\Modules\Operations\Actions\EvaluateSetupReadiness;
use App\Modules\Operations\Domain\SetupCheckStatus;
use Illuminate\Support\Facades\Gate;
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

        // A readiness console that names a blocker and gives no way to clear
        // it is a wizard with no next step. Every row owns the screen that
        // fixes it; that mapping belongs here, beside the check, not in the
        // operator's head. Gate-checked so no role is offered a link its
        // permissions refuse (the nav-and-route-agree contract); keys with no
        // screen (fiscal calendar rows are seeded by the rollover wizard) map
        // to null and simply render no link.
        $checks = array_map(function (array $check): array {
            [$href, $permission] = match ($check['key']) {
                'tax_settings', 'vat_prorata' => ['/settings/tax', 'ledger.configure'],
                'dsf_mapping', 'chart_postable' => ['/ledger/chart-of-accounts', 'ledger.view'],
                'two_accounting_users' => ['/users', 'user.view'],
                'assessment_framework' => ['/academics/settings', 'academics.manage'],
                default => [null, null],
            };

            $check['fix_href'] = ($href !== null && Gate::allows((string) $permission)) ? $href : null;

            return $check;
        }, $checks);

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

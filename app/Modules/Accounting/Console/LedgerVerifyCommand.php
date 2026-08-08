<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Console;

use App\Modules\Accounting\Actions\VerifyLedgerIntegrity;
use Illuminate\Console\Command;

/**
 * The cron face of VerifyLedgerIntegrity (02-accounting §4.3, backstop
 * column). Exit code 1 whenever any finding exists so a wrapper script can
 * alert on it; 0 means every invariant held tonight.
 */
final class LedgerVerifyCommand extends Command
{
    protected $signature = 'opes:ledger:verify {--fiscal-year= : Restrict the checks to one fiscal year id}';

    protected $description = 'Re-assert the ledger invariants (L2, L5, L7-L11) and report any violation.';

    public function handle(VerifyLedgerIntegrity $verify): int
    {
        $fiscalYearOption = $this->option('fiscal-year');
        $fiscalYearId = is_string($fiscalYearOption) && $fiscalYearOption !== ''
            ? (int) $fiscalYearOption
            : null;

        $report = $verify->handle($fiscalYearId);

        $total = 0;

        foreach ($report as $invariant => $findings) {
            $count = count($findings);
            $total += $count;

            if ($count === 0) {
                $this->line("{$invariant}: OK");

                continue;
            }

            $this->error("{$invariant}: {$count} finding(s)");

            foreach ($findings as $finding) {
                $parts = [];
                foreach ($finding as $field => $value) {
                    $parts[] = "{$field}={$value}";
                }
                $this->line('  - '.implode(' ', $parts));
            }
        }

        if ($total === 0) {
            $this->info('Ledger integrity verified. All invariants hold.');

            return self::SUCCESS;
        }

        $this->error("Ledger integrity FAILED: {$total} finding(s) across the invariants above.");
        $this->line('Do not post further entries until an accountant has reviewed this output.');

        return self::FAILURE;
    }
}

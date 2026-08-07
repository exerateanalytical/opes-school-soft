<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\VerifyAuditChain;
use Illuminate\Console\Command;

final class VerifyAuditChainCommand extends Command
{
    protected $signature = 'opes:audit:verify';

    protected $description = 'Verify the audit log hash chain is intact.';

    public function handle(VerifyAuditChain $verify): int
    {
        $result = $verify->handle();

        if ($result->isIntact()) {
            $this->info("Audit chain intact. {$result->checked} entries verified.");

            return self::SUCCESS;
        }

        $this->error("Audit chain BROKEN at entry {$result->firstBrokenId}: {$result->reason}");
        $this->line("Entries verified before the break: {$result->checked}");
        $this->line('This means the audit table was modified outside the application.');

        return self::FAILURE;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

enum BackupStatus: string
{
    case Running = 'running';
    case Healthy = 'healthy';
    case Corrupt = 'corrupt';
    case Failed = 'failed';

    /**
     * Only a verified-healthy backup may be restored from or pruned against.
     * "Exists on disk" is not the same as "restorable" - a dump can complete
     * cleanly and still fail to restore (08-operations 3.6).
     */
    public function isUsable(): bool
    {
        return $this === self::Healthy;
    }

    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Domain\BackupStatus;
use App\Modules\Operations\Models\Backup;
use Illuminate\Support\Facades\File;

/**
 * Re-hash a backup and compare against the checksum recorded when it was taken.
 *
 * Bit rot, a truncated copy to a USB stick, and a half-written file all look
 * identical to "the backup exists" until this runs.
 */
final class VerifyBackup
{
    public function handle(Backup $backup): Backup
    {
        if (! File::exists($backup->path)) {
            return $this->fail($backup, 'The backup file is missing from disk.');
        }

        $actual = hash_file('sha256', $backup->path);

        if ($actual !== $backup->sha256) {
            return $this->fail($backup, sprintf(
                'checksum mismatch: expected %s, found %s',
                (string) $backup->sha256,
                (string) $actual,
            ));
        }

        $backup->update([
            'status' => BackupStatus::Healthy->value,
            'verified_at' => now(),
            'failure_detail' => null,
        ]);

        return $backup->refresh();
    }

    private function fail(Backup $backup, string $why): Backup
    {
        $backup->update([
            'status' => BackupStatus::Corrupt->value,
            'verified_at' => now(),
            'failure_detail' => $why,
        ]);

        return $backup->refresh();
    }
}

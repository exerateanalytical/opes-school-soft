<?php

declare(strict_types=1);

namespace App\Modules\Operations\Console;

use App\Modules\Operations\Actions\CreateBackup;
use App\Modules\Operations\Domain\BackupKind;
use Illuminate\Console\Command;

final class BackupRunCommand extends Command
{
    protected $signature = 'opes:backup:run';

    protected $description = 'Take a full backup of the school database.';

    public function handle(CreateBackup $create): int
    {
        $backup = $create->handle(BackupKind::Full);

        if (! $backup->status()->isUsable()) {
            $this->error('The backup FAILED: '.(string) $backup->failure_detail);
            $this->line('No new copy was made. The last good backup is still the newest one.');

            return self::FAILURE;
        }

        $this->info('Backup complete.');
        $this->line('  File:     '.$backup->path);
        $this->line('  Size:     '.$this->humanBytes((int) $backup->size_bytes));
        $this->line('  Checksum: '.(string) $backup->sha256);
        $this->line('');
        $this->comment('A backup is not proven until it restores. Run: php artisan opes:backup:drill');

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 ** 2) {
            return number_format($bytes / 1024, 1).' KB';
        }

        if ($bytes < 1024 ** 3) {
            return number_format($bytes / (1024 ** 2), 1).' MB';
        }

        return number_format($bytes / (1024 ** 3), 2).' GB';
    }
}

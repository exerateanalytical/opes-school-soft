<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Domain\BackupKind;
use App\Modules\Operations\Domain\BackupStatus;
use App\Modules\Operations\Models\Backup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Take a full logical backup of the school database.
 *
 * --single-transaction so the dump is consistent without locking the school
 * out of the cashier screen while it runs.
 */
final class CreateBackup
{
    public function handle(BackupKind $kind = BackupKind::Full): Backup
    {
        $directory = (string) config('opes.backup.path');
        File::ensureDirectoryExists($directory);

        $filename = sprintf('opes-%s-%s.sql', $kind->value, now()->format('Ymd-His'));
        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        $backup = Backup::query()->create([
            'kind' => $kind->value,
            'status' => BackupStatus::Running->value,
            'path' => $path,
            'started_at' => now(),
        ]);

        try {
            $this->dump($path);

            $backup->update([
                'status' => BackupStatus::Healthy->value,
                'completed_at' => now(),
                'size_bytes' => File::size($path),
                'sha256' => hash_file('sha256', $path),
                'manifest' => $this->manifest(),
            ]);
        } catch (Throwable $e) {
            $backup->update([
                'status' => BackupStatus::Failed->value,
                'completed_at' => now(),
                'failure_detail' => $e->getMessage(),
            ]);
        }

        return $backup->refresh();
    }

    private function dump(string $path): void
    {
        /** @var array{host: string, port: int|string, database: string, username: string, password: string} $c */
        $c = config('database.connections.mysql');

        $process = new Process([
            (string) config('opes.mysql.dump_binary'),
            '--host='.$c['host'],
            '--port='.$c['port'],
            '--user='.$c['username'],
            // An empty --password= is a value, not a prompt: mysqldump 8.4.3
            // takes it as "the password is the empty string" and proceeds.
            // Only bare --password (no =) prompts, which would hang a timer.
            '--password='.$c['password'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--default-character-set=utf8mb4',
            '--result-file='.$path,
            $c['database'],
        ]);

        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'mysqldump failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * A fingerprint the restore drill re-asserts against the restored copy.
     *
     * Row counts alone prove the file loaded. The ledger fingerprint proves the
     * restored database is arithmetically the same one - which is the property
     * an accounting system actually needs (08-operations 3.6).
     *
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $tables = [];

        foreach (DB::select('SHOW TABLES') as $row) {
            $values = (array) $row;
            $name = (string) reset($values);
            $tables[$name] = (int) DB::table($name)->count();
        }

        return [
            'schema_version' => $this->schemaVersion(),
            'tables' => $tables,
            'ledger_fingerprint' => $this->ledgerFingerprint(),
            'taken_at' => now()->toIso8601String(),
        ];
    }

    private function schemaVersion(): string
    {
        $last = DB::table('migrations')->orderByDesc('id')->first();

        return (string) ($last->migration ?? 'none');
    }

    /**
     * Hash of the audit chain head plus its count. Extended in Phase 4 to
     * include the accounting ledger's per-account debit/credit totals, which
     * do not exist yet.
     */
    private function ledgerFingerprint(): string
    {
        $anchor = DB::table('audit_chain_anchors')->find(1);

        return hash('sha256', json_encode([
            'audit_head' => $anchor->last_row_hash ?? null,
            'audit_count' => $anchor->entry_count ?? 0,
        ], JSON_THROW_ON_ERROR));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Domain\BackupKind;
use App\Modules\Operations\Domain\BackupStatus;
use App\Modules\Operations\Models\Backup;
use App\Modules\Operations\Support\IsolatedConnection;
use Illuminate\Database\Connection;
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
     * Computed on an ISOLATED connection, not the application's. mysqldump
     * connects as its own client and sees only committed data; a manifest read
     * on the application's connection would also see that connection's
     * uncommitted writes, and would then describe rows the dump does not
     * contain. The manifest has to describe the FILE, not the caller's view of
     * the database, or the restore drill checks it against the wrong baseline.
     *
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $connection = IsolatedConnection::resolve('opes_manifest');

        try {
            $tables = [];

            foreach ($connection->select('SHOW TABLES') as $row) {
                // reset() takes a reference, so the cast must land in a
                // variable first.
                $values = (array) $row;
                $name = (string) reset($values);
                $tables[$name] = (int) $connection->table($name)->count();
            }

            return [
                'schema_version' => $this->schemaVersion($connection),
                'tables' => $tables,
                'ledger_fingerprint' => $this->ledgerFingerprint($connection),
                'taken_at' => now()->toIso8601String(),
            ];
        } finally {
            IsolatedConnection::release('opes_manifest');
        }
    }

    private function schemaVersion(Connection $connection): string
    {
        $last = $connection->table('migrations')->orderByDesc('id')->first();

        return (string) ($last->migration ?? 'none');
    }

    /**
     * Hash of the audit chain head plus its count. Extended in Phase 4 to
     * include the accounting ledger's per-account debit/credit totals, which
     * do not exist yet.
     */
    private function ledgerFingerprint(Connection $connection): string
    {
        $anchor = $connection->table('audit_chain_anchors')->find(1);

        return hash('sha256', json_encode([
            'audit_head' => $anchor->last_row_hash ?? null,
            'audit_count' => $anchor->entry_count ?? 0,
        ], JSON_THROW_ON_ERROR));
    }
}

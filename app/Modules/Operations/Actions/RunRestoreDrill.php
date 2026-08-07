<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Models\Backup;
use App\Modules\Operations\Models\RestoreDrill;
use App\Modules\Operations\Support\IsolatedConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Restore the newest healthy backup into a scratch schema and prove it is
 * usable (docs/specs/08-operations.md 3.6).
 *
 * mysqldump completing cleanly does not mean the dump restores: charset
 * mismatches, DEFINER clauses and missing routines all produce clean files
 * that fail on load. This is the only control that turns "we have backups"
 * into "we have proven we can restore".
 */
final class RunRestoreDrill
{
    /**
     * Row counts are compared within this fraction of the manifest count.
     *
     * NOT a way to make the assertion easier to satisfy - see assertRowCounts()
     * for why an exact comparison is unsound in production.
     */
    private const ROW_COUNT_TOLERANCE = 0.01;

    private const CONNECTION = 'opes_drill';

    public function handle(): RestoreDrill
    {
        $startedAt = now();

        $drill = RestoreDrill::query()->create([
            'started_at' => $startedAt,
            'status' => 'running',
        ]);

        $backup = Backup::query()->healthy()->orderByDesc('completed_at')->first();

        if ($backup === null) {
            return $this->fail($drill, $startedAt, 'There is no healthy backup to exercise.');
        }

        $schema = 'opes_drill_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
        $passed = 0;

        // CREATE DATABASE and DROP DATABASE carry an implicit commit in MySQL,
        // so they must never run on the application's connection: doing so
        // would silently commit whatever transaction the caller had open.
        $connection = IsolatedConnection::resolve(self::CONNECTION);

        try {
            $connection->statement("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");

            $this->restoreInto($schema, $backup->path);
            $passed++;

            $this->assertTablesPresent($connection, $schema, $backup);
            $passed++;

            $this->assertRowCounts($connection, $schema, $backup);
            $passed++;

            $this->assertLedgerBalances($connection, $schema);
            $passed++;

            $drill->update([
                'backup_id' => $backup->getKey(),
                'status' => 'passed',
                'completed_at' => now(),
                'duration_seconds' => (int) $startedAt->diffInSeconds(now()),
                'assertions_passed' => $passed,
            ]);
        } catch (Throwable $e) {
            $drill->update([
                'backup_id' => $backup->getKey(),
                'status' => 'failed',
                'completed_at' => now(),
                'duration_seconds' => (int) $startedAt->diffInSeconds(now()),
                'assertions_passed' => $passed,
                'failure_detail' => $e->getMessage(),
            ]);
        } finally {
            // ALWAYS drop the scratch schema, including on failure - otherwise a
            // repeatedly failing drill fills the disk it exists to protect.
            $connection->statement("DROP DATABASE IF EXISTS `{$schema}`");
            IsolatedConnection::release(self::CONNECTION);
        }

        return $drill->refresh();
    }

    private function fail(RestoreDrill $drill, Carbon $startedAt, string $why): RestoreDrill
    {
        $drill->update([
            'status' => 'failed',
            'completed_at' => now(),
            'duration_seconds' => (int) $startedAt->diffInSeconds(now()),
            'failure_detail' => $why,
        ]);

        return $drill->refresh();
    }

    private function restoreInto(string $schema, string $path): void
    {
        if (! File::exists($path)) {
            throw new RuntimeException('The backup file is missing from disk.');
        }

        /** @var array{host: string, port: int|string, username: string, password: string} $c */
        $c = config('database.connections.mysql');

        $process = Process::fromShellCommandline(
            sprintf(
                '"%s" --host=%s --port=%s --user=%s --password=%s %s < "%s"',
                (string) config('opes.mysql.client_binary'),
                $c['host'],
                (string) $c['port'],
                $c['username'],
                $c['password'],
                $schema,
                $path,
            )
        );

        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Restore failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    private function assertTablesPresent(Connection $connection, string $schema, Backup $backup): void
    {
        $expected = $this->expectedTables($backup);

        if ($expected === []) {
            throw new RuntimeException('The backup manifest lists no tables.');
        }

        foreach (array_keys($expected) as $table) {
            $found = (int) $connection->scalar(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
                [$schema, $table],
            );

            if ($found === 0) {
                throw new RuntimeException("Restored copy is missing table [{$table}].");
            }
        }
    }

    /**
     * Row counts, compared within a tolerance.
     *
     * An exact comparison is unsound, and deliberately not used. mysqldump
     * takes its consistent snapshot at T1 and CreateBackup writes the manifest
     * at T2, when the dump has finished - minutes later on a real school
     * database. Any payment posted in that window is counted in the manifest
     * but is not in the file, so an exact check would fail on every backup
     * taken during business hours. A drill that cries wolf daily gets ignored,
     * and then it protects nothing. 08-operations 3.6 accordingly asks for
     * counts "within tolerance of the live counts as of the dump time".
     *
     * The two checks that stay STRICT are the ones that actually catch a bad
     * restore: every table in the manifest must exist (assertTablesPresent),
     * and a table the manifest says held rows must never restore empty. A
     * truncated dump, a failed load, or a lost table trips those regardless of
     * tolerance.
     *
     * The systematic half of the drift is fixed at the source rather than
     * papered over here: CreateBackup computes the manifest on an isolated
     * connection, so it can no longer count uncommitted rows that mysqldump was
     * never able to see.
     */
    private function assertRowCounts(Connection $connection, string $schema, Backup $backup): void
    {
        foreach ($this->expectedTables($backup) as $table => $count) {
            $actual = (int) $connection->scalar(
                sprintf('SELECT COUNT(*) FROM `%s`.`%s`', $schema, $table)
            );

            if ($count > 0 && $actual === 0) {
                throw new RuntimeException(
                    "Table [{$table}] restored empty but the manifest recorded {$count} rows."
                );
            }

            $tolerance = (int) ceil($count * self::ROW_COUNT_TOLERANCE);

            if (abs($actual - $count) > $tolerance) {
                throw new RuntimeException(
                    "Row count mismatch for [{$table}]: expected {$count} (+/-{$tolerance}), restored {$actual}"
                );
            }
        }
    }

    /**
     * Phase 0C has no accounting ledger yet, so this asserts the audit chain
     * anchor table survived. PHASE 4 MUST EXTEND THIS to assert
     * sum(debit) = sum(credit) globally and per entry, which is the real
     * acceptance criterion in 08-operations 3.6.
     */
    private function assertLedgerBalances(Connection $connection, string $schema): void
    {
        $exists = (int) $connection->scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$schema, 'audit_chain_anchors'],
        );

        if ($exists === 0) {
            throw new RuntimeException('Restored copy is missing the audit chain anchor table.');
        }
    }

    /**
     * Table names reach the SQL above by interpolation, so they are validated
     * here rather than trusted: the manifest is a JSON column and a tampered
     * row must not become an injection point.
     *
     * @return array<string, int>
     */
    private function expectedTables(Backup $backup): array
    {
        $manifest = $backup->manifest ?? [];
        $tables = $manifest['tables'] ?? null;

        if (! is_array($tables)) {
            return [];
        }

        $expected = [];

        foreach ($tables as $name => $count) {
            $name = (string) $name;

            if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
                throw new RuntimeException("Manifest contains an unusable table name [{$name}].");
            }

            $expected[$name] = (int) $count;
        }

        return $expected;
    }
}

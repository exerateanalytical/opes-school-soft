<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health\Checks;

use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\HealthCheck;
use Illuminate\Support\Facades\DB;

/**
 * innodb_flush_log_at_trx_commit and sync_binlog decide whether a committed
 * transaction is on the disk platter or still in a buffer. In Cameroon the
 * power goes out; the difference is whether a receipt the parent is holding
 * still exists after the lights come back.
 *
 * This WARNS rather than fails. The application cannot change server
 * configuration, and a permanently red light that nobody can clear is a light
 * that gets ignored (08-operations 7).
 */
final class MysqlDurabilityCheck implements HealthCheck
{
    public function run(): HealthCheckResult
    {
        $flush = $this->variable('innodb_flush_log_at_trx_commit');
        $binlog = $this->variable('sync_binlog');

        $problems = [];

        if ($flush !== '1') {
            $problems[] = "innodb_flush_log_at_trx_commit is {$flush}, not 1";
        }

        if ($binlog !== '1') {
            $problems[] = "sync_binlog is {$binlog}, not 1";
        }

        if ($problems !== []) {
            return new HealthCheckResult(
                key: 'mysql.durability',
                label: 'Power-cut safety',
                status: HealthStatus::Amber,
                detail: 'The database is not set to write every confirmed transaction '
                    .'straight to disk ('.implode('; ', $problems).').',
                remedy: 'Ask whoever installed the system to set '
                    .'innodb_flush_log_at_trx_commit = 1 and sync_binlog = 1 in the MySQL '
                    .'configuration and restart the service. Until then, a power cut can '
                    .'erase a payment that was already receipted, and the parent will have '
                    .'the paper and you will not have the record.',
            );
        }

        return new HealthCheckResult(
            key: 'mysql.durability',
            label: 'Power-cut safety',
            status: HealthStatus::Ok,
            detail: 'Every confirmed transaction is written straight to disk.',
            remedy: '',
        );
    }

    private function variable(string $name): string
    {
        $value = DB::connection()->scalar('SELECT @@'.$name);

        return is_scalar($value) ? (string) $value : 'unknown';
    }
}

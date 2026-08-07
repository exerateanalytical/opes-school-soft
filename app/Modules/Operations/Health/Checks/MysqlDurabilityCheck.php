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
            $problems[] = (string) __('opes.health.mysql_durability.problem_flush', ['value' => $flush]);
        }

        if ($binlog !== '1') {
            $problems[] = (string) __('opes.health.mysql_durability.problem_binlog', ['value' => $binlog]);
        }

        if ($problems !== []) {
            return new HealthCheckResult(
                key: 'mysql.durability',
                label: (string) __('opes.health.mysql_durability.label'),
                status: HealthStatus::Amber,
                detail: (string) __('opes.health.mysql_durability.amber_detail', ['problems' => implode('; ', $problems)]),
                remedy: (string) __('opes.health.mysql_durability.amber_remedy'),
            );
        }

        return new HealthCheckResult(
            key: 'mysql.durability',
            label: (string) __('opes.health.mysql_durability.label'),
            status: HealthStatus::Ok,
            detail: (string) __('opes.health.mysql_durability.ok_detail'),
            remedy: '',
        );
    }

    private function variable(string $name): string
    {
        $value = DB::connection()->scalar('SELECT @@'.$name);

        return is_scalar($value) ? (string) $value : 'unknown';
    }
}

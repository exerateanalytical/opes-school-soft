<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health\Checks;

use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\HealthCheck;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DatabaseCheck implements HealthCheck
{
    public function run(): HealthCheckResult
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();

            // Scoped to THIS database explicitly. getTableListing() with no
            // argument enumerates every schema on the server, and on a shared
            // Laragon or VPS that reported 2208 tables for an install that has
            // eleven - a number nobody could sanity-check.
            $tables = count($connection->getSchemaBuilder()->getTableListing(
                $connection->getDatabaseName(),
                false,
            ));

            return new HealthCheckResult(
                key: 'database.reachable',
                label: (string) __('opes.health.database.label'),
                status: HealthStatus::Ok,
                detail: (string) __('opes.health.database.ok_detail', ['tables' => $tables]),
                remedy: '',
            );
        } catch (Throwable $e) {
            return new HealthCheckResult(
                key: 'database.reachable',
                label: (string) __('opes.health.database.label'),
                status: HealthStatus::Red,
                detail: (string) __('opes.health.database.red_detail', ['reason' => $this->plain($e)]),
                remedy: (string) __('opes.health.database.red_remedy'),
            );
        }
    }

    /**
     * Driver messages can carry the connection string, and the connection
     * string can carry the password. Only the short reason is shown.
     */
    private function plain(Throwable $e): string
    {
        $message = $e->getMessage();
        $cut = strpos($message, '(');

        return trim($cut === false ? $message : substr($message, 0, $cut));
    }
}

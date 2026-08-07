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

            $tables = count($connection->getSchemaBuilder()->getTableListing());

            return new HealthCheckResult(
                key: 'database.reachable',
                label: 'Database',
                status: HealthStatus::Ok,
                detail: "Reachable, {$tables} tables.",
                remedy: '',
            );
        } catch (Throwable $e) {
            return new HealthCheckResult(
                key: 'database.reachable',
                label: 'Database',
                status: HealthStatus::Red,
                detail: 'The database did not answer: '.$this->plain($e),
                remedy: 'The database service is not running or has stopped accepting '
                    .'connections. Ask whoever installed the system to restart the MySQL '
                    .'service, then reload this page. Do not take any payments until it is back.',
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

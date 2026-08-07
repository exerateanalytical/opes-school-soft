<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health\Checks;

use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\HealthCheck;
use Illuminate\Foundation\Application;

/**
 * Informational only. It is never red, but it is the first thing support will
 * ask for, and a bursar can read it off the screen without finding a terminal.
 */
final class AppVersionCheck implements HealthCheck
{
    public function run(): HealthCheckResult
    {
        $version = (string) config('app.version', 'dev');
        $environment = (string) config('app.env', 'production');

        return new HealthCheckResult(
            key: 'app.version',
            label: 'Software version',
            status: HealthStatus::Ok,
            detail: sprintf(
                'OPES SCHOOL %s (%s), Laravel %s on PHP %s.',
                $version,
                $environment,
                Application::VERSION,
                PHP_VERSION,
            ),
            remedy: '',
        );
    }
}

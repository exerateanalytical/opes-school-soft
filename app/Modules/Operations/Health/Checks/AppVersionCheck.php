<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health\Checks;

use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\HealthCheck;

/**
 * Informational only. It is never red, but it is the first thing support will
 * ask for, and a bursar can read it off the screen without finding a terminal.
 *
 * Deliberately does NOT name the PHP or Laravel patch version. This result is
 * published on /up, which is unauthenticated, and "PHP 8.3.30" is a shopping
 * list of applicable CVEs for anyone scanning. The opes:health command prints
 * the exact versions instead, because reaching that requires server access
 * already.
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
            detail: sprintf('OPES SCHOOL %s, running in the %s environment.', $version, $environment),
            remedy: '',
        );
    }
}

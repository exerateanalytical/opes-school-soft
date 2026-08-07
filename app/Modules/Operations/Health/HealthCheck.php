<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health;

use App\Modules\Operations\Domain\HealthCheckResult;

interface HealthCheck
{
    public function run(): HealthCheckResult;
}

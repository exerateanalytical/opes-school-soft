<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health\Checks;

use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\HealthCheck;
use Illuminate\Database\Migrations\Migrator;

/**
 * A half-upgraded database is the state in which the application still starts
 * and then writes rows into a shape the next release does not expect.
 */
final class MigrationsCheck implements HealthCheck
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function run(): HealthCheckResult
    {
        $paths = array_merge($this->migrator->paths(), [database_path('migrations')]);

        /** @var array<string, string> $files */
        $files = $this->migrator->getMigrationFiles($paths);

        if (! $this->migrator->repositoryExists()) {
            return new HealthCheckResult(
                key: 'migrations.pending',
                label: (string) __('opes.health.migrations.label'),
                status: HealthStatus::Red,
                detail: (string) __('opes.health.migrations.never_prepared_detail'),
                remedy: (string) __('opes.health.migrations.never_prepared_remedy'),
            );
        }

        /** @var list<string> $ran */
        $ran = $this->migrator->getRepository()->getRan();

        $pending = array_diff(array_keys($files), $ran);
        $count = count($pending);

        if ($count > 0) {
            return new HealthCheckResult(
                key: 'migrations.pending',
                label: (string) __('opes.health.migrations.label'),
                status: HealthStatus::Red,
                detail: $count === 1
                    ? (string) __('opes.health.migrations.pending_detail_one')
                    : (string) __('opes.health.migrations.pending_detail_many', ['count' => $count]),
                remedy: (string) __('opes.health.migrations.pending_remedy'),
            );
        }

        return new HealthCheckResult(
            key: 'migrations.pending',
            label: (string) __('opes.health.migrations.label'),
            status: HealthStatus::Ok,
            detail: (string) __('opes.health.migrations.ok_detail', ['count' => count($files)]),
            remedy: '',
        );
    }
}

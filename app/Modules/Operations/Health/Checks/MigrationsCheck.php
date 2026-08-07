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
                label: 'Database upgrades',
                status: HealthStatus::Red,
                detail: 'The database has never been prepared for this software.',
                remedy: 'Run: php artisan migrate --force',
            );
        }

        /** @var list<string> $ran */
        $ran = $this->migrator->getRepository()->getRan();

        $pending = array_diff(array_keys($files), $ran);
        $count = count($pending);

        if ($count > 0) {
            return new HealthCheckResult(
                key: 'migrations.pending',
                label: 'Database upgrades',
                status: HealthStatus::Red,
                detail: $count === 1
                    ? 'One database upgrade has not been applied yet.'
                    : "{$count} database upgrades have not been applied yet.",
                remedy: 'Take a backup first (php artisan opes:backup:run), then run: '
                    .'php artisan migrate --force. Until this is done the software and the '
                    .'database disagree about the shape of your records.',
            );
        }

        return new HealthCheckResult(
            key: 'migrations.pending',
            label: 'Database upgrades',
            status: HealthStatus::Ok,
            detail: 'All '.count($files).' upgrades applied.',
            remedy: '',
        );
    }
}

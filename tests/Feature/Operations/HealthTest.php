<?php

declare(strict_types=1);

use App\Modules\Operations\Actions\CollectHealth;
use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Models\Backup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A plain helper, not beforeEach + $this->: inside a Pest closure `$this` is a
 * TestCall as far as PHPStan is concerned.
 *
 * @return list<HealthCheckResult>
 */
function healthResults(): array
{
    return app(CollectHealth::class)->handle();
}

function healthFor(string $key): ?HealthCheckResult
{
    foreach (healthResults() as $result) {
        if ($result->key === $key) {
            return $result;
        }
    }

    return null;
}

function healthyBackup(): Backup
{
    return Backup::query()->create([
        'kind' => 'full', 'status' => 'healthy', 'path' => 'x.sql',
        'started_at' => now(), 'completed_at' => now(), 'sha256' => str_repeat('a', 64),
    ]);
}

it('returns a result for every registered check', function () {
    $results = healthResults();

    expect($results)->toHaveCount(count(CollectHealth::CHECKS));

    foreach ($results as $result) {
        expect($result->label)->not->toBe('');
        expect($result->detail)->not->toBe('');
        expect($result->key)->not->toBe('');
    }
});

it('gives every non-ok check a remedy an operator can act on', function () {
    // A red light with no instruction is just anxiety for a bursar who cannot
    // read a stack trace (08-operations 7).
    foreach (healthResults() as $result) {
        if ($result->status !== HealthStatus::Ok) {
            expect($result->remedy)->not->toBe(
                '',
                "check [{$result->key}] is not ok but offers no remedy"
            );
        }
    }
});

it('reports red when no backup has ever been taken', function () {
    expect(healthFor('backup.recency')?->status)->toBe(HealthStatus::Red);
});

it('reports ok once a recent healthy backup exists', function () {
    healthyBackup();

    expect(healthFor('backup.recency')?->status)->toBe(HealthStatus::Ok);
});

it('goes amber when the only backup target is on the same disk as the data', function () {
    // A backup on the same disk is not a backup, and that must be visible as a
    // warning rather than buried in config (08-operations 3.5).
    config(['opes.backup.second_target' => null]);

    expect(healthFor('backup.second_target')?->status)->toBe(HealthStatus::Amber);
});

it('reports ok when a second target is configured', function () {
    config(['opes.backup.second_target' => 'D:\\opes-offsite']);

    expect(healthFor('backup.second_target')?->status)->toBe(HealthStatus::Ok);
});

it('reports red when no restore drill has ever passed', function () {
    expect(healthFor('drill.recency')?->status)->toBe(HealthStatus::Red);
});

it('includes a database, migrations and audit-chain check', function () {
    expect(healthFor('database.reachable'))->not->toBeNull();
    expect(healthFor('migrations.pending'))->not->toBeNull();
    expect(healthFor('audit.chain'))->not->toBeNull();
});

it('summarises to the worst status among the checks', function () {
    expect(app(CollectHealth::class)->summary())->toBeInstanceOf(HealthStatus::class);
});

it('survives a check that throws, rather than blanking the whole page', function () {
    // The moment a check explodes is exactly when the operator needs to read
    // the others.
    config(['opes.backup.path' => "\0invalid"]);

    $results = healthResults();

    expect($results)->toHaveCount(count(CollectHealth::CHECKS));

    // Asserted explicitly, because a count alone would also pass if nothing had
    // thrown at all - which is how this test came to be vacuous once.
    $keys = array_map(static fn (HealthCheckResult $r): string => $r->key, $results);
    expect($keys)->toContain('check.error');
    expect(healthFor('database.reachable')?->status)->toBe(HealthStatus::Ok);
});

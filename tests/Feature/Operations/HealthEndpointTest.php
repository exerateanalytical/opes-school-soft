<?php

declare(strict_types=1);

use App\Modules\Operations\Actions\CollectHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Pest's functional Laravel API rather than $this->getJson(): inside a Pest
// closure PHPStan resolves $this to Pest\PendingCalls\TestCall, which has no
// HTTP methods. Same reason this file uses plain helpers over beforeEach.
use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

it('exposes machine-readable health at /up', function () {
    getJson('/up')
        ->assertOk()
        ->assertJsonStructure(['status', 'version', 'checks' => [['key', 'label', 'status', 'detail']]]);
});

it('reveals nothing sensitive at /up', function () {
    // /up is unauthenticated so a monitor can poll it. It must therefore never
    // leak credentials, absolute paths or student data.
    $body = (string) getJson('/up')->getContent();

    expect(strtolower($body))->not->toContain('password');

    $dbPassword = (string) config('database.connections.mysql.password');
    if ($dbPassword !== '') {
        expect($body)->not->toContain($dbPassword);
    }
});

it('never publishes an absolute filesystem path at /up', function () {
    // A check that wants to name the backup folder must say "the backup
    // folder". The install layout is not a monitor's business, and it is the
    // first thing an attacker asks for.
    config(['opes.backup.path' => "\0invalid"]);

    $body = (string) getJson('/up')->getContent();

    expect($body)->not->toContain(str_replace('\\', '\\\\', base_path()));
    expect($body)->not->toMatch('/[A-Za-z]:\\\\\\\\/');
    expect($body)->not->toContain('/var/www');
});

it('answers with a status even when a check has exploded', function () {
    // The stock Laravel /up answers "PHP is running". This one has to keep
    // answering when part of what it reports on is broken, or a monitor learns
    // nothing from the outage it exists to catch.
    config(['opes.backup.path' => "\0invalid"]);

    $response = getJson('/up');

    $response->assertOk();
    expect($response->json('status'))->toBe('red');
    expect($response->json('checks'))->toHaveCount(count(CollectHealth::CHECKS));
});

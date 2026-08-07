<?php

declare(strict_types=1);

use App\Console\Commands\PreflightCommand;

it('reports the required php extensions', function () {
    $missing = PreflightCommand::missingExtensions();

    expect($missing)->toBe([]);
});

it('accepts a MySQL 8 version string', function () {
    expect(PreflightCommand::isSupportedDatabase('8.4.3'))->toBeTrue();
    expect(PreflightCommand::isSupportedDatabase('8.0.36'))->toBeTrue();
});

it('rejects MariaDB however it identifies itself', function () {
    expect(PreflightCommand::isSupportedDatabase('10.4.32-MariaDB'))->toBeFalse();
    expect(PreflightCommand::isSupportedDatabase('5.5.5-10.11.2-MariaDB-log'))->toBeFalse();
    expect(PreflightCommand::isSupportedDatabase('11.4.2-MariaDB'))->toBeFalse();
});

it('rejects MySQL below 8', function () {
    expect(PreflightCommand::isSupportedDatabase('5.7.44'))->toBeFalse();
});

it('requires argon2id to be available', function () {
    expect(PreflightCommand::hasArgon2id())->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Modules\Operations\Domain\BackupKind;
use App\Modules\Operations\Domain\BackupStatus;

it('knows which statuses represent a usable backup', function () {
    expect(BackupStatus::Healthy->isUsable())->toBeTrue();
    expect(BackupStatus::Corrupt->isUsable())->toBeFalse();
    expect(BackupStatus::Failed->isUsable())->toBeFalse();
    expect(BackupStatus::Running->isUsable())->toBeFalse();
});

it('knows a running backup is not yet a result', function () {
    expect(BackupStatus::Running->isTerminal())->toBeFalse();
    expect(BackupStatus::Healthy->isTerminal())->toBeTrue();
});

it('has stable string values usable as database keys', function () {
    expect(BackupStatus::Healthy->value)->toBe('healthy');
    expect(BackupKind::Full->value)->toBe('full');
});

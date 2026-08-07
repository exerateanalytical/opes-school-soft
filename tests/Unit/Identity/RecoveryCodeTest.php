<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\RecoveryCode;

it('generates four groups of five characters', function () {
    expect(RecoveryCode::generate()->formatted())->toMatch('/^[0-9A-Z]{5}-[0-9A-Z]{5}-[0-9A-Z]{5}-[0-9A-Z]{5}$/');
});

it('excludes the ambiguous characters', function () {
    // 00-core 9.3: a Crockford-style alphabet without 0/O/1/I/L/U, because the
    // code is read off paper by a stressed person under time pressure.
    for ($i = 0; $i < 200; $i++) {
        expect(RecoveryCode::generate()->formatted())->not->toMatch('/[0O1ILU]/');
    }
});

it('carries at least 90 bits of entropy', function () {
    expect(RecoveryCode::entropyBits())->toBeGreaterThanOrEqual(90);
});

it('normalises user input, tolerating case and missing dashes', function () {
    $code = RecoveryCode::generate();
    $messy = strtolower(str_replace('-', ' ', $code->formatted()));

    expect(RecoveryCode::normalise($messy))->toBe($code->normalised());
});

it('produces different codes each time', function () {
    $codes = [];
    for ($i = 0; $i < 100; $i++) {
        $codes[] = RecoveryCode::generate()->normalised();
    }

    expect(array_unique($codes))->toHaveCount(100);
});

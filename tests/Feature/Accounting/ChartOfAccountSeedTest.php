<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 02-accounting.md 2.3: "a wrong seeded value is more dangerous than an
 * empty one." Nothing from the NEEDS-VERIFICATION table may ever appear in
 * the seeded chart of accounts, and the seeded set otherwise matches the
 * "Verified and seeded" table exactly.
 */
it('never seeds any NEEDS-VERIFICATION code', function () {
    $forbidden = [
        // 707x subdivisions beyond the verified 7073/7077/7078.
        '7071', '7072', '7074', '7075', '7076', '7079',
        // 706 5-digit tuition extensions.
        '70611', '70612',
        // 658/758 cash shortage/overage.
        '658', '758',
        // 491 provision for doubtful receivables.
        '491',
        // 845 quote-part de subvention, and the commonly-cited-but-wrong 865.
        '845', '865',
        // 151 amortissements derogatoires.
        '151',
        // 106 ecart de reevaluation.
        '106',
        // 428x provision for leave.
        '4281', '4282', '428',
    ];

    $seeded = ChartOfAccount::query()->pluck('code')->all();

    foreach ($forbidden as $code) {
        expect($seeded)->not->toContain($code);
    }
});

it('specifically does not seed 865, the commonly-cited-but-wrong code for quote-part de subvention', function () {
    expect(ChartOfAccount::query()->where('code', '865')->exists())->toBeFalse();
});

it('seeds exactly the accounts in the "Verified and seeded" table, plus their structural scaffolding', function () {
    $expectedCodes = [
        // Class 1
        '1', '11', '12', '13', '14',
        // Class 2
        '2', '24', '244', '2441', '2442', '249', '28', '29',
        // Class 3
        '3', '31', '32', '33',
        // Class 4
        '4', '40', '401', '41', '411', '4111', '4112', '4114',
        '416', '4161', '4162', '418', '4181', '419', '4191', '4198',
        '47', '476', '477', '48', '481', '4812', '4817', '4818', '485',
        // Class 5
        '5', '52', '55', '552', '57',
        // Class 6
        '6', '60', '601', '602', '603', '6031', '6032', '6033', '604',
        '63', '631', '6317',
        // Class 7
        '7', '70', '701', '706', '707', '7073', '7077', '7078',
        // Class 8
        '8', '81', '811', '812', '816', '82', '821', '822', '826',
        '89', '891', '892', '895', '899',
        // Class 9 (root only)
        '9',
    ];

    $seeded = ChartOfAccount::query()->pluck('code')->sort()->values()->all();
    sort($expectedCodes);

    expect($seeded)->toBe($expectedCodes);
    expect(ChartOfAccount::query()->count())->toBe(count($expectedCodes));
});

it('every seeded account is is_system = true', function () {
    expect(ChartOfAccount::query()->where('is_system', false)->count())->toBe(0);
});

it('every seeded account currency is XAF', function () {
    expect(ChartOfAccount::query()->where('currency', '!=', 'XAF')->count())->toBe(0);
});

it('every seeded account carries a citation in notes', function () {
    expect(ChartOfAccount::query()->whereNull('notes')->orWhere('notes', '')->count())->toBe(0);
});

it('2442 is IT equipment and 2441 is office equipment, not swapped (the v1 regression)', function () {
    $itEquipment = ChartOfAccount::query()->where('code', '2442')->firstOrFail();
    $officeEquipment = ChartOfAccount::query()->where('code', '2441')->firstOrFail();

    expect($itEquipment->name_fr)->toBe('Materiel informatique');
    expect($officeEquipment->name_fr)->toBe('Materiel de bureau');
});

it('55/552 (mobile money) is seeded, and 5210 is never seeded (the v1 regression)', function () {
    expect(ChartOfAccount::query()->where('code', '55')->exists())->toBeTrue();
    expect(ChartOfAccount::query()->where('code', '552')->exists())->toBeTrue();
    expect(ChartOfAccount::query()->where('code', '5210')->exists())->toBeFalse();
});

it('706 is seeded as a plain account with no seeded 5-digit tuition child (the v1 regression)', function () {
    expect(ChartOfAccount::query()->where('code', '706')->exists())->toBeTrue();
    expect(ChartOfAccount::query()->where('code', 'like', '706%')->where('code', '!=', '706')->exists())->toBeFalse();
});

it('every account with a seeded child is is_postable = false (CoA-4 holds across the seed)', function () {
    $codes = ChartOfAccount::query()->pluck('code');
    $parentCodes = $codes->filter(fn (string $code) => $codes->contains(
        fn (string $other) => $other !== $code && str_starts_with($other, $code)
    ));

    $nonPostableParents = ChartOfAccount::query()
        ->whereIn('code', $parentCodes)
        ->where('is_postable', true)
        ->count();

    expect($nonPostableParents)->toBe(0);
});

it('the collective client/supplier accounts carry requires_partner and allowed_partner_types', function () {
    foreach (['401', '4111', '4812'] as $code) {
        $account = ChartOfAccount::query()->where('code', $code)->firstOrFail();

        expect($account->is_collective)->toBeTrue();
        expect($account->requires_partner)->toBeTrue();
        expect($account->allowed_partner_types)->not->toBeEmpty();
    }
});

it('the treasury leaf accounts are reconcilable', function () {
    foreach (['52', '552', '57'] as $code) {
        expect(ChartOfAccount::query()->where('code', $code)->value('is_reconcilable'))->toBeTrue();
    }
});

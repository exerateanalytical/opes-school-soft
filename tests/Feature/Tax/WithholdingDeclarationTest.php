<?php

declare(strict_types=1);

use App\Modules\Tax\Actions\FileTaxDeclaration;
use App\Modules\Tax\Actions\GenerateWithholdingDeclaration;
use App\Modules\Tax\Domain\DeclarationStatus;
use App\Modules\Tax\Models\WithholdingAttestation;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/DeclarationTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * 03-tax-procurement §7.3 / §11.8 - the withholding declaration:
 * attestation ↔ declaration ↔ 447 three-way reconciliation, the
 * per-supplier annex, and the block on a manual 447 movement that no
 * attestation covers.
 */
if (! function_exists('f5DeclWithholdingFixture')) {
    /**
     * Two suppliers, one confirmed 5.5% rule, issued attestations for
     * 2031-03 totalling 121 000, and the matching 447 credit.
     *
     * @return array{calendar: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}, liability: int, rule: \App\Modules\Tax\Models\WithholdingRule, attestations: list<WithholdingAttestation>}
     */
    function f5DeclWithholdingFixture(bool $postLedgerLeg = true): array
    {
        f5DeclConfirmedIdentity();
        f5DeclType('withholding_monthly');

        $calendar = f5DeclCalendar('2031-03-15');
        $liability = f5DeclAccount();
        $rule = f5DeclRule($liability->id, rateBp: 5_500);

        $alpha = f5DeclSupplier('Alpha Fournitures', 'M111222333444A');
        $beta = f5DeclSupplier('Beta Livres', 'M555666777888B');

        $attestations = [
            f5DeclAttestation($alpha, f5DeclInvoiceRow($alpha, $calendar), $rule->id, 2031, 3, 2_000_000, 5_500, 110_000),
            f5DeclAttestation($beta, f5DeclInvoiceRow($beta, $calendar), $rule->id, 2031, 3, 200_000, 5_500, 11_000),
        ];

        if ($postLedgerLeg) {
            $counterpart = f5DeclAccount();
            f5DeclPostEntry($calendar, '2031-03-14', 'F5-WH-447', [
                ['account_id' => $counterpart->id, 'debit' => 121_000, 'credit' => 0],
                ['account_id' => $liability->id, 'debit' => 0, 'credit' => 121_000],
            ]);
        }

        f5DeclLockPeriod($calendar['accounting_period_id']);

        return ['calendar' => $calendar, 'liability' => $liability->id, 'rule' => $rule, 'attestations' => $attestations];
    }
}

it('refuses while the withholding_monthly type is not configured', function () {
    f5DeclConfirmedIdentity();
    $user = f5DeclUser('tax.declare');
    $calendar = f5DeclCalendar('2031-03-15');
    f5DeclLockPeriod($calendar['accounting_period_id']);

    app(GenerateWithholdingDeclaration::class)->handle(2031, 3, f5DeclActor($user));
})->throws(DomainException::class, 'withholding_monthly declaration type is not configured');

it('generates from the issued attestations, reconciled to the 447 movement', function () {
    $fixture = f5DeclWithholdingFixture();
    $user = f5DeclUser('tax.declare');

    $declaration = app(GenerateWithholdingDeclaration::class)->handle(2031, 3, f5DeclActor($user));

    expect($declaration->status)->toBe(DeclarationStatus::Generated)
        ->and($declaration->amount_declared)->toBe(121_000);

    // §7.3: the per-supplier annex - name, NIU, base, rate, withheld -
    // required by the form and impossible to reconstruct later.
    $annex = $declaration->lines()->where('line_code', 'WH_ANNEX')->get();
    expect($annex)->toHaveCount(2);

    $alphaLine = $annex->firstWhere('supplier_name', 'Alpha Fournitures');
    expect($alphaLine)->not->toBeNull()
        ->and($alphaLine?->supplier_niu)->toBe('M111222333444A')
        ->and($alphaLine?->base_amount)->toBe(2_000_000)
        ->and($alphaLine?->rate_bp)->toBe(5_500)
        ->and($alphaLine?->tax_amount)->toBe(110_000);

    $total = $declaration->lines()->where('line_code', 'WH_TOTAL')->firstOrFail();
    expect($total->tax_amount)->toBe(121_000)
        ->and($total->base_amount)->toBe(2_200_000);

    // §6.6: every attestation of the period is stamped into the declaration.
    foreach ($fixture['attestations'] as $attestation) {
        expect($attestation->refresh()->tax_declaration_id)->toBe($declaration->id);
    }
});

it('blocks generation when the 447 moved without a matching attestation', function () {
    // §11.8: a manually inserted 447 movement without an attestation.
    $fixture = f5DeclWithholdingFixture();
    $counterpart = f5DeclAccount();
    f5DeclPostEntry($fixture['calendar'], '2031-03-20', 'F5-WH-MANUAL', [
        ['account_id' => $counterpart->id, 'debit' => 9_999, 'credit' => 0],
        ['account_id' => $fixture['liability'], 'debit' => 0, 'credit' => 9_999],
    ]);
    $user = f5DeclUser('tax.declare');

    expect(fn () => app(GenerateWithholdingDeclaration::class)->handle(2031, 3, f5DeclActor($user)))
        ->toThrow(DomainException::class, 'does not reconcile');
});

it('blocks generation when an attestation was never posted to the 447', function () {
    // The mismatch blocks in the OTHER direction too.
    f5DeclWithholdingFixture(postLedgerLeg: false);
    $user = f5DeclUser('tax.declare');

    expect(fn () => app(GenerateWithholdingDeclaration::class)->handle(2031, 3, f5DeclActor($user)))
        ->toThrow(DomainException::class, 'does not reconcile');
});

it('excludes cancelled attestations from the declaration and the reconciliation', function () {
    $fixture = f5DeclWithholdingFixture();

    // Beta's attestation is cancelled - and its 447 leg reversed, as the
    // cancel cascade would do.
    $fixture['attestations'][1]->forceFill([
        'status' => 'cancelled',
        'cancelled_at' => now(),
        'cancellation_reason' => 'test cancel',
    ])->save();
    $counterpart = f5DeclAccount();
    f5DeclPostEntry($fixture['calendar'], '2031-03-21', 'F5-WH-REV', [
        ['account_id' => $fixture['liability'], 'debit' => 11_000, 'credit' => 0],
        ['account_id' => $counterpart->id, 'debit' => 0, 'credit' => 11_000],
    ]);

    $user = f5DeclUser('tax.declare');
    $declaration = app(GenerateWithholdingDeclaration::class)->handle(2031, 3, f5DeclActor($user));

    expect($declaration->amount_declared)->toBe(110_000)
        ->and($declaration->lines()->where('line_code', 'WH_ANNEX')->count())->toBe(1)
        ->and($fixture['attestations'][1]->refresh()->tax_declaration_id)->toBeNull();
});

it('files with hash re-verification against the attestation set', function () {
    $fixture = f5DeclWithholdingFixture();
    $user = f5DeclUser('tax.declare', 'tax.file');

    $declaration = app(GenerateWithholdingDeclaration::class)->handle(2031, 3, f5DeclActor($user));

    $filed = app(FileTaxDeclaration::class)->handle($declaration->id, 'impots_cm', 'DGI-WH-042', f5DeclActor($user));
    expect($filed->status)->toBe(DeclarationStatus::Filed);

    // And the register survives: Σ withheld over issued attestations of
    // the period still equals the filed amount (§6.6 invariant 3).
    $sum = (int) WithholdingAttestation::query()
        ->where('period_year', 2031)->where('period_month', 3)
        ->where('status', 'issued')
        ->sum('withheld_amount');
    expect($sum)->toBe($filed->amount_declared);
});

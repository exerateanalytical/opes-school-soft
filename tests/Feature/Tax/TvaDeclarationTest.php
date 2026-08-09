<?php

declare(strict_types=1);

use App\Modules\Tax\Actions\AmendTaxDeclaration;
use App\Modules\Tax\Actions\FileTaxDeclaration;
use App\Modules\Tax\Actions\GenerateTvaDeclaration;
use App\Modules\Tax\Domain\DeclarationStatus;
use App\Modules\Tax\Models\TaxCredit;
use App\Modules\Tax\Models\TaxDeclaration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/DeclarationTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * 03-tax-procurement §7.2 - TVA declaration generation and filing:
 * soft-lock gate, one-per-period (Action refusal AND DB unique backstop),
 * credit carry-forward, inputs_hash re-verification at filing.
 */
if (! function_exists('f5DeclTvaFixture')) {
    /**
     * @return array{calendar: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}, collected: int, deductible: int}
     */
    function f5DeclTvaFixture(int $outputTax = 19_250, int $inputTax = 5_000): array
    {
    f5DeclConfirmedIdentity();
    f5DeclType('tva_monthly');

    $calendar = f5DeclCalendar('2031-03-15');
    $collected = f5DeclAccount();
    $deductible = f5DeclAccount();
    $other = f5DeclAccount();
    f5DeclTaxCode($collected->id, $deductible->id);

    if ($outputTax > 0) {
        f5DeclPostEntry($calendar, '2031-03-10', 'F5-TVA-OUT', [
            ['account_id' => $other->id, 'debit' => $outputTax, 'credit' => 0],
            ['account_id' => $collected->id, 'debit' => 0, 'credit' => $outputTax],
        ]);
    }

    if ($inputTax > 0) {
        f5DeclPostEntry($calendar, '2031-03-12', 'F5-TVA-IN', [
            ['account_id' => $deductible->id, 'debit' => $inputTax, 'credit' => 0],
            ['account_id' => $other->id, 'debit' => 0, 'credit' => $inputTax],
        ]);
    }

        f5DeclLockPeriod($calendar['accounting_period_id']);

        return ['calendar' => $calendar, 'collected' => $collected->id, 'deductible' => $deductible->id];
    }
}

it('refuses to generate while the tva_monthly type is not configured', function () {
    // 00-core §16: the reference list ships empty-and-blocking.
    f5DeclConfirmedIdentity();
    $user = f5DeclUser('tax.declare');
    $calendar = f5DeclCalendar('2031-03-15');
    f5DeclLockPeriod($calendar['accounting_period_id']);

    app(GenerateTvaDeclaration::class)->handle(2031, 3, f5DeclActor($user));
})->throws(DomainException::class, 'tva_monthly declaration type is not configured');

it('refuses to generate from a period that is still open', function () {
    f5DeclConfirmedIdentity();
    f5DeclType('tva_monthly');
    $user = f5DeclUser('tax.declare');
    $calendar = f5DeclCalendar('2031-03-15');
    $collected = f5DeclAccount();
    f5DeclTaxCode($collected->id, f5DeclAccount()->id);

    app(GenerateTvaDeclaration::class)->handle(2031, 3, f5DeclActor($user));
})->throws(DomainException::class, 'still OPEN');

it('refuses generation without the tax.declare permission', function () {
    f5DeclTvaFixture();
    $user = f5DeclUser(); // signed in, holds nothing

    expect(fn () => app(GenerateTvaDeclaration::class)->handle(2031, 3, f5DeclActor($user)))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('generates output, deductible and net from the posted ledger', function () {
    $fixture = f5DeclTvaFixture(outputTax: 19_250, inputTax: 5_000);
    $user = f5DeclUser('tax.declare');

    $declaration = app(GenerateTvaDeclaration::class)->handle(2031, 3, f5DeclActor($user));

    expect($declaration->status)->toBe(DeclarationStatus::Generated)
        ->and($declaration->amount_declared)->toBe(14_250)
        ->and($declaration->inputs_hash)->not->toBeNull()
        ->and($declaration->generated_by)->toBe($user->id);

    $lineAmount = fn (string $code): int => $declaration->lines()->where('line_code', $code)->firstOrFail()->tax_amount;
    expect($lineAmount('TVA_OUTPUT'))->toBe(19_250)
        ->and($lineAmount('TVA_INPUT_DEDUCTIBLE'))->toBe(5_000)
        ->and($lineAmount('TVA_NET'))->toBe(14_250);

    // The pivot names exactly the contributing lines: the collected leg
    // and the deductible leg (the counterpart account is not a tax
    // account and contributes nothing).
    expect($declaration->entries()->count())->toBe(2);
});

it('refuses a second generation for the same period and backs it with the DB unique', function () {
    $fixture = f5DeclTvaFixture();
    $user = f5DeclUser('tax.declare');

    $declaration = app(GenerateTvaDeclaration::class)->handle(2031, 3, f5DeclActor($user));

    expect(fn () => app(GenerateTvaDeclaration::class)->handle(2031, 3, f5DeclActor($user)))
        ->toThrow(DomainException::class, 'already exists');

    // The idempotency BACKSTOP is the unique key itself (§7.1) - a writer
    // that bypasses the Action still cannot double the period.
    expect(function () use ($declaration) {
        DB::table('tax_declarations')->insert([
            'declaration_type' => 'tva_monthly',
            'period_type' => 'month',
            'period_year' => 2031,
            'period_month' => 3,
            'fiscal_year_id' => $declaration->fiscal_year_id,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(Illuminate\Database\QueryException::class);
});

it('carries a negative net forward as a TaxCredit and consumes it next month', function () {
    // March: deductible 30 000 > output 19 250 → credit 10 750, declared 0.
    $fixture = f5DeclTvaFixture(outputTax: 19_250, inputTax: 30_000);
    $user = f5DeclUser('tax.declare');

    $march = app(GenerateTvaDeclaration::class)->handle(2031, 3, f5DeclActor($user));

    expect($march->amount_declared)->toBe(0);

    /** @var TaxCredit $credit */
    $credit = TaxCredit::query()->where('source_declaration_id', $march->id)->firstOrFail();
    expect($credit->amount)->toBe(10_750)
        ->and($credit->consumed_in_declaration_id)->toBeNull();

    // April: output 50 000, no input → net 50 000 − 10 750 = 39 250.
    $collected = (int) DB::table('tax_codes')->whereNotNull('collected_account_id')->value('collected_account_id');
    $april = f5DeclAddPeriod($fixture['calendar']['fiscal_year_id'], '2031-04');
    $aprilCalendar = [
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'accounting_period_id' => (int) $april->id,
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
    ];
    $other = f5DeclAccount();
    f5DeclPostEntry($aprilCalendar, '2031-04-10', 'F5-TVA-APR', [
        ['account_id' => $other->id, 'debit' => 50_000, 'credit' => 0],
        ['account_id' => $collected, 'debit' => 0, 'credit' => 50_000],
    ]);
    f5DeclLockPeriod((int) $april->id);

    $aprilDeclaration = app(GenerateTvaDeclaration::class)->handle(2031, 4, f5DeclActor($user));

    expect($aprilDeclaration->amount_declared)->toBe(39_250)
        ->and($credit->refresh()->consumed_in_declaration_id)->toBe($aprilDeclaration->id);

    // §7.4: the unfiled March declaration is a WARNING on April's run.
    expect((string) $aprilDeclaration->notes)->toContain('NOT FILED');
});

it('re-verifies the inputs_hash at filing and fails when the ledger changed underneath', function () {
    $fixture = f5DeclTvaFixture();
    $user = f5DeclUser('tax.declare', 'tax.file');

    $declaration = app(GenerateTvaDeclaration::class)->handle(2031, 3, f5DeclActor($user));

    // The ledger moves AFTER generation: another output entry lands in the
    // (still soft-locked, year-end-writable) period.
    $collected = (int) DB::table('tax_codes')->whereNotNull('collected_account_id')->value('collected_account_id');
    $other = f5DeclAccount();
    f5DeclPostEntry($fixture['calendar'], '2031-03-20', 'F5-TVA-LATE', [
        ['account_id' => $other->id, 'debit' => 1_000, 'credit' => 0],
        ['account_id' => $collected, 'debit' => 0, 'credit' => 1_000],
    ]);

    expect(fn () => app(FileTaxDeclaration::class)->handle($declaration->id, 'impots_cm', 'DGI-2031-777', f5DeclActor($user)))
        ->toThrow(DomainException::class, 'inputs_hash mismatch');
});

it('files a generated declaration with a mandatory acknowledgement', function () {
    f5DeclTvaFixture();
    $user = f5DeclUser('tax.declare', 'tax.file');

    $declaration = app(GenerateTvaDeclaration::class)->handle(2031, 3, f5DeclActor($user));

    // The DGI acknowledgement is not optional.
    expect(fn () => app(FileTaxDeclaration::class)->handle($declaration->id, 'impots_cm', '   ', f5DeclActor($user)))
        ->toThrow(DomainException::class, 'external_reference');

    $filed = app(FileTaxDeclaration::class)->handle($declaration->id, 'impots_cm', 'DGI-2031-000123', f5DeclActor($user));

    expect($filed->status)->toBe(DeclarationStatus::Filed)
        ->and($filed->filed_at)->not->toBeNull()
        ->and($filed->external_reference)->toBe('DGI-2031-000123');
});

it('cannot be filed while the type is not mapped to the official form', function () {
    // §7.1: form box codes ship EMPTY (NEEDS VERIFICATION) - internal
    // codes generate and review fine, but never file.
    f5DeclConfirmedIdentity();
    f5DeclType('tva_monthly', mapped: false);
    $calendar = f5DeclCalendar('2031-03-15');
    $collected = f5DeclAccount();
    $other = f5DeclAccount();
    f5DeclTaxCode($collected->id, f5DeclAccount()->id);
    f5DeclPostEntry($calendar, '2031-03-10', 'F5-TVA-UM', [
        ['account_id' => $other->id, 'debit' => 5_000, 'credit' => 0],
        ['account_id' => $collected->id, 'debit' => 0, 'credit' => 5_000],
    ]);
    f5DeclLockPeriod($calendar['accounting_period_id']);
    $user = f5DeclUser('tax.declare', 'tax.file');

    $declaration = app(GenerateTvaDeclaration::class)->handle(2031, 3, f5DeclActor($user));

    expect(fn () => app(FileTaxDeclaration::class)->handle($declaration->id, 'impots_cm', 'DGI-REF', f5DeclActor($user)))
        ->toThrow(DomainException::class, 'not yet mapped to the official DGI form');
});

it('amends a filed declaration from the current ledger and flips the original when the amendment files', function () {
    $fixture = f5DeclTvaFixture();
    $user = f5DeclUser('tax.declare', 'tax.file');

    $original = app(GenerateTvaDeclaration::class)->handle(2031, 3, f5DeclActor($user));
    app(FileTaxDeclaration::class)->handle($original->id, 'impots_cm', 'DGI-ORIG', f5DeclActor($user));

    // A correction lands in the period after filing.
    $collected = (int) DB::table('tax_codes')->whereNotNull('collected_account_id')->value('collected_account_id');
    $other = f5DeclAccount();
    f5DeclPostEntry($fixture['calendar'], '2031-03-25', 'F5-TVA-CORR', [
        ['account_id' => $other->id, 'debit' => 2_000, 'credit' => 0],
        ['account_id' => $collected, 'debit' => 0, 'credit' => 2_000],
    ]);

    $amendment = app(AmendTaxDeclaration::class)->handle($original->id, 'Late sales invoice found', f5DeclActor($user));

    expect($amendment->amends_declaration_id)->toBe($original->id)
        ->and($amendment->amount_declared)->toBe(14_250 + 2_000)
        ->and($original->refresh()->status)->toBe(DeclarationStatus::Filed);

    app(FileTaxDeclaration::class)->handle($amendment->id, 'impots_cm', 'DGI-AMND', f5DeclActor($user));

    expect($original->refresh()->status)->toBe(DeclarationStatus::Amended)
        ->and(TaxDeclaration::query()->where('amends_declaration_id', $original->id)->count())->toBe(1);
});

it('never deletes a declaration past draft', function () {
    f5DeclTvaFixture();
    $user = f5DeclUser('tax.declare');

    $declaration = app(GenerateTvaDeclaration::class)->handle(2031, 3, f5DeclActor($user));

    expect(fn () => $declaration->delete())
        ->toThrow(RuntimeException::class, 'never deleted');
});

<?php

declare(strict_types=1);

use App\Modules\Fees\Actions\IssueInvoice;
use App\Modules\Fees\Actions\PrintInvoice;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md §10.2 (phase-12-13 D3) - the Fee Invoice: the
 * snapshot pattern (Invoice IS the immutable source), the balance-due figure
 * as of today, and the refusal to print a draft that has no invoice_no yet.
 */
beforeEach(function (): void {
    p13moneyDocumentProfile();
    p13moneyConfirmedFiscalIdentity();
});

it('prints the issued invoice with its invoice_no, lines, grand total and amount in words', function (): void {
    $bursar = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveInvoiceIssuedRule($bursar);

    $fixture = p13moneyInvoiceFixture($cal);
    $invoice = p13moneyDraftInvoice($fixture, $bursar);
    [$issued] = app(IssueInvoice::class)->handle([$invoice->id], $bursar->toAuditActor());

    $rendered = app(PrintInvoice::class)->handle($issued->id);

    expect($rendered->serial)->toBeNull(); // receipt pattern: invoice_no is Fees' own number
    expect($rendered->html)->toContain((string) $issued->invoice_no);
    expect($rendered->html)->toContain('Tuition Fee');
    expect($rendered->html)->toContain('Development Fee');
    expect($rendered->html)->toContain('three hundred fifty thousand');
});

it('refuses to print a draft invoice that has not been issued yet', function (): void {
    $bursar = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');

    $fixture = p13moneyInvoiceFixture($cal);
    $invoice = p13moneyDraftInvoice($fixture, $bursar);

    expect(fn () => app(PrintInvoice::class)->handle($invoice->id))
        ->toThrow(DomainException::class, 'has not been issued yet');
});

it('a reprint of the same invoice is a DUPLICATA', function (): void {
    $bursar = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveInvoiceIssuedRule($bursar);

    $fixture = p13moneyInvoiceFixture($cal);
    $invoice = p13moneyDraftInvoice($fixture, $bursar);
    [$issued] = app(IssueInvoice::class)->handle([$invoice->id], $bursar->toAuditActor());

    $first = app(PrintInvoice::class)->handle($issued->id);
    expect($first->isDuplicate)->toBeFalse();

    $second = app(PrintInvoice::class)->handle($issued->id);
    expect($second->isDuplicate)->toBeTrue();
    expect($second->html)->toContain('DUPLICATA');
});

it('refuses to print any invoice while the fiscal identity is incomplete', function (): void {
    $bursar = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveInvoiceIssuedRule($bursar);

    $fixture = p13moneyInvoiceFixture($cal);
    $invoice = p13moneyDraftInvoice($fixture, $bursar);
    [$issued] = app(IssueInvoice::class)->handle([$invoice->id], $bursar->toAuditActor());

    p13moneyConfirmedFiscalIdentity(['legal_name' => null]);

    expect(fn () => app(PrintInvoice::class)->handle($issued->id))
        ->toThrow(DomainException::class, 'fiscal identity is incomplete');
});

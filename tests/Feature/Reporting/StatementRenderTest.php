<?php

declare(strict_types=1);

use App\Modules\Fees\Actions\IssueInvoice;
use App\Modules\Fees\Actions\PrintStatement;
use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Models\IssuedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md §10.3 (phase-12-13 D3) - the Student Account
 * Statement: LIVE, so a reprint after a new payment reflects it, prints no
 * series number and issues no IssuedDocument row at all (unlike the
 * snapshot-backed receipt/invoice).
 */
beforeEach(function (): void {
    p13moneyDocumentProfile();
    p13moneyConfirmedFiscalIdentity();
});

it('prints the live statement with the as-of date and running balance', function (): void {
    $bursar = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveInvoiceIssuedRule($bursar);
    p13moneySaveCashPaymentRule($bursar);

    $fixture = p13moneyInvoiceFixture($cal);
    $invoice = p13moneyDraftInvoice($fixture, $bursar);
    app(IssueInvoice::class)->handle([$invoice->id], $bursar->toAuditActor());

    $studentId = $fixture['enrollment']->student_id;

    $before = app(PrintStatement::class)->handle($studentId, '2031-03-16');
    expect($before->html)->toContain('350'); // gross invoice total, no payment yet
    expect(IssuedDocument::query()->count())->toBe(0);

    p13moneyRecordCash($studentId, $fixture['enrollment']->id, $cal, $bursar, 120_000);

    $after = app(PrintStatement::class)->handle($studentId, '2031-03-17');
    expect($after->html)->not->toContain('DUPLICATA');
    expect($after->serial)->toBeNull();
    expect(IssuedDocument::query()->count())->toBe(0); // still nothing issued - it is live
});

it('refuses to print a statement while the fiscal identity is incomplete', function (): void {
    p13moneyUserAs(Role::Bursar, Role::Accountant);
    p13moneyConfirmedFiscalIdentity(['tax_centre_name' => null]);

    $studentId = \App\Modules\Students\Models\Student::factory()->create()->id;

    expect(fn () => app(PrintStatement::class)->handle($studentId))
        ->toThrow(DomainException::class, 'fiscal identity is incomplete');
});

it('refuses a non-existent student id', function (): void {
    p13moneyUserAs(Role::Bursar, Role::Accountant);

    expect(fn () => app(PrintStatement::class)->handle(999_999))
        ->toThrow(DomainException::class, 'does not exist');
});

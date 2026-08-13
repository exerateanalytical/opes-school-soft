<?php

declare(strict_types=1);

use App\Modules\Fees\Livewire\Cashier;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13moneyDocumentProfile();
    p13moneyConfirmedFiscalIdentity();
});

it('offers both the A5 and the thermal receipt after recording a payment', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveCashPaymentRule($cashier);

    $payment = p13moneyRecordCash(\App\Modules\Students\Models\Student::factory()->create()->id, null, $cal, $cashier, 10_000);

    Livewire::actingAs($cashier)
        ->test(Cashier::class)
        ->set('lastPaymentId', $payment->id)
        ->set('receiptNo', $payment->receipt_no)
        ->assertSeeHtml(route('fees.payments.receipt', ['payment' => $payment->id]))
        ->assertSeeHtml(route('fees.payments.receipt', ['payment' => $payment->id, 'variant' => 'pos']));
});

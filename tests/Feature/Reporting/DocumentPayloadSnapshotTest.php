<?php

declare(strict_types=1);

use App\Modules\Fees\Actions\PrintReceipt;
use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13moneyDocumentProfile();
    p13moneyConfirmedFiscalIdentity();
});

it('reprints the name recorded at issue, even after the student is later renamed', function (): void {
    $cashier = p13moneyUserAs(Role::Bursar, Role::Accountant);
    $cal = ledgerCalendar('2031-03-15');
    p13moneySaveCashPaymentRule($cashier);

    $studentId = Student::factory()->create(['first_name' => 'Carine', 'last_name' => 'Ndongo'])->id;
    $payment = p13moneyRecordCash($studentId, null, $cal, $cashier, 25_000);

    $original = app(PrintReceipt::class)->handle($payment->id);
    expect($original->html)->toContain('Carine Ndongo');

    // A legitimate later correction - e.g. a misspelled name fixed at the
    // front desk - must not break the ability to reprint what was already
    // issued.
    DB::table('students')->where('id', $studentId)->update(['first_name' => 'Karine']);

    $reprint = app(PrintReceipt::class)->handle($payment->id);
    expect($reprint->html)->toContain('Carine Ndongo');
    expect($reprint->html)->not->toContain('Karine Ndongo');
    expect($reprint->isDuplicate)->toBeTrue();
});

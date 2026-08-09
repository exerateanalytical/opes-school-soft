<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Fees\Models\Invoice;
use App\Modules\Fees\Models\Payment;
use App\Modules\Identity\Domain\Permission;
use Database\Factories\InvoiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

require_once __DIR__.'/ApiTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Read-only v1 fees surface (docs/plans/phase-12-13.md 12.4): invoices and
 * payments behind fee.view - the same permission the /finance screens carry.
 */

it('lists invoices with a derived gross total', function () {
    /** @var Invoice $invoice */
    $invoice = InvoiceFactory::new()->create();
    $revenueAccountId = ChartOfAccount::factory()->create()->id;

    foreach ([[1, 50_000, 0], [2, 25_000, 1_000]] as [$lineNo, $amount, $tax]) {
        DB::table('invoice_lines')->insert([
            'invoice_id' => $invoice->id,
            'line_no' => $lineNo,
            'description' => 'Tuition Fee',
            'collection_basis' => 'own_revenue',
            'revenue_account_id' => $revenueAccountId,
            'recognition_method' => 'on_issue',
            'quantity' => 1,
            'unit_amount' => $amount,
            'amount' => $amount,
            'tax_amount' => $tax,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $user = p12apiUserWithPermissions(Permission::FeeView);
    $headers = p12apiBearerHeaders($user, [Permission::FeeView->value]);

    $response = getJson('/api/v1/invoices', $headers)->assertStatus(200);

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($invoice->id);
    // A1: totals are derived from the lines, never stored.
    expect($response->json('data.0.gross_total'))->toBe(76_000);
    expect($response->json('data.0.currency'))->toBe('XAF');
});

it('filters invoices by student and shows one invoice', function () {
    /** @var Invoice $mine */
    $mine = InvoiceFactory::new()->create();
    InvoiceFactory::new()->create();

    $user = p12apiUserWithPermissions(Permission::FeeView);
    $headers = p12apiBearerHeaders($user, [Permission::FeeView->value]);

    $list = getJson('/api/v1/invoices?student_id='.$mine->student_id, $headers)->assertStatus(200);
    expect($list->json('meta.total'))->toBe(1);

    $show = getJson('/api/v1/invoices/'.$mine->id, $headers)->assertStatus(200);
    expect($show->json('data.id'))->toBe($mine->id);
    expect($show->json('data.status'))->toBe('draft');
});

it('lists and shows payments with integer XAF amounts', function () {
    /** @var Payment $payment */
    $payment = Payment::factory()->create(['amount' => 150_000, 'unallocated_amount' => 150_000]);

    $user = p12apiUserWithPermissions(Permission::FeeView);
    $headers = p12apiBearerHeaders($user, [Permission::FeeView->value]);

    $list = getJson('/api/v1/payments', $headers)->assertStatus(200);
    expect($list->json('meta.total'))->toBe(1);
    expect($list->json('data.0.amount'))->toBe(150_000);
    expect($list->json('data.0.receipt_no'))->toBe($payment->receipt_no);

    $show = getJson('/api/v1/payments/'.$payment->id, $headers)->assertStatus(200);
    expect($show->json('data.payment_method'))->toBe('cash');
    expect($show->json('data.unallocated_amount'))->toBe(150_000);
});

it('filters payments by student', function () {
    /** @var Payment $mine */
    $mine = Payment::factory()->create();
    Payment::factory()->create();

    $user = p12apiUserWithPermissions(Permission::FeeView);
    $headers = p12apiBearerHeaders($user, [Permission::FeeView->value]);

    $response = getJson('/api/v1/payments?student_id='.$mine->student_id, $headers)
        ->assertStatus(200);

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.id'))->toBe($mine->id);
});

it('denies the fees endpoints to a fee.view user whose token was scoped to students only', function () {
    $user = p12apiUserWithPermissions(Permission::FeeView, Permission::StudentsView);
    $headers = p12apiBearerHeaders($user, [Permission::StudentsView->value]);

    getJson('/api/v1/invoices', $headers)->assertStatus(403);
    getJson('/api/v1/payments', $headers)->assertStatus(403);
});

it('denies the fees endpoints to a user without fee.view regardless of token scope', function () {
    $user = p12apiUserWithPermissions(Permission::StudentsView);
    $headers = p12apiBearerHeaders($user, [Permission::FeeView->value]);

    getJson('/api/v1/invoices', $headers)->assertStatus(403);
});

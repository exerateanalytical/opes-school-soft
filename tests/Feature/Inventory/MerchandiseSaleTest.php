<?php

declare(strict_types=1);

use App\Modules\Accounting\Actions\SavePostingRule;
use App\Modules\Accounting\Domain\AccountSource;
use App\Modules\Accounting\Domain\LineSign;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\SellMerchandise;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Accounting/AccountingTestHelpers.php';
require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

if (! function_exists('phase9StockInvoiceRule')) {
    /** The §11.2 fee.invoice.issued rule: Dr 4111 gross w/ student partner, Cr per-line revenue. */
    function phase9StockInvoiceRule(User $configurer): void
    {
        app(SavePostingRule::class)->handle([
            'code' => 'p9s_fee_inv_'.Str::lower(Str::random(4)),
            'event' => PostingEvent::FeeInvoiceIssued->value,
            'journal_id' => Journal::factory()->create()->id,
            'label_expression' => 'Facture {invoice.reference}',
            'condition_expression' => null,
            'priority' => 100,
            'is_active' => true,
            'effective_from' => '2030-01-01',
            'effective_to' => null,
        ], [
            [
                'sequence' => 1,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'invoice.receivable_account_id',
                'sign' => LineSign::Debit,
                'amount_expression' => 'invoice.total',
                'partner_source' => 'invoice.partner',
                'label_expression' => 'Client - {invoice.reference}',
            ],
            [
                'sequence' => 2,
                'account_source' => AccountSource::PayloadPath,
                'account_path' => 'item.revenue_account_id',
                'iterates_over' => 'invoice.lines',
                'sign' => LineSign::Credit,
                'amount_expression' => 'item.amount',
                'label_expression' => '{item.label}',
            ],
        ], $configurer->toAuditActor());
    }
}

if (! function_exists('phase9StockMerchandiseBaseline')) {
    /**
     * The §8.5 standing start: a Uniforms jumper (601/31/6031/701) priced
     * 15,000 HT with 200 units on hand at value 1,840,000 (derived 9,200)
     * in a sellable point, plus an enrollment to sell to.
     *
     * @return array{user: User, item: Item, location: int, enrollment: Enrollment, calendar: array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}}
     */
    function phase9StockMerchandiseBaseline(): array
    {
        $user = phase9StockUser();
        phase9StockReceiptRule($user);
        phase9StockSoldRule($user);
        phase9StockInvoiceRule($user);
        $calendar = ledgerCalendar('2031-03-15');

        $item = phase9StockItem([
            'name' => 'School Jumper',
            'item_type' => 'merchandise',
            'standard_sale_price' => 15_000,
            'item_category_id' => phase9StockCategoryId([
                'name' => 'Uniforms',
                'purchase_account_id' => phase9StockAccountId('601'),
                'stock_account_id' => phase9StockAccountId('31'),
                'variation_account_id' => phase9StockAccountId('6031'),
                'sales_account_id' => phase9StockAccountId('701'),
            ]),
        ]);
        $location = phase9StockLocationId(['is_sellable_point' => true, 'name' => 'Bookshop']);

        phase9StockReceive($user, $item, $location, '200.000', 1_840_000, $calendar);

        /** @var Enrollment $enrollment */
        $enrollment = Enrollment::factory()->create([
            'academic_year_id' => $calendar['academic_year_id'],
        ]);

        return [
            'user' => $user,
            'item' => $item,
            'location' => $location,
            'enrollment' => $enrollment,
            'calendar' => $calendar,
        ];
    }
}

it('sells one jumper on credit: Fees invoice Dr 4111/Cr 701 for 15,000 AND cost leg Dr 6031/Cr 31 for 9,200 (§8.5)', function (): void {
    $fixture = phase9StockMerchandiseBaseline();

    $result = app(SellMerchandise::class)->handle([
        'item_id' => (int) $fixture['item']->getKey(),
        'store_location_id' => $fixture['location'],
        'quantity' => 1,
        'payment' => 'credit',
        'enrollment_id' => (int) $fixture['enrollment']->getKey(),
        'sold_on' => '2031-03-18',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
    ], phase9StockActor($fixture['user']));

    expect($result['revenue'])->toBe(15_000)
        ->and($result['cost_of_sales'])->toBe(9_200)
        ->and($result['invoice_no'])->not->toBeNull()
        ->and($result['revenue_entry_id'])->not->toBeNull();

    // REVENUE leg - it is 04-fees' Invoice, the student's SINGLE debt
    // stream (§10.7): Dr 4111 with the student partner / Cr 701.
    $revenueLines = JournalEntryLine::query()
        ->where('journal_entry_id', $result['revenue_entry_id'])
        ->orderBy('sequence')
        ->get();
    $code = fn (JournalEntryLine $l): string => (string) DB::table('chart_of_accounts')->where('id', $l->account_id)->value('code');

    expect($code($revenueLines[0]))->toBe('4111')
        ->and($revenueLines[0]->debit)->toBe(15_000)
        ->and($revenueLines[0]->partner_type?->value)->toBe('student')
        ->and($revenueLines[0]->partner_id)->toBe($fixture['enrollment']->student_id)
        ->and($code($revenueLines[1]))->toBe('701')
        ->and($revenueLines[1]->credit)->toBe(15_000);

    // The invoice really is a Fees supplementary invoice on the enrollment.
    /** @var object{enrollment_id: int|string, type: string, status: string} $invoice */
    $invoice = DB::table('invoices')->where('id', $result['invoice_id'])->first(['enrollment_id', 'type', 'status']);
    expect((int) $invoice->enrollment_id)->toBe((int) $fixture['enrollment']->getKey())
        ->and($invoice->type)->toBe('supplementary')
        ->and($invoice->status)->toBe('issued');

    // COST-OF-SALES leg - a stock issue like any other: Dr 6031 / Cr 31 at
    // the derived 9,200; gross margin 5,800 is derived, never posted.
    $costLines = JournalEntryLine::query()
        ->where('journal_entry_id', $result['cost_entry_id'])
        ->orderBy('sequence')
        ->get();
    expect($code($costLines[0]))->toBe('6031')
        ->and($costLines[0]->debit)->toBe(9_200)
        ->and($code($costLines[1]))->toBe('31')
        ->and($costLines[1]->credit)->toBe(9_200);

    // Movement: sale, -1 at -9,200, referencing the Invoice.
    /** @var StockMovement $movement */
    $movement = StockMovement::query()->findOrFail($result['movement_id']);
    expect($movement->quantity)->toBe('-1.000')
        ->and($movement->total_cost)->toBe(-9_200)
        ->and($movement->reference_type)->toBe('Invoice')
        ->and($movement->reference_id)->toBe($result['invoice_id']);

    $balance = phase9StockBalance((int) $fixture['item']->getKey(), $fixture['location']);
    expect($balance->quantity_on_hand)->toBe('199.000')
        ->and($balance->value_on_hand)->toBe(1_830_800);
});

it('refuses CASH sales, naming the unverified 571x Caisse subdivision (V13)', function (): void {
    $fixture = phase9StockMerchandiseBaseline();

    app(SellMerchandise::class)->handle([
        'item_id' => (int) $fixture['item']->getKey(),
        'store_location_id' => $fixture['location'],
        'quantity' => 1,
        'payment' => 'cash',
        'sold_on' => '2031-03-18',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
    ], phase9StockActor($fixture['user']));
})->throws(DomainException::class, '571x');

it('refuses TAXED sales, naming the unverified 443x TVA facturee subdivision (V11)', function (): void {
    $fixture = phase9StockMerchandiseBaseline();

    $taxCodeId = (int) DB::table('tax_codes')->insertGetId([
        'code' => 'TVA19',
        'name' => 'TVA 19.25%',
        'name_fr' => 'TVA 19,25 %',
        'tax_type' => 'tva',
        'rate_bp' => 19_250,
        'direction' => 'output',
        'effective_from' => '2030-01-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $fixture['item']->forceFill(['sale_tax_code_id' => $taxCodeId])->save();

    app(SellMerchandise::class)->handle([
        'item_id' => (int) $fixture['item']->getKey(),
        'store_location_id' => $fixture['location'],
        'quantity' => 1,
        'payment' => 'credit',
        'enrollment_id' => (int) $fixture['enrollment']->getKey(),
        'sold_on' => '2031-03-18',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
    ], phase9StockActor($fixture['user']));
})->throws(DomainException::class, '443x');

it('refuses to sell a non-merchandise item (I3) and from a non-sellable point', function (): void {
    $fixture = phase9StockMerchandiseBaseline();

    $consumable = phase9StockItem();
    expect(fn (): array => app(SellMerchandise::class)->handle([
        'item_id' => (int) $consumable->getKey(),
        'store_location_id' => $fixture['location'],
        'quantity' => 1,
        'payment' => 'credit',
        'enrollment_id' => (int) $fixture['enrollment']->getKey(),
        'sold_on' => '2031-03-18',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
    ], phase9StockActor($fixture['user'])))->toThrow(DomainException::class, 'not merchandise');

    $backroom = phase9StockLocationId(['is_sellable_point' => false]);
    expect(fn (): array => app(SellMerchandise::class)->handle([
        'item_id' => (int) $fixture['item']->getKey(),
        'store_location_id' => $backroom,
        'quantity' => 1,
        'payment' => 'credit',
        'enrollment_id' => (int) $fixture['enrollment']->getKey(),
        'sold_on' => '2031-03-18',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
    ], phase9StockActor($fixture['user'])))->toThrow(DomainException::class, 'not a sellable point');
});

it('is idempotent: the same idempotency_key sells exactly once', function (): void {
    $fixture = phase9StockMerchandiseBaseline();

    $data = [
        'item_id' => (int) $fixture['item']->getKey(),
        'store_location_id' => $fixture['location'],
        'quantity' => 2,
        'payment' => 'credit',
        'enrollment_id' => (int) $fixture['enrollment']->getKey(),
        'sold_on' => '2031-03-18',
        'fiscal_year_id' => $fixture['calendar']['fiscal_year_id'],
        'academic_year_id' => $fixture['calendar']['academic_year_id'],
        'idempotency_key' => 'p9s-sale-'.Str::random(8),
    ];

    $first = app(SellMerchandise::class)->handle($data, phase9StockActor($fixture['user']));
    $second = app(SellMerchandise::class)->handle($data, phase9StockActor($fixture['user']));

    expect($second['movement_id'])->toBe($first['movement_id'])
        ->and($second['invoice_id'])->toBe($first['invoice_id'])
        ->and($second['revenue'])->toBe(30_000)
        ->and(StockMovement::query()->where('movement_type', 'sale')->count())->toBe(1)
        ->and(DB::table('invoices')->count())->toBe(1);

    $balance = phase9StockBalance((int) $fixture['item']->getKey(), $fixture['location']);
    expect($balance->quantity_on_hand)->toBe('198.000');
});

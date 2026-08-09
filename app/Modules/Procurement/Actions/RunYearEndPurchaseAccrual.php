<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Procurement\Domain\LineAmount;
use App\Modules\Procurement\Models\PurchaseAccrual;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §3.3 - the year-end cut-off run
 * (02-accounting C8): every confirmed goods-receipt line with accepted
 * quantity NOT yet invoiced at the closing date becomes a "facture non
 * parvenue", valued at PO price:
 *
 *   Dr 60x/61x/62x (the PO line's expense account)
 *       Cr 4818  Fournisseurs, factures non parvenues (partner = supplier)
 *
 * dated on the fiscal year's LAST day, and REVERSED on the FIRST day of
 * the next period - both through PostFromEvent (event
 * `goods.received_not_invoiced`; the reversal is the school-configured
 * mirror rule selected by a negative `document.total` sentinel). One entry
 * per supplier per direction, because the 4818 balancing line carries the
 * supplier partner (L8).
 *
 * Idempotent at the database: UNIQUE(fiscal_year_id, goods_receipt_line_id)
 * - a re-run accrues only lines the first run missed.
 *
 * Lines received against no PO line are SKIPPED and reported: §3.3 values
 * the accrual "at PO price" and inventing another price is exactly the
 * silent-guess 00-core §16 forbids.
 *
 * @phpstan-type AccrualRow array{goods_receipt_line_id: int, supplier_id: int, quantity_millis: int, amount_ht: int, expense_account_id: int, description: string}
 */
final class RunYearEndPurchaseAccrual
{
    public function __construct(
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @return array{accruals: list<PurchaseAccrual>, skipped_without_po: int}
     */
    public function handle(int $fiscalYearId, Actor $actor): array
    {
        Gate::authorize(Permission::LedgerPost->value);

        return DB::transaction(function () use ($fiscalYearId, $actor): array {
            $fiscalYear = DB::table('fiscal_years')->where('id', $fiscalYearId)->first(['id', 'ends_on', 'status']);

            if ($fiscalYear === null) {
                throw new DomainException("Fiscal year {$fiscalYearId} does not exist.");
            }

            $closingDate = Carbon::parse((string) $fiscalYear->ends_on)->toDateString();
            $reversalDate = Carbon::parse($closingDate)->addDay()->toDateString();

            $accrualAccountId = $this->accrualAccountId();

            [$rows, $skipped] = $this->inputSet($fiscalYearId, $closingDate);

            if ($rows === []) {
                return ['accruals' => [], 'skipped_without_po' => $skipped];
            }

            /** @var array<int, list<AccrualRow>> $bySupplier */
            $bySupplier = [];

            foreach ($rows as $row) {
                $bySupplier[$row['supplier_id']][] = $row;
            }

            $created = [];

            foreach ($bySupplier as $supplierId => $supplierRows) {
                $total = 0;
                $legs = [];

                foreach ($supplierRows as $row) {
                    $total += $row['amount_ht'];
                    $legs[] = [
                        'amount' => $row['amount_ht'],
                        'expense_account_id' => $row['expense_account_id'],
                        'label' => 'FNP '.$row['description'],
                    ];
                }

                $payload = [
                    'reference' => sprintf('FNP/%s', Carbon::parse($closingDate)->format('Y')),
                    'partner' => ['type' => 'supplier', 'id' => $supplierId],
                    'payable_account_id' => $accrualAccountId,
                ];

                $accrualEntry = $this->post->handle(
                    PostingEvent::GoodsReceivedNotInvoiced->value,
                    ['document' => $payload + ['total' => $total, 'lines' => $legs]],
                    $closingDate,
                    $actor,
                    $payload['reference'],
                );

                // §3.3: reversed on the FIRST DAY of the next period - the
                // negative sentinel selects the mirror rule; the legs flip
                // sign so expense is credited and 4818 debited.
                $reversalEntry = $this->post->handle(
                    PostingEvent::GoodsReceivedNotInvoiced->value,
                    ['document' => $payload + [
                        'total' => -$total,
                        'lines' => array_map(
                            static fn (array $leg): array => ['amount' => -$leg['amount']] + $leg,
                            $legs,
                        ),
                    ]],
                    $reversalDate,
                    $actor,
                    $payload['reference'],
                );

                foreach ($supplierRows as $row) {
                    /** @var PurchaseAccrual $accrual */
                    $accrual = PurchaseAccrual::query()->create([
                        'fiscal_year_id' => $fiscalYearId,
                        'goods_receipt_line_id' => $row['goods_receipt_line_id'],
                        'supplier_id' => $supplierId,
                        'quantity' => sprintf('%d.%03d', intdiv($row['quantity_millis'], 1000), $row['quantity_millis'] % 1000),
                        'amount_ht' => $row['amount_ht'],
                        'expense_account_id' => $row['expense_account_id'],
                        'accrual_account_id' => $accrualAccountId,
                        'journal_entry_id' => (int) $accrualEntry->getKey(),
                        'reversal_journal_entry_id' => (int) $reversalEntry->getKey(),
                        'created_by' => $actor->id,
                    ]);

                    $created[] = $accrual;
                }
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Procurement',
                auditableType: PurchaseAccrual::class,
                auditableId: (int) ($created[0]->getKey()),
                after: [
                    'fiscal_year_id' => $fiscalYearId,
                    'closing_date' => $closingDate,
                    'lines_accrued' => count($created),
                    'skipped_without_po' => $skipped,
                ],
                actor: $actor,
            );

            return ['accruals' => $created, 'skipped_without_po' => $skipped];
        });
    }

    /**
     * The §3.3 input set: accepted-not-invoiced receipt quantities at the
     * closing date, valued proportionally on the PO line's HT amount -
     * skipping lines already accrued for this fiscal year (the UNIQUE key
     * makes the skip a fact, not a guess).
     *
     * @return array{0: list<AccrualRow>, 1: int}
     */
    private function inputSet(int $fiscalYearId, string $closingDate): array
    {
        $lines = DB::table('goods_receipt_lines as grl')
            ->join('goods_receipts as gr', 'gr.id', '=', 'grl.goods_receipt_id')
            ->where('gr.status', 'confirmed')
            ->whereDate('gr.received_on', '<=', $closingDate)
            ->whereNotExists(function ($query) use ($fiscalYearId): void {
                $query->select(DB::raw(1))
                    ->from('purchase_accruals as pa')
                    ->whereColumn('pa.goods_receipt_line_id', 'grl.id')
                    ->where('pa.fiscal_year_id', $fiscalYearId);
            })
            ->orderBy('grl.id')
            ->get([
                'grl.id', 'grl.purchase_order_line_id', 'grl.qty_accepted', 'grl.description',
                'gr.supplier_id',
            ]);

        $rows = [];
        $skipped = 0;

        foreach ($lines as $line) {
            if ($line->purchase_order_line_id === null) {
                $skipped++;

                continue;
            }

            $poLine = DB::table('purchase_order_lines')
                ->where('id', $line->purchase_order_line_id)
                ->first(['quantity', 'unit_price_ht', 'discount_rate_bp', 'amount_ht', 'expense_account_id']);

            if ($poLine === null) {
                $skipped++;

                continue;
            }

            $acceptedMillis = LineAmount::toMillis((string) $line->qty_accepted);
            $invoicedMillis = $this->invoicedMillis((int) $line->id, $closingDate);
            $openMillis = $acceptedMillis - $invoicedMillis;

            if ($openMillis <= 0) {
                continue;
            }

            $amount = LineAmount::compute(
                sprintf('%d.%03d', intdiv($openMillis, 1000), $openMillis % 1000),
                (int) $poLine->unit_price_ht,
                (int) $poLine->discount_rate_bp,
            );

            if ($amount <= 0) {
                continue;
            }

            $rows[] = [
                'goods_receipt_line_id' => (int) $line->id,
                'supplier_id' => (int) $line->supplier_id,
                'quantity_millis' => $openMillis,
                'amount_ht' => $amount,
                'expense_account_id' => (int) $poLine->expense_account_id,
                'description' => (string) $line->description,
            ];
        }

        return [$rows, $skipped];
    }

    /**
     * Quantity already invoiced against the receipt line by live (non-
     * cancelled) supplier invoices dated on or before the closing date.
     */
    private function invoicedMillis(int $goodsReceiptLineId, string $closingDate): int
    {
        $quantities = DB::table('supplier_invoice_lines as sil')
            ->join('supplier_invoices as si', 'si.id', '=', 'sil.supplier_invoice_id')
            ->where('sil.goods_receipt_line_id', $goodsReceiptLineId)
            ->where('si.status', '<>', 'cancelled')
            ->whereDate('si.invoice_date', '<=', $closingDate)
            ->pluck('sil.quantity');

        $total = 0;

        foreach ($quantities as $quantity) {
            $total += LineAmount::toMillis((string) $quantity);
        }

        return $total;
    }

    /**
     * The seeded 4818 "factures non parvenues" account - refusing loudly
     * when absent, never guessing (00-core §16).
     */
    private function accrualAccountId(): int
    {
        $id = DB::table('chart_of_accounts')
            ->where('code', '4818')
            ->where('is_postable', true)
            ->value('id');

        if ($id === null) {
            throw new DomainException(
                'No postable 4818 (factures non parvenues) account exists in the chart; the cut-off run cannot post (03-tax-procurement 3.3).'
            );
        }

        return (int) $id;
    }
}

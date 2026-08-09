<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Procurement\Domain\LineAmount;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §4.9 - the receipt-not-invoiced report:
 * the 4818 working paper, live. Every confirmed goods-receipt line whose
 * accepted quantity exceeds what supplier invoices (non-cancelled, dated
 * on or before `as_of`) have claimed against it, valued at PO price -
 * exactly the input set `RunYearEndPurchaseAccrual` accrues at year end.
 */
final class ReceiptNotInvoiced
{
    /**
     * @return array{as_of: string, total: int, rows: list<object{goods_receipt_line_id: int, receipt_no: string, received_on: string, supplier_id: int, supplier_name: string, description: string, open_quantity: string, value: int, has_po_price: bool}&\stdClass>}
     */
    public function handle(?string $asOf = null): array
    {
        Gate::authorize(ProcurementPermission::VIEW);

        $asOf ??= BusinessDate::today();

        $lines = DB::table('goods_receipt_lines as grl')
            ->join('goods_receipts as gr', 'gr.id', '=', 'grl.goods_receipt_id')
            ->join('suppliers as s', 's.id', '=', 'gr.supplier_id')
            ->where('gr.status', 'confirmed')
            ->whereDate('gr.received_on', '<=', $asOf)
            ->orderBy('grl.id')
            ->get([
                'grl.id', 'grl.purchase_order_line_id', 'grl.qty_accepted', 'grl.description',
                'gr.receipt_no', 'gr.received_on', 'gr.supplier_id',
                's.name as supplier_name',
            ]);

        $rows = [];
        $total = 0;

        foreach ($lines as $line) {
            $acceptedMillis = LineAmount::toMillis((string) $line->qty_accepted);
            $invoicedMillis = $this->invoicedMillis((int) $line->id, $asOf);
            $openMillis = $acceptedMillis - $invoicedMillis;

            if ($openMillis <= 0) {
                continue;
            }

            $value = 0;
            $hasPoPrice = false;

            if ($line->purchase_order_line_id !== null) {
                $poLine = DB::table('purchase_order_lines')
                    ->where('id', $line->purchase_order_line_id)
                    ->first(['unit_price_ht', 'discount_rate_bp']);

                if ($poLine !== null) {
                    $hasPoPrice = true;
                    $value = LineAmount::compute(
                        sprintf('%d.%03d', intdiv($openMillis, 1000), $openMillis % 1000),
                        (int) $poLine->unit_price_ht,
                        (int) $poLine->discount_rate_bp,
                    );
                }
            }

            $rows[] = (object) [
                'goods_receipt_line_id' => (int) $line->id,
                'receipt_no' => (string) $line->receipt_no,
                'received_on' => (string) $line->received_on,
                'supplier_id' => (int) $line->supplier_id,
                'supplier_name' => (string) $line->supplier_name,
                'description' => (string) $line->description,
                'open_quantity' => sprintf('%d.%03d', intdiv($openMillis, 1000), $openMillis % 1000),
                'value' => $value,
                'has_po_price' => $hasPoPrice,
            ];
            $total += $value;
        }

        return ['as_of' => $asOf, 'total' => $total, 'rows' => $rows];
    }

    private function invoicedMillis(int $goodsReceiptLineId, string $asOf): int
    {
        $quantities = DB::table('supplier_invoice_lines as sil')
            ->join('supplier_invoices as si', 'si.id', '=', 'sil.supplier_invoice_id')
            ->where('sil.goods_receipt_line_id', $goodsReceiptLineId)
            ->where('si.status', '<>', 'cancelled')
            ->whereDate('si.invoice_date', '<=', $asOf)
            ->pluck('sil.quantity');

        $total = 0;

        foreach ($quantities as $quantity) {
            $total += LineAmount::toMillis((string) $quantity);
        }

        return $total;
    }
}

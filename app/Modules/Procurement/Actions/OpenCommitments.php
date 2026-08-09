<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Procurement\Domain\LineAmount;
use App\Modules\Procurement\Domain\ProcurementPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §4.9 - open commitments: approved (or
 * further-advanced) purchase orders not yet fully invoiced, valued at the
 * PO line price for the uninvoiced quantity. Draft, cancelled and closed
 * orders commit nothing.
 */
final class OpenCommitments
{
    private const OPEN_STATUSES = ['approved', 'sent', 'partially_received', 'received', 'partially_invoiced'];

    /**
     * @return array{total: int, rows: list<object{purchase_order_id: int, po_no: string, supplier_id: int, supplier_name: string, order_date: string, open_value: int}&\stdClass>}
     */
    public function handle(): array
    {
        Gate::authorize(ProcurementPermission::VIEW);

        $lines = DB::table('purchase_order_lines as pol')
            ->join('purchase_orders as po', 'po.id', '=', 'pol.purchase_order_id')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->whereIn('po.status', self::OPEN_STATUSES)
            ->whereColumn('pol.qty_invoiced', '<', 'pol.quantity')
            ->orderBy('po.id')
            ->get([
                'po.id as purchase_order_id', 'po.po_no', 'po.supplier_id', 'po.order_date',
                's.name as supplier_name',
                'pol.quantity', 'pol.qty_invoiced', 'pol.unit_price_ht', 'pol.discount_rate_bp',
            ]);

        /** @var array<int, array{purchase_order_id: int, po_no: string, supplier_id: int, supplier_name: string, order_date: string, open_value: int}> $byOrder */
        $byOrder = [];
        $total = 0;

        foreach ($lines as $line) {
            $openMillis = LineAmount::toMillis((string) $line->quantity) - LineAmount::toMillis((string) $line->qty_invoiced);

            if ($openMillis <= 0) {
                continue;
            }

            $value = LineAmount::compute(
                sprintf('%d.%03d', intdiv($openMillis, 1000), $openMillis % 1000),
                (int) $line->unit_price_ht,
                (int) $line->discount_rate_bp,
            );

            $orderId = (int) $line->purchase_order_id;

            $byOrder[$orderId] ??= [
                'purchase_order_id' => $orderId,
                'po_no' => (string) $line->po_no,
                'supplier_id' => (int) $line->supplier_id,
                'supplier_name' => (string) $line->supplier_name,
                'order_date' => (string) $line->order_date,
                'open_value' => 0,
            ];

            $byOrder[$orderId]['open_value'] += $value;
            $total += $value;
        }

        return [
            'total' => $total,
            'rows' => array_values(array_map(
                static fn (array $row): \stdClass => (object) $row,
                $byOrder,
            )),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Procurement\Domain\ProcurementPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §4.9 - duplicate-payment risk: pairs of
 * live (non-cancelled) supplier invoices on the SAME supplier for the SAME
 * total with invoice dates within N days of each other. The classic
 * payables fraud/fat-finger vector (§3.2's cousin at document level) -
 * the DB UNIQUE on (supplier_id, supplier_invoice_no) already blocks the
 * literal duplicate; this surfaces the re-keyed one.
 */
final class DuplicateRisk
{
    /**
     * @return list<object{supplier_id: int, supplier_name: string, total_ttc: int, first_invoice_id: int, first_internal_no: string, first_date: string, second_invoice_id: int, second_internal_no: string, second_date: string, days_apart: int}&\stdClass>
     */
    public function handle(int $withinDays = 7): array
    {
        Gate::authorize(ProcurementPermission::VIEW);

        $pairs = DB::table('supplier_invoices as a')
            ->join('supplier_invoices as b', function ($join): void {
                $join->on('a.supplier_id', '=', 'b.supplier_id')
                    ->on('a.total_ttc', '=', 'b.total_ttc')
                    ->on('a.id', '<', 'b.id');
            })
            ->join('suppliers as s', 's.id', '=', 'a.supplier_id')
            ->where('a.status', '<>', 'cancelled')
            ->where('b.status', '<>', 'cancelled')
            ->whereRaw('ABS(DATEDIFF(a.invoice_date, b.invoice_date)) <= ?', [$withinDays])
            ->orderBy('a.supplier_id')
            ->orderBy('a.id')
            ->get([
                'a.supplier_id', 's.name as supplier_name', 'a.total_ttc',
                'a.id as first_invoice_id', 'a.internal_no as first_internal_no', 'a.invoice_date as first_date',
                'b.id as second_invoice_id', 'b.internal_no as second_internal_no', 'b.invoice_date as second_date',
            ]);

        $rows = [];

        foreach ($pairs as $pair) {
            $rows[] = (object) [
                'supplier_id' => (int) $pair->supplier_id,
                'supplier_name' => (string) $pair->supplier_name,
                'total_ttc' => (int) $pair->total_ttc,
                'first_invoice_id' => (int) $pair->first_invoice_id,
                'first_internal_no' => (string) $pair->first_internal_no,
                'first_date' => (string) $pair->first_date,
                'second_invoice_id' => (int) $pair->second_invoice_id,
                'second_internal_no' => (string) $pair->second_internal_no,
                'second_date' => (string) $pair->second_date,
                'days_apart' => (int) abs((strtotime((string) $pair->first_date) - strtotime((string) $pair->second_date)) / 86_400),
            ];
        }

        return $rows;
    }
}

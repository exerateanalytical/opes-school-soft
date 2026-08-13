<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Support;

use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Procurement\Models\SupplierPayment;
use App\Support\Ledger\ResolvesLedgerSource;
use App\Support\Ledger\SourceReference;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Names the supplier invoice or supplier payment that caused a journal
 * entry.
 *
 * Two documents, one resolver: both belong to Procurement, so splitting
 * them into two classes would buy nothing. Each is queried once and merged
 * with `??=` so the first claim wins, matching the registry's semantics.
 *
 * PURCHASE ORDERS ARE DELIBERATELY ABSENT. A PO is a commitment document
 * and posts nothing to the ledger - 03-tax-procurement.md §4.2 invariant 6 -
 * so `purchase_orders` has no `journal_entry_id` column at all. Its
 * migration says as much: "so a developer reaching for a posting rule has
 * nowhere to put the stamp." Adding one here would query a column that does
 * not exist.
 *
 * Imports only this module's own models, which is what makes the reverse
 * lookup legal under the boundary rule.
 */
final readonly class ProcurementLedgerSource implements ResolvesLedgerSource
{
    private const INVOICE_ROUTE = 'procurement.invoices.show';

    private const PAYMENT_ROUTE = 'procurement.payments.show';

    public function forEntryIds(array $journalEntryIds): array
    {
        $resolved = [];

        if (RouteFacade::has(self::INVOICE_ROUTE)) {
            foreach (SupplierInvoice::query()->whereIn('journal_entry_id', $journalEntryIds)->get(['id', 'journal_entry_id']) as $row) {
                $resolved[(int) $row->journal_entry_id] ??= SourceReference::linked(
                    __('opes.accounting.review.source_supplier_invoice', ['id' => $row->id]),
                    route(self::INVOICE_ROUTE, ['invoice' => $row->id]),
                );
            }
        }

        if (RouteFacade::has(self::PAYMENT_ROUTE)) {
            foreach (SupplierPayment::query()->whereIn('journal_entry_id', $journalEntryIds)->get(['id', 'journal_entry_id']) as $row) {
                $resolved[(int) $row->journal_entry_id] ??= SourceReference::linked(
                    __('opes.accounting.review.source_supplier_payment', ['id' => $row->id]),
                    route(self::PAYMENT_ROUTE, ['payment' => $row->id]),
                );
            }
        }

        return $resolved;
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * docs/plans/phase-05.md Block D, 250024 - posting-event registration for
 * the payables package. INTENTIONALLY A NO-OP, kept as a documentation
 * migration per the plan's own instruction ("leave the file as a no-op
 * enum-doc migration if unused; renumbering is NOT allowed").
 *
 * Verified at build time: `PostingRule.event` is NOT a free string - it is
 * validated by `SavePostingRule` against the closed §11.2 catalogue enum
 * `App\Modules\Accounting\Domain\PostingEvent`, which ALREADY declares the
 * procurement/payables events this package posts through:
 *
 *   - `supplier.paid`                (SupplierPaid)        - the settlement
 *     entry Dr 401/481 gross / Cr treasury net / Cr 447 withholding /
 *     Dr 6317 fee, AND (as a second call with a negative `document.total`
 *     sentinel discriminating the school-configured rule) the §3.3
 *     retention reclass Dr payable / Cr 4817;
 *   - `withholding.retained`         (WithholdingRetained) - used by F3's
 *     on_invoice recognition;
 *   - `supplier.invoice.received`    (SupplierInvoiceReceived) - also
 *     carries the retention RELEASE (Dr 4817 / Cr 401), rule-discriminated
 *     the same way;
 *   - `goods.received_not_invoiced`  (GoodsReceivedNotInvoiced) - the
 *     year-end 4818 accrual and its first-day reversal.
 *
 * The plan's speculative names (`supplier.payment.made`,
 * `supplier.retention.withheld`, `purchase.accrual.recognised`, ...) map
 * onto that existing catalogue; adding enum cases would mean editing an
 * Accounting-owned file, which Phase 5 agents must not touch. Nothing
 * monetary is seeded here - posting rules remain school-configured data
 * (00-core §16).
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op: the event catalogue is code (see docblock), and no rate or
        // rule is ever seeded.
    }

    public function down(): void
    {
        // No-op.
    }
};

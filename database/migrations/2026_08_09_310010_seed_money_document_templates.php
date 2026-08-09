<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/plans/phase-12-13.md D3 - registers the money-document catalogue
 * (10-documents.md §10) and the official series it documents (§4.3, §15).
 *
 * WHY these templates carry series_code = NULL, unlike the p13core test
 * fixtures that set one: `Payment.receipt_no`, `Invoice.invoice_no`,
 * `SupplierPayment.payment_no` and `WithholdingAttestation.attestation_no`
 * were already allocated by their OWNING module's own SequenceAllocator key
 * (Fees' `receipt_no`/`invoice_no`, Procurement's payment sequence, Tax's
 * `ATT` sequence) - all built in earlier phases, before this documents
 * platform existed. Letting RenderDocument allocate a SECOND number under
 * `document.RCPT.*` for the very same receipt would be exactly the second
 * numbering path 00-core §12 forbids, and the two counters would drift
 * apart the moment either one skipped a beat. This is the "receipt
 * pattern" SnapshotSourceMap's docblock names: the immutable snapshot is
 * the append-only domain row itself, its OWN number is what prints, and
 * RenderDocument's job is strictly the print-log/duplicate/hash wrapper
 * around it (RenderDocument::isFinancial() recognises these template CODES
 * directly for that reason, since there is no series_code to key off).
 *
 * The RCPT/INV/CN/WHT series rows below are still seeded because the
 * catalogue in §4.3/§15 names them and a later direct consumer (a future
 * template that has NOT already got its own domain numbering) can adopt
 * them without a further migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('document_series')->insert([
            [
                'code' => 'RCPT',
                'format' => '{school}/{year}/RCPT/{serial:6}',
                'scope' => 'fiscal_year',
                'reset_policy' => 'per_fiscal_year',
                'padding' => 6,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'INV',
                'format' => '{school}/{year}/INV/{serial:6}',
                'scope' => 'fiscal_year',
                'reset_policy' => 'per_fiscal_year',
                'padding' => 6,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'CN',
                'format' => '{school}/{year}/CN/{serial:6}',
                'scope' => 'fiscal_year',
                'reset_policy' => 'per_fiscal_year',
                'padding' => 6,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'WHT',
                'format' => '{school}/{year}/WHT/{serial:6}',
                'scope' => 'fiscal_year',
                'reset_policy' => 'per_fiscal_year',
                'padding' => 6,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('document_templates')->insert([
            [
                'code' => 'FEE-RECEIPT',
                'name' => 'Fee Receipt',
                'name_fr' => 'Reçu de paiement',
                'module' => 'Fees',
                'paper_size' => 'A5',
                'orientation' => 'portrait',
                'duplex' => 'none',
                'series_code' => null,
                'is_snapshot_backed' => true,
                'snapshot_source' => null,
                'carries_qr' => false,
                'carries_barcode' => false,
                'state_header' => 'none',
                'signature_roles' => json_encode(['bursar'], JSON_THROW_ON_ERROR),
                'min_phase' => 'v1',
                'bulk_printable' => false,
                'blade_view' => 'documents.fees.receipt',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                // 10.1: "A5 portrait default; A4 and 80 mm thermal variants" -
                // the 80 mm thermal variant, same data contract, own template
                // row so its own paper size and IssuedDocument lineage never
                // collide with the A5 original's.
                'code' => 'FEE-RECEIPT-POS',
                'name' => 'Fee Receipt (80mm)',
                'name_fr' => 'Reçu de paiement (80mm)',
                'module' => 'Fees',
                'paper_size' => 'POS80',
                'orientation' => 'portrait',
                'duplex' => 'none',
                'series_code' => null,
                'is_snapshot_backed' => true,
                'snapshot_source' => null,
                'carries_qr' => false,
                'carries_barcode' => false,
                'state_header' => 'none',
                'signature_roles' => json_encode(['bursar'], JSON_THROW_ON_ERROR),
                'min_phase' => 'v1',
                'bulk_printable' => false,
                'blade_view' => 'documents.fees.receipt',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'FEE-INVOICE',
                'name' => 'Fee Invoice',
                'name_fr' => 'Facture de frais',
                'module' => 'Fees',
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                'duplex' => 'none',
                'series_code' => null,
                'is_snapshot_backed' => true,
                'snapshot_source' => null,
                'carries_qr' => false,
                'carries_barcode' => false,
                'state_header' => 'none',
                'signature_roles' => json_encode(['bursar', 'accountant'], JSON_THROW_ON_ERROR),
                'min_phase' => 'v1',
                'bulk_printable' => false,
                'blade_view' => 'documents.fees.invoice',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'FEE-STATEMENT',
                'name' => 'Student Account Statement',
                'name_fr' => 'Relevé de compte élève',
                'module' => 'Fees',
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                'duplex' => 'none',
                'series_code' => null,
                // 10.3: live, "with a printed as_of date" - reprinting after
                // a data change is expected and correct, unlike the receipt
                // and the invoice above.
                'is_snapshot_backed' => false,
                'snapshot_source' => null,
                'carries_qr' => false,
                'carries_barcode' => false,
                'state_header' => 'none',
                'signature_roles' => json_encode(['accountant'], JSON_THROW_ON_ERROR),
                'min_phase' => 'v1',
                'bulk_printable' => true,
                'blade_view' => 'documents.fees.statement',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'WHT-CERT',
                'name' => 'Withholding Tax Attestation',
                'name_fr' => 'Attestation de retenue à la source',
                'module' => 'Tax',
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                'duplex' => 'none',
                'series_code' => null,
                'is_snapshot_backed' => true,
                'snapshot_source' => null,
                'carries_qr' => false,
                'carries_barcode' => false,
                'state_header' => 'none',
                'signature_roles' => json_encode(['accountant'], JSON_THROW_ON_ERROR),
                'min_phase' => 'v1',
                'bulk_printable' => false,
                'blade_view' => 'documents.tax.withholding_attestation',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'PAY-VOUCHER',
                'name' => 'Payment Voucher',
                'name_fr' => 'Bon de paiement',
                'module' => 'Procurement',
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                'duplex' => 'none',
                'series_code' => null,
                'is_snapshot_backed' => true,
                'snapshot_source' => null,
                'carries_qr' => false,
                'carries_barcode' => false,
                'state_header' => 'none',
                'signature_roles' => json_encode(['accountant', 'bursar'], JSON_THROW_ON_ERROR),
                'min_phase' => 'v1',
                'bulk_printable' => false,
                'blade_view' => 'documents.procurement.payment_voucher',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('document_templates')->whereIn('code', [
            'FEE-RECEIPT', 'FEE-RECEIPT-POS', 'FEE-INVOICE', 'FEE-STATEMENT', 'WHT-CERT', 'PAY-VOUCHER',
        ])->delete();

        DB::table('document_series')->whereIn('code', ['RCPT', 'INV', 'CN', 'WHT'])->delete();
    }
};

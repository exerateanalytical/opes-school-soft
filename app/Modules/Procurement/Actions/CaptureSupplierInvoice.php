<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\LineAmount;
use App\Modules\Procurement\Domain\MatchStatus;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use App\Modules\Procurement\Models\Supplier;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Tax\Actions\ComputeLineTax;
use App\Modules\Tax\Actions\ResolveTaxCodeFor;
use App\Modules\Tax\Actions\ResolveWithholding;
use App\Modules\Tax\Domain\TaxDirection;
use App\Modules\Tax\Domain\WithholdingRecognition;
use App\Modules\Tax\Domain\WithholdingResolution;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.5 - capture a facture fournisseur.
 *
 * Everything later steps rely on is SNAPSHOTTED here, per line, at
 * invoice_date: the TaxCode version and its rate (§5.3), the §5.4 prorata
 * split (via Tax\ComputeLineTax - the ONE computation path), and the §6.4
 * withholding resolution. Header totals are Money sums of the lines,
 * never independently rounded (00-core §7.3).
 *
 * Withholding recognition basis (§4.6) is CONFIGURATION, not choice:
 * TaxSettings.withholding_recognition must be confirmed before any
 * supplier invoice can exist - amounts are recognised here under
 * `on_invoice`, or left to the payment under `on_payment`; in both modes
 * the resolution runs so an unresolved line flags the invoice (§6.4.7 -
 * silence is not an answer).
 *
 * The duplicate-payment control: a friendly validation first, and the
 * UNIQUE(supplier_id, supplier_invoice_no) constraint as the real gate.
 *
 * @phpstan-type CaptureLine array{description: string, quantity?: string, unit_of_measure?: string|null, unit_price_ht: int, discount_rate_bp?: int, tax_code_id: int, expense_account_id: int, purchase_order_line_id?: int|null, goods_receipt_line_id?: int|null, is_capitalised?: bool, asset_category_id?: int|null, asset_id?: int|null, inventory_item_id?: int|null, nature?: string}
 */
final class CaptureSupplierInvoice
{
    public function __construct(
        private readonly ResolveTaxCodeFor $resolveTaxCode,
        private readonly ComputeLineTax $computeLineTax,
        private readonly ResolveWithholding $resolveWithholding,
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @phpstan-param list<CaptureLine> $lines
     */
    public function handle(array $header, array $lines, Actor $actor): SupplierInvoice
    {
        Gate::authorize(SupplierInvoicePermission::CREATE);

        if ($lines === []) {
            throw new DomainException('A supplier invoice needs at least one line.');
        }

        $recognition = $this->recognitionBasis();

        return DB::transaction(function () use ($header, $lines, $recognition, $actor): SupplierInvoice {
            $idempotencyKey = isset($header['idempotency_key']) ? (string) $header['idempotency_key'] : null;

            if ($idempotencyKey !== null) {
                /** @var SupplierInvoice|null $existing */
                $existing = SupplierInvoice::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            /** @var Supplier $supplier */
            $supplier = Supplier::query()->findOrFail((int) $header['supplier_id']);

            if (! $supplier->is_active || $supplier->is_archived) {
                throw ValidationException::withMessages([
                    'supplier_id' => "Supplier {$supplier->name} is not active.",
                ]);
            }

            $invoiceDate = Carbon::parse((string) $header['invoice_date'])->toDateString();
            $supplierInvoiceNo = trim((string) $header['supplier_invoice_no']);

            if ($supplierInvoiceNo === '') {
                throw ValidationException::withMessages([
                    'supplier_invoice_no' => "The supplier's own invoice number is mandatory - it is the duplicate-payment control (03-tax-procurement 4.5).",
                ]);
            }

            $duplicate = SupplierInvoice::query()
                ->where('supplier_id', $supplier->id)
                ->where('supplier_invoice_no', $supplierInvoiceNo)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'supplier_invoice_no' => sprintf(
                        'Invoice %s from %s is already captured - the same supplier number twice is the classic duplicate-payment vector (03-tax-procurement 4.5).',
                        $supplierInvoiceNo,
                        $supplier->name,
                    ),
                ]);
            }

            $calendar = $this->calendarFor($invoiceDate, $header);
            $payableAccountId = isset($header['payable_account_id'])
                ? (int) $header['payable_account_id']
                : $supplier->payable_account_id;

            $computed = $this->computeLines($lines, $invoiceDate);

            $this->assertCapexPayableFamily($computed['any_capitalised'], $payableAccountId);

            // §6.4 - resolve withholding for every line NOW, in both
            // recognition modes: under on_payment the amounts are recognised
            // later, but an unresolved line must flag the invoice today.
            $resolutions = $this->resolveWithholding->handle(
                $this->supplierConditionAttributes($supplier),
                $computed['withholding_inputs'],
                $invoiceDate,
            );

            $withholdingTotal = Money::zero();
            $unresolved = false;

            foreach ($resolutions as $index => $resolution) {
                $line = &$computed['rows'][$index];
                $line['withholding_reason'] = $resolution->reason;
                $line['withholding_exemption_ref'] = $resolution->exemptionRef;

                if ($resolution->isUnresolved()) {
                    $unresolved = true;
                }

                if ($recognition === WithholdingRecognition::OnInvoice && $resolution->isWithheld()) {
                    $line['withholding_rule_id'] = $resolution->ruleId;
                    $line['withholding_base'] = $resolution->baseAmount;
                    $line['withholding_rate_bp_applied'] = $resolution->rateBpApplied;
                    $line['withholding_amount'] = $resolution->withheldAmount;
                    $withholdingTotal = $withholdingTotal->plus(Money::of($resolution->withheldAmount));
                } elseif ($resolution->ruleId !== null) {
                    // Below threshold, or on_payment preview: name the rule
                    // without recognising an amount.
                    $line['withholding_rule_id'] = $resolution->ruleId;
                    $line['withholding_rate_bp_applied'] = $resolution->rateBpApplied;
                }

                unset($line);
            }

            $retention = $this->retentionFor($header, $computed['total_ttc']);

            $year = Carbon::parse($invoiceDate)->format('Y');
            $internalNo = sprintf('FF/%s/%06d', $year, $this->sequence->allocate('FF'));

            $invoice = SupplierInvoice::query()->create([
                'internal_no' => $internalNo,
                'supplier_invoice_no' => $supplierInvoiceNo,
                'supplier_id' => $supplier->id,
                'purchase_order_id' => isset($header['purchase_order_id']) ? (int) $header['purchase_order_id'] : null,
                'invoice_date' => $invoiceDate,
                'received_date' => isset($header['received_date'])
                    ? Carbon::parse((string) $header['received_date'])->toDateString()
                    : $invoiceDate,
                // 02-accounting C4: the ECONOMIC date, retained even when a
                // late invoice is forward-posted into the first open period.
                'value_date' => $invoiceDate,
                'due_date' => isset($header['due_date'])
                    ? Carbon::parse((string) $header['due_date'])->toDateString()
                    : Carbon::parse($invoiceDate)->addDays($supplier->payment_terms_days)->toDateString(),
                'currency' => (string) ($header['currency'] ?? 'XAF'),
                'exchange_rate_bp' => isset($header['exchange_rate_bp']) ? (int) $header['exchange_rate_bp'] : null,
                'subtotal_ht' => $computed['subtotal_ht']->amount(),
                'discount_total' => $computed['discount_total']->amount(),
                'tax_total' => $computed['tax_total']->amount(),
                'total_ttc' => $computed['total_ttc']->amount(),
                'withholding_total' => $withholdingTotal->amount(),
                'net_payable' => $computed['total_ttc']->minus($withholdingTotal)->amount(),
                'retention_amount' => $retention,
                'payable_account_id' => $payableAccountId,
                'status' => SupplierInvoiceStatus::Draft,
                'match_status' => MatchStatus::NotRequired,
                'withholding_unresolved' => $unresolved,
                'created_by' => $actor->id,
                'is_migration' => (bool) ($header['is_migration'] ?? false),
                'academic_year_id' => $calendar['academic_year_id'],
                'fiscal_year_id' => $calendar['fiscal_year_id'],
                'accounting_period_id' => $calendar['accounting_period_id'],
                'document_id' => isset($header['document_id']) ? (int) $header['document_id'] : null,
                'version' => 0,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($computed['rows'] as $row) {
                $invoice->lines()->create($row);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Procurement',
                auditableType: SupplierInvoice::class,
                auditableId: (int) $invoice->getKey(),
                after: [
                    'internal_no' => $internalNo,
                    'supplier_invoice_no' => $supplierInvoiceNo,
                    'supplier_id' => $supplier->id,
                    'total_ttc' => $computed['total_ttc']->amount(),
                    'withholding_total' => $withholdingTotal->amount(),
                    'withholding_unresolved' => $unresolved,
                ],
                actor: $actor,
            );

            return $invoice->refresh();
        });
    }

    /**
     * §4.6: the recognition point is configuration, confirmed with the
     * accountant BEFORE the module computes anything - never a per-invoice
     * choice, never a silent default.
     */
    private function recognitionBasis(): WithholdingRecognition
    {
        // Cross-module READ via DB::table - TaxSettings is Tax's model
        // (00-core §6.2).
        $settings = DB::table('tax_settings')->where('id', 1)->first(['withholding_recognition', 'confirmed_at']);

        if ($settings === null || $settings->withholding_recognition === null || $settings->confirmed_at === null) {
            throw new DomainException(
                'TaxSettings.withholding_recognition is not confirmed - configure the withholding '
                .'recognition basis with your accountant before recording supplier invoices '
                .'(03-tax-procurement 4.6, blocking gate 6).'
            );
        }

        return WithholdingRecognition::from((string) $settings->withholding_recognition);
    }

    /**
     * Compute every line's amount and tax snapshot.
     *
     * @phpstan-param list<CaptureLine> $lines
     *
     * @return array{rows: list<array<string, mixed>>, withholding_inputs: list<array{amount_ht: int, amount_ttc: int, nature: string}>, subtotal_ht: Money, discount_total: Money, tax_total: Money, total_ttc: Money, any_capitalised: bool}
     */
    private function computeLines(array $lines, string $invoiceDate): array
    {
        $rows = [];
        $withholdingInputs = [];
        $subtotal = Money::zero();
        $discountTotal = Money::zero();
        $taxTotal = Money::zero();
        $anyCapitalised = false;

        foreach ($lines as $index => $line) {
            $quantity = (string) ($line['quantity'] ?? '1');
            $discountBp = (int) ($line['discount_rate_bp'] ?? 0);
            $amountHt = LineAmount::compute($quantity, $line['unit_price_ht'], $discountBp);
            $gross = LineAmount::compute($quantity, $line['unit_price_ht']);

            $taxCode = $this->resolveTaxCode->handle($line['tax_code_id'], $invoiceDate);
            $lineTax = $this->computeLineTax->handle($amountHt, $line['tax_code_id'], $invoiceDate, TaxDirection::Input);

            $isCapitalised = (bool) ($line['is_capitalised'] ?? false);

            if ($isCapitalised && ($line['asset_category_id'] ?? null) === null && ($line['asset_id'] ?? null) === null) {
                throw ValidationException::withMessages([
                    'lines' => sprintf(
                        'Line %d is capitalised but names no asset category - the provisional asset cannot be costed (03-tax-procurement 4.5 invariant 3).',
                        $index + 1,
                    ),
                ]);
            }

            $anyCapitalised = $anyCapitalised || $isCapitalised;

            $rows[] = [
                'line_no' => $index + 1,
                'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                'goods_receipt_line_id' => $line['goods_receipt_line_id'] ?? null,
                'description' => $line['description'],
                'quantity' => $quantity,
                'unit_of_measure' => $line['unit_of_measure'] ?? null,
                'unit_price_ht' => $line['unit_price_ht'],
                'discount_rate_bp' => $discountBp,
                'amount_ht' => $amountHt,
                'tax_code_id' => $line['tax_code_id'],
                'tax_rate_bp_applied' => $taxCode->rate_bp,
                'tax_amount' => $lineTax->taxAmount,
                'deductible_tax_amount' => $lineTax->deductible,
                'non_deductible_tax_amount' => $lineTax->nonDeductible,
                'expense_account_id' => $line['expense_account_id'],
                'is_capitalised' => $isCapitalised,
                'asset_id' => $line['asset_id'] ?? null,
                'asset_category_id' => $line['asset_category_id'] ?? null,
                'inventory_item_id' => $line['inventory_item_id'] ?? null,
                'withholding_rule_id' => null,
                'withholding_base' => 0,
                'withholding_rate_bp_applied' => 0,
                'withholding_amount' => 0,
                'match_status' => MatchStatus::NotRequired->value,
            ];

            $withholdingInputs[] = [
                'amount_ht' => $amountHt,
                'amount_ttc' => $amountHt + $lineTax->taxAmount,
                'nature' => $this->lineNature($line),
            ];

            $subtotal = $subtotal->plus(Money::of($amountHt));
            $discountTotal = $discountTotal->plus(Money::of($gross - $amountHt));
            $taxTotal = $taxTotal->plus(Money::of($lineTax->taxAmount));
        }

        return [
            'rows' => $rows,
            'withholding_inputs' => $withholdingInputs,
            'subtotal_ht' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'total_ttc' => $subtotal->plus($taxTotal),
            'any_capitalised' => $anyCapitalised,
        ];
    }

    /**
     * §6.4 step 2: which rule family a line's nature selects. Explicit
     * `nature` wins; otherwise an inventory/asset line is goods, anything
     * else a service.
     *
     * @phpstan-param CaptureLine $line
     */
    private function lineNature(array $line): string
    {
        $nature = $line['nature'] ?? null;

        if (is_string($nature) && $nature !== '') {
            return $nature;
        }

        $isGoods = ($line['inventory_item_id'] ?? null) !== null
            || ($line['asset_category_id'] ?? null) !== null
            || ($line['asset_id'] ?? null) !== null;

        return $isGoods ? 'goods' : 'services';
    }

    /**
     * §3.3 invariant: any capitalised line forces a 481-family payable.
     */
    private function assertCapexPayableFamily(bool $anyCapitalised, int $payableAccountId): void
    {
        if (! $anyCapitalised) {
            return;
        }

        $code = (string) DB::table('chart_of_accounts')->where('id', $payableAccountId)->value('code');

        if (! str_starts_with($code, '481')) {
            throw new DomainException(sprintf(
                'This invoice capitalises at least one line; its payable must be in the 481 family '
                .'(fournisseurs d\'investissements), not account %s (03-tax-procurement 3.3).',
                $code,
            ));
        }
    }

    /**
     * §3.3 retenue de garantie: snapshotted from the PO's retention_rate_bp
     * (per-10 000 basis points, the procurement-settings scale).
     *
     * @param  array<string, mixed>  $header
     */
    private function retentionFor(array $header, Money $totalTtc): int
    {
        $poId = isset($header['purchase_order_id']) ? (int) $header['purchase_order_id'] : null;

        if ($poId === null) {
            return 0;
        }

        $rateBp = (int) DB::table('purchase_orders')->where('id', $poId)->value('retention_rate_bp');

        if ($rateBp <= 0) {
            return 0;
        }

        return intdiv($totalTtc->amount() * $rateBp + 5_000, 10_000);
    }

    /**
     * The §6.2 supplier_condition vocabulary, crossing to Tax as a plain
     * attribute array (00-core §6.2 - Tax may not import Supplier).
     *
     * @return array{is_withholding_exempt: bool, withholding_exemption_ref: string|null, withholding_exemption_expires_on: string|null, regime_fiscal: string|null, has_contributor_card: bool, niu_status: string|null, supplier_type: string, country: string|null}
     */
    private function supplierConditionAttributes(Supplier $supplier): array
    {
        return [
            'is_withholding_exempt' => $supplier->is_withholding_exempt,
            'withholding_exemption_ref' => $supplier->withholding_exemption_ref,
            'withholding_exemption_expires_on' => $supplier->withholding_exemption_expires_on,
            'regime_fiscal' => $supplier->regime_fiscal?->value,
            'has_contributor_card' => $supplier->has_contributor_card,
            'niu_status' => $supplier->niu_status->value,
            'supplier_type' => $supplier->supplier_type->value,
            'country' => $supplier->country,
        ];
    }

    /**
     * The dual calendar of the invoice date (02-accounting C3), read via
     * DB::table - the calendar tables are Accounting's models.
     *
     * @param  array<string, mixed>  $header
     * @return array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}
     */
    private function calendarFor(string $invoiceDate, array $header): array
    {
        $period = DB::table('accounting_periods')
            ->whereDate('starts_on', '<=', $invoiceDate)
            ->whereDate('ends_on', '>=', $invoiceDate)
            ->first(['id', 'fiscal_year_id']);

        if ($period === null) {
            throw new DomainException(
                "No accounting period covers {$invoiceDate}; the fiscal-year calendar is misconfigured."
            );
        }

        $academicYearId = isset($header['academic_year_id'])
            ? (int) $header['academic_year_id']
            : (int) DB::table('academic_years')
                ->whereDate('starts_on', '<=', $invoiceDate)
                ->whereDate('ends_on', '>=', $invoiceDate)
                ->orderBy('starts_on')
                ->value('id');

        if ($academicYearId === 0) {
            throw new DomainException(
                "No academic year covers {$invoiceDate} and none was given (02-accounting C3 dual calendar)."
            );
        }

        return [
            'fiscal_year_id' => (int) $period->fiscal_year_id,
            'accounting_period_id' => (int) $period->id,
            'academic_year_id' => $academicYearId,
        ];
    }
}

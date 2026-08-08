<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * The complete event catalogue of docs/specs/02-accounting.md §11.2.
 *
 * Each event declares a typed payload schema; posting-rule expressions are
 * validated against it at save time (§11.1). `payloadSchema()` is an
 * exhaustive match with NO default arm - adding a case without a schema is
 * an UnhandledMatchError, which the arch test in PostingRuleTest turns into
 * a failing build ("Adding an event without a schema fails the arch test").
 *
 * Schema types: 'int' | 'string' | 'bool' | 'date' | 'partner' | 'list'.
 * List element fields are declared as '<list>.*.<field>' and are exposed to
 * an iterating line's expressions as 'item.<field>'.
 */
enum PostingEvent: string
{
    // Fees / receivables
    case FeeInvoiceIssued = 'fee.invoice.issued';
    case FeeInvoiceCancelled = 'fee.invoice.cancelled';
    case FeeCreditNoteIssued = 'fee.credit_note.issued';
    case FeePaymentReceived = 'fee.payment.received';
    case FeePaymentVoided = 'fee.payment.voided';
    case FeeRefundIssued = 'fee.refund.issued';
    case FeeAdjustmentGranted = 'fee.adjustment.granted';
    case ReceivableWrittenOff = 'receivable.written_off';
    case ReceivableProvisionRecognized = 'receivable.provision.recognized';
    case ReceivableProvisionReversed = 'receivable.provision.reversed';
    case RevenueDeferralRecognized = 'revenue.deferral.recognized';
    case RevenueDeferralReversed = 'revenue.deferral.reversed';
    case ThirdPartyFundCollected = 'thirdparty.fund.collected';
    case ThirdPartyFundRemitted = 'thirdparty.fund.remitted';

    // Treasury
    case ChequeReceived = 'cheque.received';
    case ChequeCleared = 'cheque.cleared';
    case ChequeBounced = 'cheque.bounced';
    case CashdeskClosedWithVariance = 'cashdesk.closed_with_variance';
    case BankChargeRecorded = 'bank.charge.recorded';
    case BankInterestReceived = 'bank.interest.received';
    case TreasuryTransfer = 'treasury.transfer';
    case MobileMoneyCommissionCharged = 'mobile_money.commission.charged';

    // Procurement / payables (03)
    case SupplierInvoiceReceived = 'supplier.invoice.received';
    case SupplierCreditNoteReceived = 'supplier.credit_note.received';
    case SupplierPaid = 'supplier.paid';
    case WithholdingRetained = 'withholding.retained';
    case GoodsReceivedNotInvoiced = 'goods.received_not_invoiced';

    // Inventory (06)
    case InventoryPurchased = 'inventory.purchased';
    case InventoryReceivedIntoStock = 'inventory.received_into_stock';
    case InventoryIssued = 'inventory.issued';
    case InventorySold = 'inventory.sold';
    case InventoryTransfer = 'inventory.transfer';
    case InventoryStocktakeVariance = 'inventory.stocktake.variance';
    case InventoryWrittenOff = 'inventory.written_off';

    // Assets (06)
    case AssetAcquired = 'asset.acquired';
    case AssetCommissioned = 'asset.commissioned';
    case AssetDepreciated = 'asset.depreciated';
    case AssetImpaired = 'asset.impaired';
    case AssetRevalued = 'asset.revalued';
    case AssetDisposed = 'asset.disposed';
    case AssetSubsidyReceived = 'asset.subsidy.received';
    case AssetSubsidyReleased = 'asset.subsidy.released';

    // Payroll (05)
    case PayrollApproved = 'payroll.approved';
    case PayrollPaid = 'payroll.paid';
    case PayrollReversed = 'payroll.reversed';
    case PayrollLeaveProvision = 'payroll.leave_provision';
    case PayrollLeaveProvisionReversed = 'payroll.leave_provision.reversed';
    case PayrollSettlementFinal = 'payroll.settlement.final';

    // Tax (03)
    case TaxVatDeclared = 'tax.vat.declared';
    case TaxRemitted = 'tax.remitted';
    case TaxProvisionRecognized = 'tax.provision.recognized';

    // Library (06)
    case LibraryFineLevied = 'library.fine.levied';
    case LibraryFineCollected = 'library.fine.collected';
    case LibraryBookLost = 'library.book.lost';

    // Year end
    case YearEndClosing = 'year_end.closing';
    case YearEndAppropriation = 'year_end.appropriation';
    case YearEndOpeningBalances = 'year_end.opening_balances';
    case FxRevaluation = 'fx.revaluation';

    /**
     * @return non-empty-array<string, string>
     */
    public function payloadSchema(): array
    {
        return match ($this) {
            self::FeeInvoiceIssued,
            self::FeeInvoiceCancelled,
            self::FeeCreditNoteIssued => [
                'invoice.total' => 'int',
                'invoice.reference' => 'string',
                'invoice.partner' => 'partner',
                'invoice.receivable_account_id' => 'int',
                'invoice.lines' => 'list',
                'invoice.lines.*.amount' => 'int',
                'invoice.lines.*.revenue_account_id' => 'int',
                'invoice.lines.*.label' => 'string',
                'invoice.lines.*.is_agent_collected' => 'bool',
                'invoice.lines.*.partner' => 'partner',
            ],

            self::FeePaymentReceived,
            self::FeePaymentVoided,
            self::FeeRefundIssued => [
                'payment.amount' => 'int',
                'payment.commission' => 'int',
                'payment.net_amount' => 'int',
                'payment.reference' => 'string',
                'payment.commission_rate_label' => 'string',
                'payment.partner' => 'partner',
                'payment.partner_label' => 'string',
                'payment.invoice_reference' => 'string',
                'payment.method.treasury_account_id' => 'int',
                'payment.method.fee_bearer_is_school' => 'bool',
                'payment.receivable_account_id' => 'int',
            ],

            self::FeeAdjustmentGranted,
            self::ReceivableWrittenOff,
            self::ReceivableProvisionRecognized,
            self::ReceivableProvisionReversed,
            self::RevenueDeferralRecognized,
            self::RevenueDeferralReversed => [
                'adjustment.amount' => 'int',
                'adjustment.reference' => 'string',
                'adjustment.partner' => 'partner',
                'adjustment.receivable_account_id' => 'int',
                'adjustment.counterpart_account_id' => 'int',
            ],

            self::ThirdPartyFundCollected,
            self::ThirdPartyFundRemitted => [
                'fund.amount' => 'int',
                'fund.reference' => 'string',
                'fund.partner' => 'partner',
                'fund.liability_account_id' => 'int',
                'fund.treasury_account_id' => 'int',
            ],

            self::ChequeReceived,
            self::ChequeCleared,
            self::ChequeBounced,
            self::BankChargeRecorded,
            self::BankInterestReceived,
            self::TreasuryTransfer,
            self::MobileMoneyCommissionCharged => [
                'movement.amount' => 'int',
                'movement.reference' => 'string',
                'movement.from_account_id' => 'int',
                'movement.to_account_id' => 'int',
            ],

            self::CashdeskClosedWithVariance => [
                'variance.amount' => 'int',
                'variance.is_shortage' => 'bool',
                'variance.reference' => 'string',
                'variance.custodian' => 'partner',
                'variance.cash_account_id' => 'int',
            ],

            self::SupplierInvoiceReceived,
            self::SupplierCreditNoteReceived,
            self::SupplierPaid,
            self::WithholdingRetained,
            self::GoodsReceivedNotInvoiced => [
                'document.total' => 'int',
                'document.reference' => 'string',
                'document.partner' => 'partner',
                'document.payable_account_id' => 'int',
                'document.lines' => 'list',
                'document.lines.*.amount' => 'int',
                'document.lines.*.expense_account_id' => 'int',
                'document.lines.*.label' => 'string',
            ],

            self::InventoryPurchased,
            self::InventoryReceivedIntoStock,
            self::InventoryIssued,
            self::InventorySold,
            self::InventoryTransfer,
            self::InventoryStocktakeVariance,
            self::InventoryWrittenOff => [
                'movement.amount' => 'int',
                'movement.reference' => 'string',
                'movement.partner' => 'partner',
                'movement.purchase_account_id' => 'int',
                'movement.stock_account_id' => 'int',
                'movement.variation_account_id' => 'int',
            ],

            self::AssetAcquired,
            self::AssetCommissioned,
            self::AssetDepreciated,
            self::AssetImpaired,
            self::AssetRevalued,
            self::AssetDisposed,
            self::AssetSubsidyReceived,
            self::AssetSubsidyReleased => [
                'asset.cost' => 'int',
                'asset.accumulated_depreciation' => 'int',
                'asset.net_book_value' => 'int',
                'asset.proceeds' => 'int',
                'asset.reference' => 'string',
                'asset.partner' => 'partner',
                'asset.asset_account_id' => 'int',
                'asset.depreciation_account_id' => 'int',
                'asset.disposal_value_account_id' => 'int',
                'asset.disposal_proceeds_account_id' => 'int',
            ],

            self::PayrollApproved,
            self::PayrollPaid,
            self::PayrollReversed,
            self::PayrollLeaveProvision,
            self::PayrollLeaveProvisionReversed,
            self::PayrollSettlementFinal => [
                'run.total_gross' => 'int',
                'run.total_employer_charges' => 'int',
                'run.total_net' => 'int',
                'run.reference' => 'string',
                'run.items' => 'list',
                'run.items.*.net' => 'int',
                'run.items.*.staff_partner' => 'partner',
                'run.items.*.label' => 'string',
                'run.remittances' => 'list',
                'run.remittances.*.amount' => 'int',
                'run.remittances.*.liability_account_id' => 'int',
                'run.remittances.*.label' => 'string',
            ],

            self::TaxVatDeclared,
            self::TaxRemitted,
            self::TaxProvisionRecognized => [
                'declaration.amount' => 'int',
                'declaration.reference' => 'string',
                'declaration.liability_account_id' => 'int',
                'declaration.counterpart_account_id' => 'int',
            ],

            self::LibraryFineLevied,
            self::LibraryFineCollected,
            self::LibraryBookLost => [
                'fine.amount' => 'int',
                'fine.reference' => 'string',
                'fine.partner' => 'partner',
                'fine.receivable_account_id' => 'int',
                'fine.income_account_id' => 'int',
            ],

            self::YearEndClosing,
            self::YearEndAppropriation,
            self::YearEndOpeningBalances,
            self::FxRevaluation => [
                'closing.amount' => 'int',
                'closing.reference' => 'string',
                'closing.result_account_id' => 'int',
                'closing.counterpart_account_id' => 'int',
            ],
        };
    }

    /**
     * The variables an amount/condition expression may reference: every
     * int/bool payload key, plus - when the line iterates - the element
     * fields of the iterated list, exposed under the 'item.' prefix.
     *
     * @return list<string>
     */
    public function expressionVariables(?string $iteratesOver = null): array
    {
        $variables = [];

        foreach ($this->payloadSchema() as $path => $type) {
            if (! in_array($type, ['int', 'bool'], true)) {
                continue;
            }

            if (str_contains($path, '.*.')) {
                [$listPath, $field] = explode('.*.', $path, 2);

                if ($iteratesOver !== null && $listPath === $iteratesOver) {
                    $variables[] = 'item.'.$field;
                }

                continue;
            }

            $variables[] = $path;
        }

        return $variables;
    }

    /**
     * The variables a label template may reference (strings included).
     *
     * @return list<string>
     */
    public function labelVariables(?string $iteratesOver = null): array
    {
        $variables = [];

        foreach ($this->payloadSchema() as $path => $type) {
            if (! in_array($type, ['int', 'bool', 'string'], true)) {
                continue;
            }

            if (str_contains($path, '.*.')) {
                [$listPath, $field] = explode('.*.', $path, 2);

                if ($iteratesOver !== null && $listPath === $iteratesOver) {
                    $variables[] = 'item.'.$field;
                }

                continue;
            }

            $variables[] = $path;
        }

        return $variables;
    }

    /**
     * Payload paths that hold collections a line may iterate over.
     *
     * @return list<string>
     */
    public function listPaths(): array
    {
        $paths = [];

        foreach ($this->payloadSchema() as $path => $type) {
            if ($type === 'list') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Payload paths that hold `(type, id)` partner tuples.
     *
     * @return list<string>
     */
    public function partnerPaths(?string $iteratesOver = null): array
    {
        $paths = [];

        foreach ($this->payloadSchema() as $path => $type) {
            if ($type !== 'partner') {
                continue;
            }

            if (str_contains($path, '.*.')) {
                [$listPath, $field] = explode('.*.', $path, 2);

                if ($iteratesOver !== null && $listPath === $iteratesOver) {
                    $paths[] = 'item.'.$field;
                }

                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Payload paths an `account_path` line may resolve an account id from.
     *
     * @return list<string>
     */
    public function accountPaths(?string $iteratesOver = null): array
    {
        $paths = [];

        foreach ($this->payloadSchema() as $path => $type) {
            if ($type !== 'int' || ! str_ends_with($path, '_account_id')) {
                continue;
            }

            if (str_contains($path, '.*.')) {
                [$listPath, $field] = explode('.*.', $path, 2);

                if ($iteratesOver !== null && $listPath === $iteratesOver) {
                    $paths[] = 'item.'.$field;
                }

                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }
}

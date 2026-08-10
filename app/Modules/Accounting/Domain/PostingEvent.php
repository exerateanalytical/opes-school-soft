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
    // 04-fees §12.6/§22: the per-student reclassification of a credit balance
    // to 4191 (advances received). Emitted by CarryForwardStudentCredit at
    // year rollover (08-operations §6.2 step 7) and, later, by the nightly
    // RunReceivableReclassification. One entry per student - OHADA
    // non-compensation forbids netting across students (04-fees A5/C9).
    case ReceivableReclassified = 'receivable.reclassified';
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

    // Expense capture (02 §21.3) - the petty, unregistered, cash-and-receipt
    // charge that never goes near a supplier invoice. Dr the class 6 (or
    // class 2 capex) lines, Cr the treasury account that actually paid.
    case ExpenseRecorded = 'expense.recorded';

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
    // 06-assets-stores §6.4/§12: grant clawback reverses the unreleased
    // balance against a liability to the donor.
    case AssetSubsidyClawedBack = 'asset.subsidy.clawed_back';

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
            self::ReceivableReclassified,
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

            // §21.3 - the expense voucher. `expense.lines` is the iterated
            // debit side (one Dr per charge line, class 6 or class 2);
            // `expense.treasury_account_id` is the single credit. The
            // partner tuple is present but usually null - an unregistered
            // market trader has no third-party account, which is precisely
            // why this document exists rather than a supplier invoice.
            self::ExpenseRecorded => [
                'expense.total' => 'int',
                'expense.reference' => 'string',
                'expense.description' => 'string',
                'expense.payee_label' => 'string',
                'expense.partner' => 'partner',
                'expense.treasury_account_id' => 'int',
                'expense.lines' => 'list',
                'expense.lines.*.amount' => 'int',
                'expense.lines.*.expense_account_id' => 'int',
                'expense.lines.*.label' => 'string',
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
            self::AssetImpaired,
            self::AssetRevalued => [
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

            // 06-assets-stores §6.1/§6.2 - the gross disposal entry needs a
            // settlement side (485 receivable or a treasury account) on top
            // of the shared asset shape.
            self::AssetDisposed => [
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
                'asset.settlement_account_id' => 'int',
            ],

            // 06-assets-stores §4 - ONE journal entry per depreciation run,
            // one Dr 681x / Cr 28x pair per asset via an iterating rule.
            // Charges are SIGNED (§5.5 estimate reductions post a credit to
            // 681x), so rule lines must be declared `signed`.
            self::AssetDepreciated => [
                'run.total_charge' => 'int',
                'run.reference' => 'string',
                'run.lines' => 'list',
                'run.lines.*.charge' => 'int',
                'run.lines.*.expense_account_id' => 'int',
                'run.lines.*.accumulated_account_id' => 'int',
                'run.lines.*.reference' => 'string',
            ],

            // 06-assets-stores §6.3/§6.4 - subventions d'investissement:
            // receipt into 14, quote-part released to income in step with
            // depreciation, clawback of the unreleased balance to a donor
            // liability. counterpart_account_id carries the non-14 side the
            // Action resolved (income 845 for releases, the donor liability
            // for clawbacks, the asset account for receipts).
            self::AssetSubsidyReceived,
            self::AssetSubsidyReleased,
            self::AssetSubsidyClawedBack => [
                'subsidy.amount' => 'int',
                'subsidy.reference' => 'string',
                'subsidy.partner' => 'partner',
                'subsidy.subsidy_account_id' => 'int',
                'subsidy.counterpart_account_id' => 'int',
            ],

            self::PayrollApproved,
            self::PayrollReversed,
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

            // 8.8 - the disbursement batch total leaving staff payable for
            // treasury (ExportDisbursementFile): a lump sum, not a
            // per-employee list, because the per-employee detail already
            // posted at approval (PayrollApproved above).
            self::PayrollPaid => [
                'payment.amount' => 'int',
                'payment.reference' => 'string',
                'payment.method' => 'string',
                'payment.treasury_account_id' => 'int',
                'payment.payroll_month' => 'string',
            ],

            // 12.5 - the monthly accrued-leave provision (PostLeaveProvision):
            // one Dr 66x / Cr 428x lump sum per month, mapped from the
            // ALLOCATION_CONGE component once its accounts are confirmed.
            self::PayrollLeaveProvision,
            self::PayrollLeaveProvisionReversed => [
                'provision.amount' => 'int',
                'provision.month' => 'string',
                'provision.expense_account_id' => 'int',
                'provision.liability_account_id' => 'int',
                'provision.reference' => 'string',
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

            // §17.2 step 9 / §18.1-§18.2. The four scalars below are the
            // original shape and are UNCHANGED - `closing.amount` is still
            // the entry total, `closing.result_account_id` still compte 13.
            // The two collections are ADDITIVE and are what makes the close
            // expressible at all: §18.1 closes EVERY class 6/7/8 account
            // with a balance (one line each, not a lump), and §18.2 is
            // explicit that the à-nouveaux carries every collective balance
            // as ONE LINE PER PARTNER - "for a 1 200-student school this is
            // a several-thousand-line entry; that is correct and expected".
            // A four-scalar payload can express neither.
            //
            // Two collections rather than one because a partner tuple is
            // not optional in EvaluatePostingRule: `partner_source` either
            // resolves to a (type, id) pair or throws. Partner-bearing
            // lines (collective accounts, L8) iterate `closing.partner_lines`;
            // everything else iterates `closing.lines`. Amounts on both are
            // SIGNED (positive debits, negative credits): a class 7 account
            // being emptied is a debit, a class 6 a credit, and which side
            // any given account lands on is data, not configuration.
            self::YearEndClosing,
            self::YearEndAppropriation,
            self::YearEndOpeningBalances => [
                'closing.amount' => 'int',
                'closing.reference' => 'string',
                'closing.result_account_id' => 'int',
                'closing.counterpart_account_id' => 'int',
                'closing.lines' => 'list',
                'closing.lines.*.amount' => 'int',
                'closing.lines.*.target_account_id' => 'int',
                'closing.lines.*.label' => 'string',
                'closing.partner_lines' => 'list',
                'closing.partner_lines.*.amount' => 'int',
                'closing.partner_lines.*.target_account_id' => 'int',
                'closing.partner_lines.*.label' => 'string',
                'closing.partner_lines.*.partner' => 'partner',
                // §18.2: "each carried partner line retains its `due_date`,
                // so aging survives the boundary".
                'closing.partner_lines.*.due_date' => 'string',
            ],

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

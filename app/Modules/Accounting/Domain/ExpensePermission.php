<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * The expense-capture ability names, as string constants - the same
 * contract Procurement's {Procurement,SupplierInvoice,SupplierPayment}
 * Permission classes use: Actions and screens gate on THESE strings, and
 * the shared `Identity\Domain\Permission` enum carries the matching case as
 * their compile-time face.
 *
 * SEGREGATION OF DUTIES (§21.3, "maker-checker above a configurable
 * threshold"): recording/submitting and approving are DISTINCT rights, and
 * ApproveExpense additionally enforces submitter ≠ approver at runtime -
 * holding both permissions does not bypass it.
 *
 * `POST` reuses the EXISTING `ledger.post` right rather than inventing a
 * third: putting an expense in the ledger is the same act, and the same
 * hands, as posting any other entry.
 */
final class ExpensePermission
{
    /** Record an expense draft, edit it while draft, and submit it. */
    public const RECORD = 'expense.record';

    /** Approve a submitted expense (never one's own, above threshold). */
    public const APPROVE = 'expense.approve';

    /** Put an approved expense in the ledger - the existing ledger right. */
    public const POST = 'ledger.post';

    /** Read the expense register. Reuses the existing ledger read right. */
    public const VIEW = 'ledger.view';

    private function __construct() {}
}

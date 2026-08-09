<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §4.8. IssueSupplierCreditNote creates
 * and posts in one act, so `draft` exists only inside that transaction;
 * an issued avoir is never deleted (§9), only cancelled by reversal.
 */
enum SupplierCreditNoteStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
}

<?php

declare(strict_types=1);

namespace App\Support\Retention;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/02-accounting.md §15, AUDCIF Art. 24: no accounting record is
 * hard-deleted for 10 years from the end of the fiscal year it belongs to.
 *
 * Applied to JournalEntry, JournalEntryLine, JournalEntryAttachment,
 * Lettering, AccountingPeriod, FiscalYear, ChartOfAccount, Journal,
 * PostingRule, PostingRuleLine, StatutoryBook, BankStatement,
 * BankStatementLine, ReconciliationSession, ReconciliationMatch, Invoice,
 * Payment, CreditNote, SupplierInvoice, SupplierPayment, PayrollRun,
 * PayrollItem, DepreciationRun, DepreciationSchedule, StockMovement, Asset,
 * AuditLog, DocumentPrintLog.
 *
 * Anchored on `created_at`, not a per-model "fiscal year end" lookup: not
 * every model here carries a fiscal-year reference (AuditLog and
 * DocumentPrintLog do not), and a record is always created ON OR AFTER the
 * fiscal year it belongs to, so `created_at + 10 years` never expires
 * EARLIER than "10 years from that fiscal year's end" would - it is always
 * at least as protective, frequently more. A documented simplification, not
 * a shortcut around the rule.
 *
 * None of these models use SoftDeletes (00-core §15 is explicit on this): a
 * soft-deleted row is an invisible row, and invisibility is exactly what
 * this rule exists to prevent. Where a record must be withdrawn, it is
 * REVERSED (§9) or ARCHIVED (`is_archived`), both of which leave it in the
 * books - never deleted, retention window or not. This trait blocks the
 * delete path itself; it does not open a "delete after 10 years" path
 * either; nothing in this codebase calls ->delete() on these models at all
 * today, so in practice this converts a currently-theoretical gap into an
 * enforced one before the first real caller appears.
 */
trait Immutable10Year
{
    public static function bootImmutable10Year(): void
    {
        // Only `deleting`, not `forceDeleting`: that event is registered by
        // the SoftDeletes trait, not base Eloquent, and none of these
        // models use SoftDeletes (deliberately - see class docblock).
        // Calling `static::forceDeleting()` on a plain model is not a
        // silent no-op either - it is not a recognised static event
        // method here, so PHP's __callStatic fallback tries to construct a
        // FRESH instance and call it as an instance method, which
        // re-enters this very boot() call and throws "may not be called
        // ... while it is being booted." A plain model's ->delete() IS
        // already a real, permanent delete, so `deleting` alone covers
        // every deletion path these models can ever take.
        static::deleting(function ($model): void {
            self::assertRetentionExpired($model);
        });
    }

    private static function assertRetentionExpired($model): void
    {
        $createdAt = $model->getAttribute('created_at');

        if ($createdAt === null) {
            // A record with no created_at cannot prove it has aged out, so
            // the safe default is to refuse - the same "empty is safer than
            // wrong" principle 00-core §16 applies to seeded data applies
            // here to an unprovable retention age.
            throw new RuntimeException(
                get_class($model).' has no created_at and cannot prove its 10-year AUDCIF retention '
                .'(Art. 24) has expired; it may not be deleted.'
            );
        }

        $createdAt = $createdAt instanceof Carbon ? $createdAt : Carbon::parse((string) $createdAt);
        $retainUntil = $createdAt->copy()->addYears(10);

        if ($retainUntil->isFuture()) {
            throw new RuntimeException(sprintf(
                '%s #%s is an accounting record under AUDCIF Art. 24\'s 10-year retention rule; it '
                .'cannot be deleted until %s. Withdraw it by reversal or archival instead - both leave it '
                .'in the books, which the rule requires.',
                class_basename($model),
                $model->getKey(),
                $retainUntil->toDateString(),
            ));
        }
    }
}

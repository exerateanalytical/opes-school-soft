<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates a `draft` JournalEntry and its lines. docs/specs/02-accounting.md
 * §4.1/§4.3.
 *
 * Two invariants this signature enforces by CONSTRUCTION, not by validation:
 *
 *  - L6: this method has NO `$accountingPeriodId` / `$fiscalYearId` /
 *    `$academicYearId` parameter. There is no way to pass one in - they are
 *    resolved here from `$date` via `AccountingPeriod::containing()` and
 *    `AccountingPeriod::resolveAcademicYearId()` (both D2's contract on
 *    `App\Modules\Accounting\Models\AccountingPeriod`). The migration's
 *    BEFORE INSERT trigger re-derives the same three values independently
 *    and SIGNALs on any mismatch (C3) - a backstop against any OTHER write
 *    path (import, direct SQL, a future bug), not against this Action.
 *  - L2 is deliberately NOT checked here. `total_debit`/`total_credit` stay
 *    at 0/0 for the life of a draft (satisfying `ck_je_totals` trivially,
 *    since 0 = 0) precisely because a draft is allowed to be temporarily
 *    unbalanced while it is being built. Only `PostJournalEntry` computes
 *    and stamps the real sums, after asserting they are equal.
 *
 */
final class DraftJournalEntry
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array<int, array{account_id: int, label: string, debit: int, credit: int, partner_type?: string|null, partner_id?: int|null, tax_code_id?: int|null, tax_base_amount?: int|null, due_date?: string|null, source_line_type?: string|null, source_line_id?: int|null}>  $lines
     */
    public function handle(
        int $journalId,
        string $date,
        ?string $valueDate,
        string $label,
        ?string $reference,
        array $lines,
        Actor $actor,
    ): JournalEntry {
        Gate::authorize(Permission::LedgerPost->value);

        $accountingDate = Carbon::parse($date)->startOfDay();
        $economicDate = $valueDate === null ? $accountingDate->copy() : Carbon::parse($valueDate)->startOfDay();

        return DB::transaction(function () use (
            $journalId, $accountingDate, $economicDate, $label, $reference, $lines, $actor
        ): JournalEntry {
            $period = AccountingPeriod::containing($accountingDate);

            if ($period === null) {
                throw new DomainException(
                    "No accounting period covers {$accountingDate->toDateString()}; the fiscal-year calendar is misconfigured."
                );
            }

            $academicYearId = AccountingPeriod::resolveAcademicYearId($accountingDate);

            $entry = JournalEntry::query()->create([
                'journal_id' => $journalId,
                'piece_no' => null,
                'date' => $accountingDate->toDateString(),
                'value_date' => $economicDate->toDateString(),
                'accounting_period_id' => $period->getKey(),
                'fiscal_year_id' => $period->fiscal_year_id,
                'academic_year_id' => $academicYearId,
                'label' => $label,
                'reference' => $reference,
                'status' => JournalEntry::STATUS_DRAFT,
                'total_debit' => 0,
                'total_credit' => 0,
                'created_by' => $actor->id,
            ]);

            $sequence = 1;

            foreach ($lines as $line) {
                $debit = (int) $line['debit'];
                $credit = (int) $line['credit'];

                // Defence in depth atop ck_jel_one_side: a clear domain
                // exception here beats surfacing a raw QueryException to the
                // caller for a mistake the Action can catch before it ever
                // reaches the database.
                if (($debit === 0) === ($credit === 0)) {
                    throw new DomainException(
                        "Journal entry line #{$sequence} must have exactly one of debit/credit non-zero (L1)."
                    );
                }

                $entry->lines()->create([
                    'sequence' => $sequence,
                    'account_id' => $line['account_id'],
                    'label' => $line['label'],
                    'debit' => $debit,
                    'credit' => $credit,
                    'partner_type' => $line['partner_type'] ?? null,
                    'partner_id' => $line['partner_id'] ?? null,
                    'tax_code_id' => $line['tax_code_id'] ?? null,
                    'tax_base_amount' => $line['tax_base_amount'] ?? null,
                    'due_date' => $line['due_date'] ?? null,
                    'source_line_type' => $line['source_line_type'] ?? null,
                    'source_line_id' => $line['source_line_id'] ?? null,
                ]);

                $sequence++;
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Accounting',
                auditableType: JournalEntry::class,
                auditableId: (int) $entry->getKey(),
                after: [
                    'journal_id' => $journalId,
                    'date' => $accountingDate->toDateString(),
                    'label' => $label,
                    'line_count' => count($lines),
                ],
                actor: $actor,
            );

            return $entry->fresh(['lines']) ?? $entry;
        });
    }
}

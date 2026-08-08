<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Appends a line to a `draft` JournalEntry. docs/specs/02-accounting.md
 * §4.2/§4.3 L3.
 *
 * The `journal_entry_lines` BEFORE INSERT trigger (L3's PRIMARY mechanism)
 * already refuses this against a posted/reversed parent regardless of what
 * this Action does. The `lockForUpdate()` below exists for a narrower
 * reason: it serialises against `PostJournalEntry`, which takes the SAME
 * exclusive lock on the parent row before it sums the lines and asserts L2.
 * Without it, a line could be inserted after PostJournalEntry has read the
 * lines but before it commits, landing after the trigger still saw
 * `status = 'draft'` yet outside the sum PostJournalEntry validated.
 */
final class AddJournalEntryLine
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(
        int $journalEntryId,
        int $accountId,
        string $label,
        int $debit,
        int $credit,
        Actor $actor,
        ?string $partnerType = null,
        ?int $partnerId = null,
        ?int $taxCodeId = null,
        ?int $taxBaseAmount = null,
        ?string $dueDate = null,
        ?string $sourceLineType = null,
        ?int $sourceLineId = null,
    ): JournalEntryLine {
        Gate::authorize(Permission::LedgerPost->value);

        if (($debit === 0) === ($credit === 0)) {
            throw new DomainException(
                'A journal entry line must have exactly one of debit/credit non-zero (L1).'
            );
        }

        return DB::transaction(function () use (
            $journalEntryId, $accountId, $label, $debit, $credit,
            $partnerType, $partnerId, $taxCodeId, $taxBaseAmount, $dueDate,
            $sourceLineType, $sourceLineId, $actor,
        ): JournalEntryLine {
            /** @var JournalEntry $entry */
            $entry = JournalEntry::query()->whereKey($journalEntryId)->lockForUpdate()->firstOrFail();

            if ($entry->status !== JournalEntry::STATUS_DRAFT) {
                throw new DomainException(
                    "Journal entry {$entry->getKey()} is {$entry->status}; lines may only be added to a draft (L3)."
                );
            }

            $nextSequence = 1 + (int) $entry->lines()->max('sequence');

            $line = $entry->lines()->create([
                'sequence' => $nextSequence,
                'account_id' => $accountId,
                'label' => $label,
                'debit' => $debit,
                'credit' => $credit,
                'partner_type' => $partnerType,
                'partner_id' => $partnerId,
                'tax_code_id' => $taxCodeId,
                'tax_base_amount' => $taxBaseAmount,
                'due_date' => $dueDate,
                'source_line_type' => $sourceLineType,
                'source_line_id' => $sourceLineId,
            ]);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: JournalEntry::class,
                auditableId: (int) $entry->getKey(),
                after: ['line_added' => $line->getKey(), 'debit' => $debit, 'credit' => $credit],
                actor: $actor,
            );

            return $line;
        });
    }
}

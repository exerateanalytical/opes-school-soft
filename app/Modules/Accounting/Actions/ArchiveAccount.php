<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Archives a chart-of-accounts row. An account is archived, never deleted
 * (00-core 10.5 - ChartOfAccount is RESTRICT-everywhere, never SoftDeletes).
 *
 * 02-accounting.md 2.1: "Archiving is refused if the account has a non-zero
 * balance in any unclosed fiscal year, or any line in the current or prior
 * fiscal year." That check needs `journal_entry_lines`, a table this phase
 * does not own and which does not exist on disk yet (a sibling agent creates
 * it). The check is written defensively against `Schema::hasTable()` so it
 * activates automatically once that table lands, instead of silently never
 * running. Until then, only the structural guard (no live children) applies.
 */
final class ArchiveAccount
{
    public const PERMISSION = CreateAccount::PERMISSION;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(ChartOfAccount $account, ?Actor $actor = null): ChartOfAccount
    {
        Gate::authorize(self::PERMISSION);

        if ($account->is_archived) {
            throw new DomainException(sprintf('Account %s is already archived.', $account->code));
        }

        $hasLiveChildren = ChartOfAccount::query()
            ->where('parent_id', $account->getKey())
            ->where('is_archived', false)
            ->exists();

        if ($hasLiveChildren) {
            throw new DomainException(sprintf(
                'Cannot archive account %s: it has at least one non-archived child.',
                $account->code,
            ));
        }

        // Handoff: JournalEntryLine does not exist yet. See class docblock.
        if (Schema::hasTable('journal_entry_lines')) {
            $hasLines = DB::table('journal_entry_lines')
                ->where('account_id', $account->getKey())
                ->exists();

            if ($hasLines) {
                throw new DomainException(sprintf(
                    'Cannot archive account %s: it has ledger lines. See 02-accounting.md 2.1 for the exact fiscal-year scoping rule.',
                    $account->code,
                ));
            }
        }

        return DB::transaction(function () use ($account, $actor): ChartOfAccount {
            $before = ['is_archived' => $account->is_archived, 'archived_at' => $account->archived_at];

            $account->forceFill([
                'is_archived' => true,
                'archived_at' => Carbon::now()->toDateString(),
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: ChartOfAccount::class,
                auditableId: (int) $account->getKey(),
                before: $before,
                after: ['is_archived' => true, 'archived_at' => $account->archived_at],
                actor: $actor ?? auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $account;
        });
    }
}

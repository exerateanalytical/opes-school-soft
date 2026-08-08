<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\JournalType;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 02-accounting §3. Two guards on top of the ordinary update:
 *
 * 1. `AN` and `CL` (`is_system = true`) are immutable through this Action -
 *    "only writable by the year-end Actions ... and the opening-balance
 *    import." A real block, not documentation: it throws regardless of the
 *    caller's permissions.
 * 2. The treasury-account CHECK is re-asserted here (belt) even though the
 *    database CHECK (braces, see the create-table migration) already
 *    enforces it, because a rejected save should surface a clear domain
 *    message instead of a raw SQLSTATE 23000 from a constraint the caller
 *    cannot see.
 *
 */
final class ConfigureJournal
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array{name?:string,name_fr?:string,type?:JournalType,default_debit_account_id?:int|null,default_credit_account_id?:int|null,treasury_account_id?:int|null,requires_maker_checker?:bool,piece_no_format?:string,is_active?:bool,is_archived?:bool}  $attributes
     */
    public function handle(int $journalId, array $attributes, Actor $actor): Journal
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($journalId, $attributes, $actor): Journal {
            /** @var Journal $journal */
            $journal = Journal::query()->lockForUpdate()->findOrFail($journalId);

            if ($journal->is_system) {
                throw new DomainException(sprintf(
                    "Journal %s is a system journal (is_system = true) and is not editable through ConfigureJournal - only the year-end Actions and the opening-balance import may write to 'AN'/'CL'.",
                    $journal->code,
                ));
            }

            $before = $journal->only([
                'name', 'name_fr', 'default_debit_account_id', 'default_credit_account_id',
                'treasury_account_id', 'requires_maker_checker', 'piece_no_format', 'is_active', 'is_archived',
            ]);
            $before['type'] = $journal->type->value;

            $type = array_key_exists('type', $attributes)
                ? $attributes['type']
                : $journal->type;

            $treasuryAccountId = array_key_exists('treasury_account_id', $attributes)
                ? $attributes['treasury_account_id']
                : $journal->treasury_account_id;

            $isActive = array_key_exists('is_active', $attributes)
                ? (bool) $attributes['is_active']
                : $journal->is_active;

            // Mirrors the database CHECK exactly, including the
            // `is_active = 0` escape used by the seeded-but-unconfigured
            // CA/BQ/MM journals - see the create-table migration.
            if ($isActive && $type->requiresTreasuryAccount() && $treasuryAccountId === null) {
                throw new DomainException(sprintf(
                    "Journal type '%s' requires a treasury_account_id before it can be active.",
                    $type->value,
                ));
            }

            $journal->fill($attributes);
            $journal->type = $type;
            $journal->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: Journal::class,
                auditableId: (int) $journal->getKey(),
                before: $before,
                after: [...$journal->only(array_diff(array_keys($before), ['type'])), 'type' => $journal->type->value],
                actor: $actor,
            );

            return $journal;
        });
    }
}

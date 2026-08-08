<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\AccountType;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Updates a non-structural field on a chart-of-accounts row.
 *
 * `code`, `parent_id` and `depth` are never in scope here - re-parenting or
 * re-coding is not an operation this product exposes to anyone; the
 * CoA-1/CoA-2 triggers would reject most attempts anyway. `is_system` is
 * accepted only implicitly - it is never in `$changes` because CoA-5's
 * BEFORE UPDATE trigger would SIGNAL on any attempt, per 02-accounting.md
 * 1.4/2.2.
 *
 * CoA-5 (system-account code/name/name_fr immutability) is checked HERE
 * too, not only left to the trigger, because a caller deserves a
 * DomainException with a clear message rather than a raw SQLSTATE 45000
 * bubbling out of a query exception. The trigger remains the proven
 * backstop - see ChartOfAccountTest's direct-SQL test for CoA-5.
 */
final class UpdateAccount
{
    public const PERMISSION = CreateAccount::PERMISSION;

    private const IMMUTABLE_ON_SYSTEM_ACCOUNTS = ['code', 'name', 'name_fr'];

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function handle(ChartOfAccount $account, array $changes, ?Actor $actor = null): ChartOfAccount
    {
        Gate::authorize(self::PERMISSION);

        unset($changes['code'], $changes['parent_id'], $changes['is_system'], $changes['account_class'], $changes['depth']);

        if ($account->is_system) {
            foreach (self::IMMUTABLE_ON_SYSTEM_ACCOUNTS as $field) {
                if (array_key_exists($field, $changes) && $changes[$field] !== $account->{$field}) {
                    throw new DomainException(sprintf(
                        "CoA-5: '%s' is immutable on a system account (code %s).",
                        $field,
                        $account->code,
                    ));
                }
            }
        }

        if (array_key_exists('requires_partner', $changes) || array_key_exists('is_collective', $changes)) {
            $requiresPartner = $changes['requires_partner'] ?? $account->requires_partner;
            $isCollective = $changes['is_collective'] ?? $account->is_collective;

            if ($requiresPartner && ! $isCollective) {
                throw new DomainException('An account cannot require a partner without being collective.');
            }
        }

        if (array_key_exists('type', $changes)) {
            $type = $changes['type'] instanceof AccountType ? $changes['type'] : AccountType::from((string) $changes['type']);

            if (! in_array($type, AccountType::allowedForClass($account->account_class), true)) {
                throw new DomainException(sprintf(
                    'CoA-6: type "%s" is not consistent with account_class %d.',
                    $type->value,
                    $account->account_class,
                ));
            }
        }

        return DB::transaction(function () use ($account, $changes, $actor): ChartOfAccount {
            $before = array_intersect_key($account->getOriginal(), $changes);

            $account->fill($changes);
            $after = $account->getDirty();
            $account->save();

            if ($after !== []) {
                $this->audit->handle(
                    action: AuditAction::Updated,
                    module: 'Accounting',
                    auditableType: ChartOfAccount::class,
                    auditableId: (int) $account->getKey(),
                    before: array_intersect_key($before, $after),
                    after: $after,
                    actor: $actor ?? auth()->user()?->toAuditActor() ?? Actor::system(),
                );
            }

            return $account;
        });
    }
}

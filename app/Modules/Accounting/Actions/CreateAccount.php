<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\AccountType;
use App\Modules\Accounting\Domain\BudgetControl;
use App\Modules\Accounting\Domain\DsfStatement;
use App\Modules\Accounting\Domain\NormalBalance;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Extends the chart of accounts under an existing parent (02-accounting.md
 * 1.4: schools extend the plan freely at 5+ digits under a system parent; in
 * practice this Action does not forbid extending under any postable-turned-
 * parent account, system or not).
 *
 * Every hierarchy invariant checked here (CoA-2 shape, CoA-4 postability
 * flip, CoA-6 type consistency) is a friendlier, earlier failure than the
 * database trigger / CHECK would give - but the triggers remain the proven
 * backstop (see 2026_08_07_230001's docblock and ChartOfAccountTest's
 * direct-SQL tests). This Action does not re-implement them for their own
 * sake; it implements CoA-6 specifically, because CoA-6 has NO trigger by
 * spec design (02-accounting.md 2.2: "validation Action at save").
 */
final class CreateAccount
{
    /**
     * Raw string, not App\Modules\Identity\Domain\Permission::LedgerPost:
     * that case governs posting entries into the ledger, a different
     * concern from administering the chart of accounts, and Identity's
     * Permission enum does not carry a dedicated case for this yet -
     * Identity's files are not this phase's to edit. Swap for the enum
     * value if/when `accounting.manage` is added there.
     */
    public const PERMISSION = 'accounting.manage';

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<string>|null  $allowedPartnerTypes
     */
    public function handle(
        int $parentId,
        string $code,
        string $name,
        string $nameFr,
        AccountType $type,
        NormalBalance $normalBalance,
        ?string $nameEn = null,
        ?string $displayAlias = null,
        bool $isCollective = false,
        bool $requiresPartner = false,
        ?array $allowedPartnerTypes = null,
        bool $requiresAnalytic = false,
        bool $isLettrable = false,
        bool $isReconcilable = false,
        ?string $dsfLineCode = null,
        ?DsfStatement $dsfStatement = null,
        ?int $defaultTaxCodeId = null,
        BudgetControl $budgetControl = BudgetControl::None,
        ?string $notes = null,
        ?Actor $actor = null,
    ): ChartOfAccount {
        Gate::authorize(self::PERMISSION);

        if (! preg_match('/^[0-9]{1,20}$/', $code)) {
            throw new DomainException(sprintf('Account code must be 1-20 digits: got "%s".', $code));
        }

        if ($requiresPartner && ! $isCollective) {
            throw new DomainException('An account cannot require a partner without being collective.');
        }

        $accountClass = (int) $code[0];

        if (! in_array($type, AccountType::allowedForClass($accountClass), true)) {
            throw new DomainException(sprintf(
                'CoA-6: type "%s" is not consistent with account_class %d.',
                $type->value,
                $accountClass,
            ));
        }

        return DB::transaction(function () use (
            $parentId, $code, $name, $nameFr, $type, $normalBalance, $nameEn, $displayAlias,
            $isCollective, $requiresPartner, $allowedPartnerTypes, $requiresAnalytic, $isLettrable,
            $isReconcilable, $dsfLineCode, $dsfStatement, $defaultTaxCodeId, $budgetControl, $notes, $actor
        ): ChartOfAccount {
            /** @var ChartOfAccount $parent */
            $parent = ChartOfAccount::query()->lockForUpdate()->findOrFail($parentId);

            if ($parent->is_archived) {
                throw new DomainException('Cannot create an account under an archived parent.');
            }

            if (! str_starts_with($code, $parent->code) || $code === $parent->code) {
                throw new DomainException('CoA-2: the new code must extend the parent code with a strict prefix.');
            }

            if (strlen($code) !== $parent->depth + 1) {
                throw new DomainException('CoA-2: the new code must be exactly one digit longer than its parent.');
            }

            // CoA-4: the parent must already be non-postable before its
            // first child is inserted - flip it here, under the lock, so
            // the trigger sees a consistent state.
            if ($parent->is_postable) {
                $parent->forceFill(['is_postable' => false])->save();
            }

            $account = ChartOfAccount::query()->create([
                'code' => $code,
                'parent_id' => $parent->getKey(),
                'name' => $name,
                'name_fr' => $nameFr,
                'name_en' => $nameEn,
                'display_alias' => $displayAlias,
                'type' => $type,
                'normal_balance' => $normalBalance,
                'is_postable' => true,
                'is_system' => false,
                'is_collective' => $isCollective,
                'requires_partner' => $requiresPartner,
                'allowed_partner_types' => $allowedPartnerTypes,
                'requires_analytic' => $requiresAnalytic,
                'is_lettrable' => $isLettrable,
                'is_reconcilable' => $isReconcilable,
                'dsf_line_code' => $dsfLineCode,
                'dsf_statement' => $dsfStatement,
                'default_tax_code_id' => $defaultTaxCodeId,
                'budget_control' => $budgetControl,
                'currency' => 'XAF',
                'is_archived' => false,
                'notes' => $notes,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Accounting',
                auditableType: ChartOfAccount::class,
                auditableId: (int) $account->getKey(),
                after: [
                    'code' => $code,
                    'parent_id' => $parent->getKey(),
                    'name' => $name,
                    'type' => $type->value,
                    'normal_balance' => $normalBalance->value,
                ],
                actor: $actor ?? auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $account;
        });
    }
}

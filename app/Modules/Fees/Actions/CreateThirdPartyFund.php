<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Domain\BeneficiaryType;
use App\Modules\Fees\Domain\RemittanceFrequency;
use App\Modules\Fees\Models\ThirdPartyFund;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 04-fees.md §2.3 (C5). The Action-level validator the spec names: the
 * liability account must be class 47 (`code LIKE '47%'`) - a CHECK cannot
 * subquery in MySQL 8. The exact 47x subdivision is the accountant's choice
 * in the setup wizard; nothing is seeded (§23 item 1).
 */
final class CreateThirdPartyFund
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(
        string $code,
        string $name,
        string $nameFr,
        BeneficiaryType $beneficiaryType,
        string $beneficiaryName,
        int $liabilityAccountId,
        RemittanceFrequency $remittanceFrequency,
        ?string $beneficiaryNiu = null,
        ?int $remittanceDueDay = null,
        ?Actor $actor = null,
    ): ThirdPartyFund {
        Gate::authorize(Permission::FeeConfigure->value);

        // Query builder, not an Accounting model import - ModuleBoundaryTest.
        $accountCode = DB::table('chart_of_accounts')->where('id', $liabilityAccountId)->value('code');

        if (! is_string($accountCode)) {
            throw new DomainException('The liability account does not exist.');
        }

        if (! str_starts_with($accountCode, '47')) {
            throw new DomainException(sprintf(
                'C5: a third-party fund must be held on a class 47 account (Débiteurs et créditeurs divers); %s is not one.',
                $accountCode,
            ));
        }

        if ($remittanceDueDay !== null && ($remittanceDueDay < 1 || $remittanceDueDay > 31)) {
            throw new DomainException('The remittance due day must be a day of the month (1-31).');
        }

        return DB::transaction(function () use (
            $code, $name, $nameFr, $beneficiaryType, $beneficiaryName,
            $liabilityAccountId, $remittanceFrequency, $beneficiaryNiu, $remittanceDueDay, $actor
        ): ThirdPartyFund {
            $fund = ThirdPartyFund::query()->create([
                'code' => $code,
                'name' => $name,
                'name_fr' => $nameFr,
                'beneficiary_type' => $beneficiaryType,
                'beneficiary_name' => $beneficiaryName,
                'beneficiary_niu' => $beneficiaryNiu,
                'liability_account_id' => $liabilityAccountId,
                'remittance_frequency' => $remittanceFrequency,
                'remittance_due_day' => $remittanceDueDay,
                'is_active' => true,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Fees',
                auditableType: ThirdPartyFund::class,
                auditableId: (int) $fund->getKey(),
                after: [
                    'code' => $code,
                    'beneficiary_type' => $beneficiaryType->value,
                    'liability_account_id' => $liabilityAccountId,
                ],
                actor: $actor ?? auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $fund;
        });
    }
}

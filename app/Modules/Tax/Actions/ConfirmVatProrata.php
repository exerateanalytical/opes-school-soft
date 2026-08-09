<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Tax\Models\VatProrata;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §5.4 - the accountant's confirmation
 * that makes a prorata usable for deduction. Until this runs,
 * ComputeLineTax refuses to split input VAT: an unconfirmed fraction
 * limiting the school's deductible VAT must never be applied silently.
 */
final class ConfirmVatProrata
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $prorataId, Actor $actor): VatProrata
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($prorataId, $actor): VatProrata {
            /** @var VatProrata $prorata */
            $prorata = VatProrata::query()->lockForUpdate()->findOrFail($prorataId);

            if ($prorata->isConfirmed()) {
                throw new DomainException('This prorata is already confirmed.');
            }

            $prorata->fill([
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Tax',
                auditableType: VatProrata::class,
                auditableId: (int) $prorata->getKey(),
                after: ['confirmed' => true, 'rate_bp' => $prorata->rate_bp],
                actor: $actor,
            );

            return $prorata->refresh();
        });
    }
}

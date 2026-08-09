<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Models\Supplier;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §3.1/§3.2 - create or update a supplier.
 *
 * Duplicate suppliers are the classic payables fraud vector (same vendor,
 * two records, one invoice paid twice), so the save runs two tiers:
 *
 *  - HARD BLOCK on an exact `niu` or bank/momo blind-index match. Override
 *    requires `procurement.supplier_override_duplicate` AND a stored
 *    reason, and the override itself is audited with the colliding ids.
 *  - SOFT MATCH on the normalised name (accent-stripped, case- and
 *    punctuation-blind). Blocks until the caller re-submits with
 *    `confirmSimilar` - a deliberate confirmation, not a permission.
 *
 * The payable account must be a COLLECTIVE account in the 401 or 481
 * family (§3.3): it is only a default - the per-document choice governs -
 * but a wrong default here would misfile every invoice captured in a hurry.
 */
final class SaveSupplier
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  column => value; bank/momo plaintext - blind indexes are derived HERE
     */
    public function handle(
        array $payload,
        Actor $actor,
        ?int $supplierId = null,
        bool $confirmSimilar = false,
        bool $overrideDuplicate = false,
        ?string $overrideReason = null,
    ): Supplier {
        Gate::authorize(ProcurementPermission::SUPPLIER_MANAGE);

        return DB::transaction(function () use (
            $payload, $actor, $supplierId, $confirmSimilar, $overrideDuplicate, $overrideReason,
        ): Supplier {
            // Blind indexes are derived server-side, never accepted from the
            // caller: a payload-supplied bidx could be forged to dodge the
            // duplicate check while storing a different account number.
            unset($payload['bank_account_rib_bidx'], $payload['mobile_money_number_bidx']);
            unset($payload['code'], $payload['created_by'], $payload['updated_by']);

            $niu = isset($payload['niu']) && is_string($payload['niu']) && trim($payload['niu']) !== ''
                ? trim($payload['niu'])
                : null;
            $payload['niu'] = $niu;

            $ribBidx = Supplier::blindIndexFor(
                isset($payload['bank_account_rib']) && is_string($payload['bank_account_rib']) ? $payload['bank_account_rib'] : null,
            );
            $momoBidx = Supplier::blindIndexFor(
                isset($payload['mobile_money_number']) && is_string($payload['mobile_money_number']) ? $payload['mobile_money_number'] : null,
            );
            $payload['bank_account_rib_bidx'] = $ribBidx;
            $payload['mobile_money_number_bidx'] = $momoBidx;

            $this->assertPayableAccountIsCollective($payload, $supplierId);

            $this->guardDuplicates(
                $niu, $ribBidx, $momoBidx,
                isset($payload['name']) && is_string($payload['name']) ? $payload['name'] : null,
                $supplierId, $confirmSimilar, $overrideDuplicate, $overrideReason, $actor,
            );

            if (isset($payload['is_withholding_exempt']) && (bool) $payload['is_withholding_exempt']
                && trim((string) ($payload['withholding_exemption_ref'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'withholding_exemption_ref' => 'A withholding exemption needs its certificate reference (03-tax-procurement 3.1).',
                ]);
            }

            if ($supplierId === null) {
                if (trim((string) ($payload['name'] ?? '')) === '') {
                    throw ValidationException::withMessages(['name' => 'A supplier needs a name.']);
                }

                // FRN/000123 - row-locked sequence, never max()+1 (00-core §12).
                $payload['code'] = sprintf('FRN/%06d', $this->sequences->allocate('FRN'));
                $payload['created_by'] = $actor->id;

                /** @var Supplier $supplier */
                $supplier = Supplier::query()->create($payload);

                $this->audit->handle(
                    action: AuditAction::Created,
                    module: 'Procurement',
                    auditableType: Supplier::class,
                    auditableId: (int) $supplier->getKey(),
                    after: [
                        'code' => $supplier->code,
                        'name' => $supplier->name,
                        'niu' => $supplier->niu,
                        // 00-core 9.5: encrypted-field VALUES never reach the
                        // audit log; the blind index proves what was set.
                        'bank_account_rib_bidx' => $ribBidx,
                        'duplicate_override' => $overrideDuplicate ? $overrideReason : null,
                    ],
                    actor: $actor,
                );

                // Refresh so DB defaults (niu_status, flags) are present on
                // the returned model, not just in the table.
                return $supplier->refresh();
            }

            /** @var Supplier $supplier */
            $supplier = Supplier::query()->findOrFail($supplierId);

            $before = ['name' => $supplier->name, 'niu' => $supplier->niu];
            $payload['updated_by'] = $actor->id;
            $supplier->fill($payload);
            $supplier->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: Supplier::class,
                auditableId: (int) $supplier->getKey(),
                before: $before,
                after: [
                    'name' => $supplier->name,
                    'niu' => $supplier->niu,
                    'duplicate_override' => $overrideDuplicate ? $overrideReason : null,
                ],
                actor: $actor,
            );

            return $supplier;
        });
    }

    /**
     * §3.2 - the two tiers. Exact identifier collisions hard-block;
     * normalised-name collisions ask for confirmation.
     */
    private function guardDuplicates(
        ?string $niu,
        ?string $ribBidx,
        ?string $momoBidx,
        ?string $name,
        ?int $excludeId,
        bool $confirmSimilar,
        bool $overrideDuplicate,
        ?string $overrideReason,
        Actor $actor,
    ): void {
        $exact = Supplier::query()
            ->when($excludeId !== null, fn ($q) => $q->whereKeyNot($excludeId))
            ->where(function ($query) use ($niu, $ribBidx, $momoBidx): void {
                $query->when($niu !== null, fn ($q) => $q->orWhere('niu', $niu))
                    ->when($ribBidx !== null, fn ($q) => $q->orWhere('bank_account_rib_bidx', $ribBidx))
                    ->when($momoBidx !== null, fn ($q) => $q->orWhere('mobile_money_number_bidx', $momoBidx));
            });

        $exactMatches = ($niu !== null || $ribBidx !== null || $momoBidx !== null)
            ? $exact->get(['id', 'code', 'name'])
            : collect();

        if ($exactMatches->isNotEmpty()) {
            if (! $overrideDuplicate) {
                throw ValidationException::withMessages([
                    'niu' => sprintf(
                        'Exact duplicate of supplier %s (matching NIU or bank/mobile-money account). '
                        .'Overriding requires the duplicate-override permission and a reason (03-tax-procurement 3.2).',
                        $exactMatches->pluck('code')->implode(', '),
                    ),
                ]);
            }

            Gate::authorize(ProcurementPermission::SUPPLIER_OVERRIDE_DUPLICATE);

            if (trim((string) $overrideReason) === '') {
                throw ValidationException::withMessages([
                    'override_reason' => 'A duplicate override must state its reason (03-tax-procurement 3.2).',
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: Supplier::class,
                auditableId: $excludeId,
                after: [
                    'duplicate_override_reason' => $overrideReason,
                    'colliding_supplier_ids' => $exactMatches->pluck('id')->all(),
                ],
                actor: $actor,
            );

            return; // Overridden - the soft tier is subsumed.
        }

        if ($name === null || $confirmSimilar) {
            return;
        }

        $normalised = self::normaliseName($name);

        if ($normalised === '') {
            return;
        }

        $similar = Supplier::query()
            ->when($excludeId !== null, fn ($q) => $q->whereKeyNot($excludeId))
            ->get(['id', 'code', 'name'])
            ->filter(fn (Supplier $s): bool => self::normaliseName($s->name) === $normalised);

        if ($similar->isNotEmpty()) {
            throw ValidationException::withMessages([
                'name' => sprintf(
                    'Possible duplicate of %s - same name after normalisation. Re-submit with confirmation if this is a distinct supplier (03-tax-procurement 3.2).',
                    $similar->pluck('code')->implode(', '),
                ),
            ]);
        }
    }

    /** Accent-stripped, case- and punctuation-blind (§3.2). */
    public static function normaliseName(string $name): string
    {
        $ascii = Str::ascii($name);

        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $ascii));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPayableAccountIsCollective(array $payload, ?int $supplierId): void
    {
        if (! isset($payload['payable_account_id'])) {
            if ($supplierId === null) {
                throw ValidationException::withMessages([
                    'payable_account_id' => 'A supplier needs its collective payable account (401 operating / 481 investment).',
                ]);
            }

            return;
        }

        /** @var object{code: string, is_collective: int|bool}|null $account */
        $account = DB::table('chart_of_accounts')
            ->where('id', (int) $payload['payable_account_id'])
            ->first(['code', 'is_collective']);

        $family = $account !== null
            && (str_starts_with($account->code, '401') || str_starts_with($account->code, '48'));

        if ($account === null || ! (bool) $account->is_collective || ! $family) {
            throw ValidationException::withMessages([
                'payable_account_id' => 'The payable account must be a collective 401- or 481-family account (03-tax-procurement 3.3).',
            ]);
        }
    }
}

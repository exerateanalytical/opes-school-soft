<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Fees\Actions\CreateSupplementaryInvoice;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Domain\FineStatus;
use App\Modules\Library\Domain\FineType;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Domain\SettlementRoute;
use App\Modules\Library\Models\LibraryFine;
use App\Modules\Library\Models\LibraryMember;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §10.6/§10.7 - LEVY a fine, which is where the money
 * routes split by the SNAPSHOTTED settlement route:
 *
 *  - STUDENT: the receivable is recognised at levy through the Fees door
 *    (`CreateSupplementaryInvoice` → `fee.invoice.issued`, Dr 4111 / Cr
 *    the fee item's income account). EXACTLY ONE student debt stream: the
 *    fine row stays as the assessment record, the debt lives in Fees
 *    through `invoice_id`. Which income account (707x vs 758x - V14) is
 *    the accountant's FeeItem configuration, never a code default: the
 *    Action refuses when the fee item is absent or unconfigured.
 *  - STAFF: NOT a receivable - a payroll deduction (05-hr-payroll).
 *    Nothing posts at levy; the fine stays `assessed`, queued as a
 *    payroll input (payroll_deduction_id is filled by Phase 11).
 *  - EXTERNAL (cash_immediate): HARD-GATED - the 571x Caisse subdivision
 *    is NEEDS VERIFICATION (V13), so immediate cash collection refuses,
 *    naming the item.
 *
 * Takes either an existing assessed fine (`fine_id`, e.g. the nightly
 * overdue assessment) or creates an ad-hoc damage/other fine.
 */
final class LevyFine
{
    public function __construct(
        private readonly CreateSupplementaryInvoice $invoice,
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     fine_id?: int|null,
     *     library_member_id?: int|null,
     *     fine_type?: string,
     *     amount?: int,
     *     library_issue_id?: int|null,
     *     assessed_on?: string|null,
     *     fee_item_id?: int|null,
     *     fiscal_year_id?: int|null,
     *     due_date?: string|null,
     *     idempotency_key?: string|null,
     * } $data
     */
    public function handle(array $data, Actor $actor): LibraryFine
    {
        Gate::authorize(LibraryPermission::MANAGE);

        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            /** @var LibraryFine|null $existing */
            $existing = LibraryFine::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($data, $actor, $idempotencyKey): LibraryFine {
            $fine = ($data['fine_id'] ?? null) !== null
                ? $this->lockExisting((int) $data['fine_id'])
                : $this->createAdHoc($data, $actor, $idempotencyKey);

            /** @var LibraryMember $member */
            $member = LibraryMember::query()->findOrFail($fine->library_member_id);

            $fine->forceFill(['levied_by' => $actor->id])->save();

            match ($fine->settlement_route) {
                SettlementRoute::StudentReceivable => $this->invoiceStudent($fine, $member, $data, $actor),
                SettlementRoute::StaffPayrollDeduction => null, // queued for Phase 11 payroll; posts nothing at levy
                SettlementRoute::CashImmediate => throw new DomainException(
                    'Immediate cash collection of an external member\'s fine is blocked: the 571x '
                    .'Caisse treasury subdivision is NEEDS VERIFICATION (06-assets-stores V13); the '
                    .'accountant must confirm it first.'
                ),
            };

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Library',
                auditableType: LibraryFine::class,
                auditableId: (int) $fine->getKey(),
                after: [
                    'fine_no' => $fine->fine_no,
                    'amount' => $fine->amount,
                    'settlement_route' => $fine->settlement_route->value,
                    'invoice_id' => $fine->invoice_id,
                ],
                actor: $actor,
            );

            return $fine->refresh();
        });
    }

    private function lockExisting(int $fineId): LibraryFine
    {
        /** @var LibraryFine $fine */
        $fine = LibraryFine::query()->lockForUpdate()->findOrFail($fineId);

        if ($fine->status !== FineStatus::Assessed) {
            throw new DomainException(
                "Fine {$fine->fine_no} is {$fine->status->value}; only an assessed fine can be levied."
            );
        }

        return $fine;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createAdHoc(array $data, Actor $actor, ?string $idempotencyKey): LibraryFine
    {
        $memberId = $data['library_member_id'] ?? null;

        if ($memberId === null) {
            throw new DomainException('Name the member (or an existing fine) to levy against.');
        }

        $amount = (int) ($data['amount'] ?? 0);

        if ($amount <= 0) {
            throw new DomainException('An ad-hoc fine needs a positive amount.');
        }

        $type = FineType::from((string) ($data['fine_type'] ?? FineType::Damage->value));

        if ($type === FineType::Overdue) {
            throw new DomainException(
                'Overdue fines are assessed by the nightly accrual (idempotent entitlement, §10.5), never levied ad hoc.'
            );
        }

        /** @var LibraryMember $member */
        $member = LibraryMember::query()->lockForUpdate()->findOrFail((int) $memberId);

        $assessedOn = (string) ($data['assessed_on'] ?? Carbon::now()->toDateString());

        /** @var LibraryFine $fine */
        $fine = LibraryFine::query()->create([
            'fine_no' => sprintf(
                'FIN/%s/%06d',
                Carbon::parse($assessedOn)->format('Y'),
                $this->sequence->allocate('library.fine_no'),
            ),
            'library_issue_id' => $data['library_issue_id'] ?? null,
            'library_member_id' => (int) $member->getKey(),
            'student_id' => $member->student_id,
            'fine_type' => $type,
            'assessed_on' => $assessedOn,
            'days_overdue' => null,
            'amount' => $amount,
            'status' => FineStatus::Assessed,
            'settlement_route' => $member->member_type->settlementRoute(),
            'idempotency_key' => $idempotencyKey,
        ]);

        return $fine;
    }

    /**
     * §10.7 - the ONE debt stream: the fine becomes an InvoiceLine through
     * the Fees door, against an own-revenue FeeItem mapped to the library
     * income account.
     *
     * @param  array<string, mixed>  $data
     */
    private function invoiceStudent(LibraryFine $fine, LibraryMember $member, array $data, Actor $actor): void
    {
        $feeItemId = $data['fee_item_id'] ?? null;

        if ($feeItemId === null) {
            throw new DomainException(
                'Levying a student fine needs the library-fine FeeItem: the income account (707x vs '
                .'758x) is NEEDS VERIFICATION (06-assets-stores V14) and must be the accountant\'s '
                .'FeeItem configuration, never a code default.'
            );
        }

        /** @var object{id: int|string, collection_basis: string, revenue_account_id: int|string|null, is_archived: int}|null $feeItem */
        $feeItem = DB::table('fee_items')
            ->where('id', (int) $feeItemId)
            ->first(['id', 'collection_basis', 'revenue_account_id', 'is_archived']);

        if ($feeItem === null || (bool) $feeItem->is_archived) {
            throw new DomainException('The library-fine FeeItem does not exist or is archived.');
        }

        if ($feeItem->collection_basis !== 'own_revenue' || $feeItem->revenue_account_id === null) {
            throw new DomainException(
                'The library-fine FeeItem must be own_revenue with a configured income account (§10.7; V14).'
            );
        }

        if (($data['fiscal_year_id'] ?? null) === null) {
            throw new DomainException('Invoicing a fine needs the open fiscal year.');
        }

        if ($member->enrollment_id === null) {
            throw new DomainException(
                'The student membership carries no enrollment; the fine cannot join the debt stream.'
            );
        }

        $net = $fine->amount - $fine->waived_amount;

        $result = $this->invoice->handle([
            'enrollment_id' => $member->enrollment_id,
            'academic_year_id' => $member->academic_year_id,
            'fiscal_year_id' => (int) $data['fiscal_year_id'],
            'issue_date' => $fine->assessed_on,
            'due_date' => (string) ($data['due_date'] ?? $fine->assessed_on),
            'lines' => [
                [
                    'description' => sprintf('Library fine %s (%s)', $fine->fine_no, $fine->fine_type->value),
                    'revenue_account_id' => (int) $feeItem->revenue_account_id,
                    'amount' => $net,
                    'fee_item_id' => (int) $feeItem->id,
                ],
            ],
            'idempotency_key' => 'library-fine:'.$fine->fine_no,
        ], $actor);

        $fine->forceFill([
            'status' => FineStatus::Invoiced,
            'invoice_id' => $result['invoice_id'],
            'journal_entry_id' => $result['journal_entry_id'],
        ])->save();
    }
}

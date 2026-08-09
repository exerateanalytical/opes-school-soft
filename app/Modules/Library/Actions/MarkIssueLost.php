<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Domain\BookCopyStatus;
use App\Modules\Library\Domain\FineStatus;
use App\Modules\Library\Domain\FineType;
use App\Modules\Library\Domain\IssueStatus;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Domain\ReturnCondition;
use App\Modules\Library\Models\BookCopy;
use App\Modules\Library\Models\LibraryFine;
use App\Modules\Library\Models\LibraryIssue;
use App\Modules\Library\Models\LibraryMember;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §10.5 "Loss" - the issue goes `lost`, the copy goes
 * `lost` (freeing `uq_open_issue`), and a LOSS fine of the book's
 * replacement cost (+ optional processing fee) is assessed against the
 * member. Levying that assessment into the student's invoice is LevyFine
 * (single debt stream, §10.7).
 *
 * Under the default `expensed` collection policy (§10.8) no carrying
 * amount exists, so nothing posts here; the `capitalised` write-off is
 * gated with the policy itself (V17).
 */
final class MarkIssueLost
{
    public function __construct(
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     issue_id: int,
     *     lost_on: string,
     *     processing_fee?: int,
     *     idempotency_key?: string|null,
     * } $data
     * @return array{issue: LibraryIssue, fine: LibraryFine}
     */
    public function handle(array $data, Actor $actor): array
    {
        Gate::authorize(LibraryPermission::CIRCULATE);

        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            /** @var LibraryFine|null $existingFine */
            $existingFine = LibraryFine::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existingFine !== null) {
                /** @var LibraryIssue $existingIssue */
                $existingIssue = LibraryIssue::query()->findOrFail($existingFine->library_issue_id);

                return ['issue' => $existingIssue, 'fine' => $existingFine];
            }
        }

        $processingFee = (int) ($data['processing_fee'] ?? 0);

        if ($processingFee < 0) {
            throw new DomainException('A processing fee cannot be negative.');
        }

        return DB::transaction(function () use ($data, $actor, $idempotencyKey, $processingFee): array {
            /** @var LibraryIssue $issue */
            $issue = LibraryIssue::query()->lockForUpdate()->findOrFail($data['issue_id']);

            if (! in_array($issue->status, [IssueStatus::Open, IssueStatus::Overdue], true)) {
                throw new DomainException(
                    "Issue {$issue->issue_no} is {$issue->status->value}; only an open loan can be declared lost."
                );
            }

            /** @var BookCopy $copy */
            $copy = BookCopy::query()->lockForUpdate()->findOrFail($issue->book_copy_id);

            /** @var LibraryMember $member */
            $member = LibraryMember::query()->lockForUpdate()->findOrFail($issue->library_member_id);

            $replacementCost = (int) DB::table('books')
                ->where('id', $copy->book_id)
                ->value('replacement_cost');

            if ($replacementCost <= 0) {
                throw new DomainException(
                    'The book has no replacement cost on record; set it before declaring the copy lost (§10.5).'
                );
            }

            $issue->forceFill([
                'status' => IssueStatus::Lost,
                'return_condition' => ReturnCondition::Lost,
                'returned_on' => $data['lost_on'],
                'received_by' => $actor->id,
            ])->save();

            $copy->forceFill(['status' => BookCopyStatus::Lost])->save();

            /** @var LibraryFine $fine */
            $fine = LibraryFine::query()->create([
                'fine_no' => sprintf(
                    'FIN/%s/%06d',
                    substr($data['lost_on'], 0, 4),
                    $this->sequence->allocate('library.fine_no'),
                ),
                'library_issue_id' => (int) $issue->getKey(),
                'library_member_id' => (int) $member->getKey(),
                'student_id' => $member->student_id,
                'fine_type' => FineType::Loss,
                'assessed_on' => $data['lost_on'],
                'days_overdue' => null,
                'amount' => $replacementCost + $processingFee,
                'status' => FineStatus::Assessed,
                'settlement_route' => $member->member_type->settlementRoute(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Library',
                auditableType: LibraryIssue::class,
                auditableId: (int) $issue->getKey(),
                before: ['status' => 'open'],
                after: [
                    'status' => 'lost',
                    'fine_no' => $fine->fine_no,
                    'amount' => $fine->amount,
                ],
                actor: $actor,
            );

            return ['issue' => $issue->refresh(), 'fine' => $fine];
        });
    }
}

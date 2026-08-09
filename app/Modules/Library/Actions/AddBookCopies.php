<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Domain\BookCondition;
use App\Modules\Library\Domain\BookCopyStatus;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Models\Book;
use App\Modules\Library\Models\BookCopy;
use App\Modules\Library\Models\ShelfLocation;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §10.2 - stamp physical copies of a title into
 * existence. Accession numbers come from the row-locked sequence
 * (00-core §12 - never max()+1); the barcode defaults to the accession
 * number, which is what the printed label encodes.
 */
final class AddBookCopies
{
    public function __construct(
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     book_id: int,
     *     shelf_location_id: int,
     *     count: int,
     *     condition?: string,
     *     acquisition_id?: int|null,
     *     acquired_on?: string|null,
     *     unit_cost?: int,
     * } $data
     * @return list<BookCopy>
     */
    public function handle(array $data, Actor $actor): array
    {
        Gate::authorize(LibraryPermission::MANAGE);

        if ($data['count'] < 1) {
            throw new DomainException('At least one copy must be added.');
        }

        if (($data['unit_cost'] ?? 0) < 0) {
            throw new DomainException('A copy cost cannot be negative.');
        }

        return DB::transaction(function () use ($data, $actor): array {
            /** @var Book $book */
            $book = Book::query()->lockForUpdate()->findOrFail($data['book_id']);

            if ($book->is_archived) {
                throw new DomainException("Book '{$book->title}' is archived; copies cannot be added.");
            }

            $shelf = ShelfLocation::query()->find($data['shelf_location_id']);

            if ($shelf === null) {
                throw new DomainException('The shelf location does not exist.');
            }

            $condition = BookCondition::from($data['condition'] ?? BookCondition::Good->value);
            $first = $this->sequence->allocate('library.accession_no', $data['count']);

            $copies = [];

            for ($i = 0; $i < $data['count']; $i++) {
                $accession = sprintf('ACC%06d', $first + $i);

                $copies[] = BookCopy::query()->create([
                    'book_id' => (int) $book->getKey(),
                    'accession_no' => $accession,
                    'barcode' => $accession,
                    'shelf_location_id' => (int) $shelf->getKey(),
                    'acquisition_id' => $data['acquisition_id'] ?? null,
                    'acquired_on' => $data['acquired_on'] ?? null,
                    'acquisition_cost' => $data['unit_cost'] ?? 0,
                    'condition' => $condition,
                    'status' => BookCopyStatus::Available,
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Library',
                auditableType: Book::class,
                auditableId: (int) $book->getKey(),
                after: [
                    'copies_added' => $data['count'],
                    'first_accession_no' => $copies[0]->accession_no,
                ],
                actor: $actor,
            );

            return $copies;
        });
    }
}

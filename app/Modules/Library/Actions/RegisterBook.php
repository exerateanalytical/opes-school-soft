<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Models\Book;
use App\Modules\Library\Models\BookCategory;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 06-assets-stores.md §10.1 - create or update the TITLE record. Copies
 * are added separately (AddBookCopies / RecordBookAcquisition); a title
 * with zero copies is a legitimate catalogue state.
 */
final class RegisterBook
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?int $bookId, array $data, Actor $actor): Book
    {
        Gate::authorize(LibraryPermission::MANAGE);

        return DB::transaction(function () use ($bookId, $data, $actor): Book {
            $existing = null;

            if ($bookId !== null) {
                /** @var Book $existing */
                $existing = Book::query()->lockForUpdate()->findOrFail($bookId);
            }

            $merged = $existing !== null
                ? [...$existing->only([
                    'isbn', 'title', 'author', 'book_category_id', 'replacement_cost',
                ]), ...$data]
                : $data;

            foreach (['title', 'author'] as $field) {
                if (trim((string) ($merged[$field] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        $field => 'A book needs a title and an author.',
                    ]);
                }
            }

            if ((int) ($merged['replacement_cost'] ?? 0) < 0) {
                throw ValidationException::withMessages([
                    'replacement_cost' => 'The replacement cost cannot be negative.',
                ]);
            }

            $categoryId = (int) ($merged['book_category_id'] ?? 0);
            $category = BookCategory::query()->find($categoryId);

            if ($category === null || $category->is_archived) {
                throw new DomainException(
                    'A book must belong to an active book category.'
                );
            }

            if ($existing !== null) {
                $existing->fill($data)->save();
                $book = $existing;
                $auditAction = AuditAction::Updated;
            } else {
                /** @var Book $book */
                $book = Book::query()->create([...$data, 'created_by' => $actor->id]);
                $auditAction = AuditAction::Created;
            }

            $this->audit->handle(
                action: $auditAction,
                module: 'Library',
                auditableType: Book::class,
                auditableId: (int) $book->getKey(),
                after: [
                    'title' => $book->title,
                    'isbn' => $book->isbn,
                    'book_category_id' => $book->book_category_id,
                ],
                actor: $actor,
            );

            return $book->refresh();
        });
    }
}

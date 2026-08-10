<?php

declare(strict_types=1);

namespace App\Modules\Library\Livewire;

use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Models\Book;
use App\Modules\Reporting\Support\PdfExport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * Book detail page at /library/books/{book}, gated `library.view`, modeled
 * on Students\Livewire\Students\Show and Assets\Livewire\Show: title/author/
 * category summary, every physical copy with its own status, circulation
 * history for the title, and a printable book label/card.
 *
 * Same-module DB::table reads only (ModuleBoundaryTest); the Book model
 * itself is the one Eloquent read (own module, so allowed), everything else
 * is a bounded query builder read - no unbounded collection reaches the view
 * (00-core 6.2 rule 8).
 */
#[Layout('layouts.app')]
final class BookShow extends Component
{
    /** Cap per history list. */
    private const int HISTORY_LIMIT = 100;

    public Book $book;

    public function mount(Book $book): void
    {
        Gate::authorize(LibraryPermission::VIEW);

        $this->book = $book;
    }

    private function categoryName(): string
    {
        $name = DB::table('book_categories')->where('id', $this->book->book_category_id)->value('name');

        return is_string($name) ? $name : '—';
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function copies(): Collection
    {
        return DB::table('book_copies as bcp')
            ->leftJoin('shelf_locations as sl', 'sl.id', '=', 'bcp.shelf_location_id')
            ->where('bcp.book_id', $this->book->getKey())
            ->orderBy('bcp.accession_no')
            ->limit(self::HISTORY_LIMIT)
            ->select([
                'bcp.id', 'bcp.accession_no', 'bcp.barcode', 'bcp.condition', 'bcp.status',
                'bcp.acquired_on', 'sl.code as shelf_code', 'sl.name as shelf_name',
            ])
            ->get();
    }

    /**
     * Circulation history across every copy of this title.
     *
     * @return Collection<int, \stdClass>
     */
    private function circulationHistory(): Collection
    {
        return DB::table('library_issues as li')
            ->join('book_copies as bcp', 'bcp.id', '=', 'li.book_copy_id')
            ->join('library_members as lm', 'lm.id', '=', 'li.library_member_id')
            ->where('bcp.book_id', $this->book->getKey())
            ->orderByDesc('li.issued_on')
            ->orderByDesc('li.id')
            ->limit(self::HISTORY_LIMIT)
            ->select([
                'li.id', 'li.issue_no', 'bcp.accession_no', 'lm.member_no', 'lm.external_name',
                'li.issued_on', 'li.due_on', 'li.returned_on', 'li.status',
            ])
            ->get();
    }

    private function copiesTotal(): int
    {
        return (int) DB::table('book_copies')->where('book_id', $this->book->getKey())->count();
    }

    private function copiesAvailable(): int
    {
        return (int) DB::table('book_copies')
            ->where('book_id', $this->book->getKey())
            ->where('status', 'available')
            ->count();
    }

    // ── Export ────────────────────────────────────────────────────────────

    public function exportBookLabelPdf(): Response
    {
        Gate::authorize(LibraryPermission::VIEW);

        return PdfExport::download(
            'Book Label — '.$this->book->title,
            ['Field', 'Value'],
            $this->bookLabelRows(),
            'book-label-'.$this->book->getKey().'.pdf',
        );
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function bookLabelRows(): iterable
    {
        yield ['Title', $this->book->title];
        yield ['Author', $this->book->author];
        yield ['Category', $this->categoryName()];
        yield ['ISBN', $this->book->isbn ?? '—'];

        foreach ($this->copies() as $copy) {
            /** @var \stdClass $copy */
            yield ['Accession No.', (string) $copy->accession_no];
        }
    }

    public function render(): mixed
    {
        return view('livewire.library.book-show', [
            'categoryName' => $this->categoryName(),
            'copies' => $this->copies(),
            'circulationHistory' => $this->circulationHistory(),
            'copiesTotal' => $this->copiesTotal(),
            'copiesAvailable' => $this->copiesAvailable(),
        ]);
    }
}

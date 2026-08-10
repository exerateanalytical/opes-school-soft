<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Livewire\Books;

use App\Modules\Accounting\Actions\Books\GenerateStatutoryBook;
use App\Modules\Accounting\Domain\StatutoryBookType;
use App\Modules\Accounting\Models\StatutoryBook;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The statutory books register (02-accounting §14).
 *
 * Read-and-generate only: there is deliberately no edit and no delete. A book
 * is a legal register, and a correction is made by generating a new one that
 * supersedes its predecessor - which the table below shows explicitly, so an
 * operator can see the version chain rather than wonder why a book "changed".
 */
final class Index extends Component
{
    public string $fiscalYearId = '';

    public string $bookType = 'livre_journal';

    public string $message = '';

    public string $error = '';

    public function mount(): void
    {
        if ($this->fiscalYearId === '') {
            $id = DB::table('fiscal_years')->orderByDesc('id')->value('id');
            $this->fiscalYearId = $id === null ? '' : (string) $id;
        }
    }

    public function generate(): void
    {
        $this->message = '';
        $this->error = '';

        $year = DB::table('fiscal_years')->where('id', (int) $this->fiscalYearId)->first();

        if ($year === null) {
            $this->error = __('opes.books_screen.select_year');

            return;
        }

        try {
            $book = app(GenerateStatutoryBook::class)->handle(
                StatutoryBookType::from($this->bookType),
                (int) $this->fiscalYearId,
                (string) $year->starts_on,
                (string) $year->ends_on,
            );

            $this->message = sprintf(
                '%s — %s lines, sha256 %s',
                $book->book_type->label(),
                number_format($book->line_count),
                substr($book->sha256, 0, 12),
            );
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.accounting.books.index', [
            'fiscalYears' => DB::table('fiscal_years')->orderByDesc('id')->get(),
            'bookTypes' => StatutoryBookType::cases(),
            'books' => StatutoryBook::query()
                ->when($this->fiscalYearId !== '', fn ($q) => $q->where('fiscal_year_id', (int) $this->fiscalYearId))
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }
}

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
    /**
     * How many hex characters of the sha256 the screen shows. ONE constant:
     * the generate() banner and the register column show a prefix of the SAME
     * hash, and two different lengths read as two different hashes.
     */
    public const HASH_PREFIX = 16;

    /** Newest generations listed. The register states this cap on screen. */
    public const LIST_LIMIT = 50;

    public string $fiscalYearId = '';

    public string $bookType = StatutoryBookType::LivreJournal->value;

    public string $message = '';

    public string $error = '';

    public function mount(): void
    {
        if ($this->fiscalYearId === '') {
            $this->fiscalYearId = (string) ($this->defaultFiscalYearId() ?? '');
        }
    }

    /**
     * The year an operator is actually working in: the open one, newest first
     * by the date it starts on. Falls back to the newest year of any status.
     *
     * Deliberately NOT `orderByDesc('id')` - insertion order is not chronology,
     * and a back-filled prior year would otherwise become the default.
     */
    private function defaultFiscalYearId(): ?int
    {
        $open = DB::table('fiscal_years')->where('status', 'open')->orderByDesc('starts_on')->value('id');

        $id = $open ?? DB::table('fiscal_years')->orderByDesc('starts_on')->value('id');

        return $id === null ? null : (int) $id;
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

            $this->message = (string) __('opes.books_screen.generated_ok', [
                'book' => $book->book_type->label(),
                'lines' => number_format($book->line_count),
                'hash' => substr($book->sha256, 0, self::HASH_PREFIX),
            ]);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $scoped = fn () => StatutoryBook::query()
            ->when($this->fiscalYearId !== '', fn ($q) => $q->where('fiscal_year_id', (int) $this->fiscalYearId));

        return view('livewire.accounting.books.index', [
            'fiscalYears' => DB::table('fiscal_years')->orderByDesc('starts_on')->get(),
            'bookTypes' => StatutoryBookType::cases(),
            'books' => $scoped()->orderByDesc('id')->limit(self::LIST_LIMIT)->get(),
            // The register is capped. The screen says so rather than letting a
            // truncated list read as the whole legal register.
            'bookTotal' => $scoped()->count(),
            'listLimit' => self::LIST_LIMIT,
            'hashPrefix' => self::HASH_PREFIX,
        ]);
    }
}

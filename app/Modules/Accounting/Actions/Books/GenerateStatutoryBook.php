<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Books;

use App\Modules\Accounting\Domain\StatutoryBookType;
use App\Modules\Accounting\Models\StatutoryBook;
use App\Modules\Identity\Domain\Permission;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * docs/specs/02-accounting.md §14.1 - renders a statutory book to PDF, hashes
 * it, records it, and points the new row at whatever it supersedes.
 *
 * Nothing here UPDATEs or DELETEs. A regeneration after a correction produces
 * a NEW row referencing the old one, so the sequence of versions is itself
 * auditable and the earlier book survives exactly as it was signed. That is
 * the property that makes these legal registers rather than reports.
 *
 * The school name and cote-et-paraphe reference are read from the settings
 * registry with fallbacks rather than from a `school_profiles` table: this
 * product keeps school configuration as key/value settings, and an
 * unconfigured demo box must still be able to produce a book.
 */
final class GenerateStatutoryBook
{
    public function __construct(
        private readonly BuildLivreJournal $livreJournal,
        private readonly BuildGrandLivre $grandLivre,
        private readonly BuildBalanceGenerale $balanceGenerale,
        private readonly ReadSetting $settings,
    ) {}

    public function handle(
        StatutoryBookType $type,
        int $fiscalYearId,
        string $periodStart,
        string $periodEnd,
    ): StatutoryBook {
        Gate::authorize(Permission::LedgerView->value);

        $user = Auth::user();

        if ($user === null) {
            throw new DomainException('Generating a statutory book is an audited act; it needs a user.');
        }

        $fiscalYear = DB::table('fiscal_years')->where('id', $fiscalYearId)->first();

        if ($fiscalYear === null) {
            throw new DomainException("Fiscal year {$fiscalYearId} does not exist.");
        }

        [$headers, $rows, $stats] = $this->content($type, $fiscalYearId, $periodStart, $periodEnd);

        $generatedAt = now();

        $pdf = Pdf::loadView('reports.statutory-book', [
            'schoolName' => (string) $this->settings->handle('school.name', 'School'),
            'bookLabel' => $type->label(),
            'fiscalYearCode' => (string) $fiscalYear->code,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'generatedAt' => $generatedAt->format('Y-m-d H:i'),
            'generatedBy' => (string) $user->name,
            'coteParaphe' => (string) $this->settings->handle('accounting.books_cote_paraphe_reference', ''),
            'headers' => $headers,
            'rows' => $rows,
        ])->setPaper('a4', 'landscape');

        $binary = $pdf->output();
        $sha256 = hash('sha256', $binary);

        $path = sprintf(
            'statutory-books/%s-%s-%s.pdf',
            $type->value,
            (string) $fiscalYear->code,
            $generatedAt->format('YmdHis'),
        );

        Storage::disk('local')->put($path, $binary);

        return DB::transaction(function () use (
            $type, $fiscalYearId, $periodStart, $periodEnd,
            $generatedAt, $user, $path, $sha256, $stats
        ): StatutoryBook {
            $previous = StatutoryBook::query()
                ->where('book_type', $type->value)
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            return StatutoryBook::query()->create([
                'book_type' => $type->value,
                'fiscal_year_id' => $fiscalYearId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'generated_at' => $generatedAt,
                'generated_by' => (int) $user->getKey(),
                'page_count' => 0,
                'first_piece_no' => $stats['first_piece_no'],
                'last_piece_no' => $stats['last_piece_no'],
                'total_debit' => $stats['total_debit'],
                'total_credit' => $stats['total_credit'],
                'entry_count' => $stats['entry_count'],
                'line_count' => $stats['line_count'],
                'file_path' => $path,
                'sha256' => $sha256,
                'signature' => null,
                'supersedes_book_id' => $previous?->getKey(),
                'is_definitive' => false,
            ]);
        });
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>, 2: array<string, mixed>}
     */
    private function content(StatutoryBookType $type, int $fiscalYearId, string $start, string $end): array
    {
        return match ($type) {
            StatutoryBookType::LivreJournal => $this->livreJournalContent($fiscalYearId, $start, $end),
            StatutoryBookType::BalanceGenerale => $this->balanceGeneraleContent($fiscalYearId, $start, $end),
            StatutoryBookType::GrandLivre => $this->grandLivreContent($fiscalYearId, $start, $end),
            StatutoryBookType::LivreInventaire => throw new DomainException(
                "The livre d'inventaire is generated at year-end close by its own Action (§14.2): it "
                .'transcribes the Bilan, the Compte de resultat, the Tableau des flux and the summary '
                .'of the physical inventory, none of which this generic renderer produces.'
            ),
        };
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>, 2: array<string, mixed>}
     */
    private function livreJournalContent(int $fiscalYearId, string $start, string $end): array
    {
        $data = $this->livreJournal->handle($fiscalYearId, $start, $end);

        $rows = array_map(static fn (array $r): array => [
            $r['date'], $r['piece_no'], $r['journal_code'], $r['account_code'],
            $r['label'], $r['partner'], $r['debit'], $r['credit'],
        ], $data);

        $pieces = array_values(array_filter(array_column($data, 'piece_no'), static fn (string $p): bool => $p !== ''));

        return [
            ['Date', 'Piece', 'Jnl', 'Compte', 'Libelle', 'Tiers', 'Debit', 'Credit'],
            $rows,
            [
                'first_piece_no' => $pieces === [] ? null : (string) min($pieces),
                'last_piece_no' => $pieces === [] ? null : (string) max($pieces),
                'total_debit' => array_sum(array_column($data, 'debit')),
                'total_credit' => array_sum(array_column($data, 'credit')),
                'entry_count' => count(array_unique($pieces)),
                'line_count' => count($data),
            ],
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>, 2: array<string, mixed>}
     */
    private function balanceGeneraleContent(int $fiscalYearId, string $start, string $end): array
    {
        $result = $this->balanceGenerale->handle($fiscalYearId, $start, $end);

        $rows = array_map(static fn (array $r): array => [
            $r['account_code'], $r['account_name'],
            $r['opening_debit'], $r['opening_credit'],
            $r['movement_debit'], $r['movement_credit'],
            $r['closing_debit'], $r['closing_credit'],
        ], $result['rows']);

        return [
            ['Compte', 'Intitule', 'AN Debit', 'AN Credit', 'Mvt Debit', 'Mvt Credit', 'Solde Debit', 'Solde Credit'],
            $rows,
            [
                'first_piece_no' => null,
                'last_piece_no' => null,
                'total_debit' => $result['totals']['closing_debit'],
                'total_credit' => $result['totals']['closing_credit'],
                'entry_count' => 0,
                'line_count' => count($result['rows']),
            ],
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>, 2: array<string, mixed>}
     */
    private function grandLivreContent(int $fiscalYearId, string $start, string $end): array
    {
        $accounts = $this->grandLivre->handle($fiscalYearId, $start, $end);

        $rows = [];
        $lineCount = 0;
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($accounts as $account) {
            $rows[] = [
                $account['account_code'],
                $account['account_name'],
                "Solde d'ouverture",
                '',
                '',
                $account['opening_balance'],
            ];

            foreach ($account['movements'] as $movement) {
                $rows[] = [
                    '',
                    $movement['date'],
                    $movement['piece_no'].' '.$movement['label'],
                    $movement['debit'],
                    $movement['credit'],
                    $movement['running_balance'],
                ];

                $lineCount++;
                $totalDebit += $movement['debit'];
                $totalCredit += $movement['credit'];
            }
        }

        return [
            ['Compte', 'Date', 'Libelle', 'Debit', 'Credit', 'Solde'],
            $rows,
            [
                'first_piece_no' => null,
                'last_piece_no' => null,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'entry_count' => count($accounts),
                'line_count' => $lineCount,
            ],
        ];
    }
}

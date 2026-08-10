<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Actions\Books\GenerateStatutoryBook;
use App\Modules\Accounting\Domain\StatutoryBookType;
use App\Modules\Accounting\Models\StatutoryBook;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Generates the three period books for every fiscal year that has none.
 *
 * The livre d'inventaire is deliberately absent: it is generated at year-end
 * close and transcribes the Bilan, Compte de resultat, Tableau des flux and
 * the physical inventory summary (§14.2).
 *
 * Idempotent and additive: a year already carrying a book of a given type is
 * skipped, so a second run never builds a pointless supersession chain.
 */
final class StatutoryBookDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'demo.admin@opeschool.test')->first();

        if ($admin === null) {
            $this->command?->warn('StatutoryBookDemoSeeder: no demo admin; skipping.');

            return;
        }

        Auth::setUser($admin);

        $types = [
            StatutoryBookType::LivreJournal,
            StatutoryBookType::GrandLivre,
            StatutoryBookType::BalanceGenerale,
        ];

        foreach (DB::table('fiscal_years')->orderBy('id')->get() as $year) {
            foreach ($types as $type) {
                $exists = StatutoryBook::query()
                    ->where('fiscal_year_id', (int) $year->id)
                    ->where('book_type', $type->value)
                    ->exists();

                if ($exists) {
                    $this->command?->info("{$type->label()} for {$year->code} already generated; skipping.");

                    continue;
                }

                $book = app(GenerateStatutoryBook::class)->handle(
                    $type,
                    (int) $year->id,
                    (string) $year->starts_on,
                    (string) $year->ends_on,
                );

                $this->command?->info(sprintf(
                    '%s %s: %d lines, D=%s C=%s, sha256 %s',
                    $type->label(),
                    (string) $year->code,
                    $book->line_count,
                    number_format($book->total_debit),
                    number_format($book->total_credit),
                    substr($book->sha256, 0, 12),
                ));
            }
        }
    }
}

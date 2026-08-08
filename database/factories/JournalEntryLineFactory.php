<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to a single debit line of 1000. `ck_jel_one_side` forbids a 0/0
 * or a both-sides line, so `credit()` flips to the other side rather than
 * adding to it.
 *
 * @extends Factory<JournalEntryLine>
 */
class JournalEntryLineFactory extends Factory
{
    /** @var class-string<JournalEntryLine> */
    protected $model = JournalEntryLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'journal_entry_id' => JournalEntry::factory(),
            'sequence' => 1,
            'account_id' => ChartOfAccount::factory(),
            'label' => fake()->sentence(3),
            'debit' => 1000,
            'credit' => 0,
            'partner_type' => null,
            'partner_id' => null,
        ];
    }

    public function credit(int $amount = 1000): static
    {
        return $this->state(fn (array $attributes): array => ['debit' => 0, 'credit' => $amount]);
    }

    public function debit(int $amount = 1000): static
    {
        return $this->state(fn (array $attributes): array => ['debit' => $amount, 'credit' => 0]);
    }
}

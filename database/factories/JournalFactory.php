<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Domain\JournalType;
use App\Modules\Accounting\Models\Journal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Journal>
 */
class JournalFactory extends Factory
{
    /** @var class-string<Journal> */
    protected $model = Journal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Three letters, never two: every seeded journal code (VE, AC, CA, BQ,
        // MM, PA, OD, AN, CL - migration 230004) is exactly two letters, so a
        // three-letter code cannot collide with one no matter what
        // fake()->unique() picks. Two letters did collide - 'BQ' and 'OD' are
        // both seeded codes, and Faker's uniqueness is scoped to this factory,
        // not to rows already in the database.
        $code = strtoupper(fake()->unique()->lexify('???'));

        return [
            'code' => $code,
            'name' => 'Journal '.$code,
            'name_fr' => 'Journal '.$code,
            'type' => JournalType::OperationsDiverses,
            'default_debit_account_id' => null,
            'default_credit_account_id' => null,
            'treasury_account_id' => null,
            'requires_maker_checker' => false,
            'piece_no_format' => '{journal}/{fy}/{seq:6}',
            'is_system' => false,
            'is_active' => true,
            'is_archived' => false,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes): array => ['is_system' => true]);
    }

    public function ofType(JournalType $type): static
    {
        return $this->state(fn (array $attributes): array => ['type' => $type]);
    }
}

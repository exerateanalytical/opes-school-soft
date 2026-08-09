<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Library\Models\Book;
use App\Modules\Library\Models\BookCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    /** @var class-string<Book> */
    protected $model = Book::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'isbn' => '978'.fake()->unique()->numerify('##########'),
            'title' => 'Advanced Mathematics '.fake()->unique()->numberBetween(1, 999_999),
            'subtitle' => null,
            'author' => fake()->name(),
            'co_authors' => null,
            'publisher' => 'Presses Universitaires',
            'publication_year' => fake()->numberBetween(1990, 2026),
            'edition' => null,
            'language' => 'en',
            'book_category_id' => BookCategory::factory(),
            'dewey_or_call_number' => (string) fake()->numberBetween(100, 999),
            'pages' => fake()->numberBetween(80, 900),
            'summary' => null,
            'cover_path' => null,
            'replacement_cost' => 6_000,
            'is_reference_only' => false,
            'is_archived' => false,
            'created_by' => null,
        ];
    }
}

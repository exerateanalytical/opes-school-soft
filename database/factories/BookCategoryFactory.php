<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Library\Models\BookCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookCategory>
 */
class BookCategoryFactory extends Factory
{
    /** @var class-string<BookCategory> */
    protected $model = BookCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'BC'.fake()->unique()->numberBetween(1, 999_999);

        return [
            'code' => $code,
            'name' => 'Category '.$code,
            'name_fr' => 'Categorie '.$code,
            'parent_id' => null,
            'is_archived' => false,
        ];
    }
}

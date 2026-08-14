<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Alumni\Models\AlumniEngagement;
use App\Modules\Alumni\Models\AlumnusRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlumniEngagement>
 */
final class AlumniEngagementFactory extends Factory
{
    protected $model = AlumniEngagement::class;

    public function definition(): array
    {
        return [
            'alumnus_record_id' => AlumnusRecord::factory(),
            'type' => 'visit',
            'engaged_on' => '2031-02-10',
            'note' => 'Dropped by the staff room during the alumni day.',
        ];
    }
}

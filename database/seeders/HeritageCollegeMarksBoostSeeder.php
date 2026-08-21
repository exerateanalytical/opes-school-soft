<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Assessment\Actions\SaveMarkBatch;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class HeritageCollegeMarksBoostSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'demo.admin@opeschool.test')->firstOrFail();
        Auth::login($admin);

        $pending = DB::table('marks')
            ->whereIn('state', ['pending', 'scored'])
            ->get(['id', 'enrollment_id', 'subject_allocation_id', 'assessment_period_id', 'component_id', 'version']);

        $byAllocation = $pending->groupBy('subject_allocation_id');

        foreach ($byAllocation as $subjectAllocationId => $group) {
            $periodId = (int) $group->first()->assessment_period_id;

            // One "ability" factor per enrollment so a student's CA and EXAM
            // scores correlate (a strong student is strong on both), plus
            // independent noise per component so it isn't a flat duplicate.
            $byEnrollment = $group->groupBy('enrollment_id');
            $rows = [];

            foreach ($byEnrollment as $marksForStudent) {
                // Bell-curve-ish ability, out of 10: mean 6.2, spread via
                // averaging two uniform draws from [2.0, 10.4] (Irwin-Hall
                // triangular approximation, peaked at the midpoint).
                $ability = (random_int(20, 104) + random_int(20, 104)) / 2 / 10;
                $ability = max(0.5, min(9.8, $ability));

                foreach ($marksForStudent as $mark) {
                    $noise = random_int(-15, 15) / 10;
                    $score = max(0, min(10, $ability + $noise));

                    $rows[] = [
                        'mark_id' => (int) $mark->id,
                        'version' => (int) $mark->version,
                        'state' => 'scored',
                        'score' => number_format($score, 3, '.', ''),
                    ];
                }
            }

            if ($rows === []) {
                continue;
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                app(SaveMarkBatch::class)->handle((int) $subjectAllocationId, $periodId, $chunk);
            }
        }

        $this->command?->info('Marks boost complete.');
    }
}

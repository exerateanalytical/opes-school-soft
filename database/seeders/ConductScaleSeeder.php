<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Assessment\Models\ConductScale;
use Illuminate\Database\Seeder;

/**
 * The two conduct scales 01-assessment §12.3 names explicitly:
 * TB/B/AB/P/M for the Francophone secondary bulletin, and A/ECA/NA for a
 * competency-based framework.
 *
 * These are seeded because the spec names the level codes itself - unlike
 * the mark scale, coefficients and DSF codes, which are NEEDS VERIFICATION
 * and stay empty. Labels are editable; a school that words "Assez bien"
 * differently changes it here and its own wording prints on the bulletin.
 *
 * Idempotent: a scale that already exists is left exactly as the school
 * configured it.
 */
final class ConductScaleSeeder extends Seeder
{
    public function run(): void
    {
        $this->scale('MINESEC_FR', 'MINESEC conduct scale', 'Échelle de conduite MINESEC', [
            ['TB', 'Very good', 'Très bien'],
            ['B', 'Good', 'Bien'],
            ['AB', 'Fairly good', 'Assez bien'],
            ['P', 'Poor', 'Passable'],
            ['M', 'Bad', 'Mauvais'],
        ]);

        $this->scale('COMPETENCY', 'Competency scale', 'Échelle de compétence', [
            ['A', 'Achieved', 'Acquis'],
            ['ECA', 'Being acquired', 'En cours d\'acquisition'],
            ['NA', 'Not achieved', 'Non acquis'],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $levels
     */
    private function scale(string $code, string $name, string $nameFr, array $levels): void
    {
        $existing = ConductScale::query()->where('code', $code)->first();

        if ($existing !== null) {
            $this->command?->info("Conduct scale {$code} already exists; leaving it as configured.");

            return;
        }

        $scale = ConductScale::query()->create([
            'code' => $code,
            'name' => $name,
            'name_fr' => $nameFr,
            'framework_id' => null,
            'is_active' => true,
        ]);

        foreach ($levels as $index => [$levelCode, $label, $labelFr]) {
            $scale->levels()->create([
                'code' => $levelCode,
                'label' => $label,
                'label_fr' => $labelFr,
                'sequence' => $index + 1,
            ]);
        }

        $this->command?->info(sprintf('Seeded conduct scale %s with %d levels.', $code, count($levels)));
    }
}

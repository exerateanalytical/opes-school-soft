<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\ReportCardConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportCardConfig>
 */
final class ReportCardConfigFactory extends Factory
{
    protected $model = ReportCardConfig::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'framework_id' => fn (): int => (int) AssessmentFramework::factory()->create()->getKey(),
            'code' => strtoupper(uniqid('RC')),
            'name' => 'Term Bulletin',
            'name_fr' => 'Bulletin de trimestre',
            'is_active' => true,
        ];
    }

    /**
     * A REAL Cameroonian bulletin de trimestre, which is the shape 13.5 says
     * v1's configurator could not express:
     *
     *   Matiere | Seq 1 | Seq 2 | Moy/20 | Coef | MxCoef | Rang | Cote | Appreciation
     *
     * Note the two `period_score` columns with different `period_ref` values.
     * That is the single thing a flat list of enum keys cannot do, and the
     * reason `marks_columns` is an ordered array of parameterised objects.
     *
     * This lives in a factory, not a seeder: 00-core 16 seeds nothing, and a
     * school's layout is a school's decision.
     *
     * @return array<string, mixed>
     */
    public static function bulletinPayload(): array
    {
        return [
            'layout' => 'minesec_term_bulletin',
            'locale' => 'fr',
            'branding' => [
                'show_school_crest' => true,
                'accent_colour' => '#1d4ed8',
            ],
            'blocks' => [
                'state_header' => true,
                'student_identity' => true,
                'subject_table' => true,
                'totals_row' => true,
                'general_average_and_rank' => true,
                'mention' => true,
                'gpa' => false,
                'conduct' => ['enabled' => true, 'required' => false],
                'absence_hours' => true,
                'conseil_award' => true,
                'remarks' => ['enabled' => true, 'class_master_required' => false],
                'class_statistics' => true,
                'previous_period_average' => false,
                'annual_average' => false,
                'fee_balance' => false,
                'signatures' => true,
                'qr_verification' => true,
                'version_and_issue_date' => true,
            ],
            'marks_columns' => [
                ['key' => 'subject_name', 'label_fr' => 'Matière', 'width' => 30],
                ['key' => 'subject_score', 'label_fr' => 'Moy/20', 'decimals' => 2],
                ['key' => 'coefficient', 'label_fr' => 'Coef'],
                ['key' => 'score_times_coef', 'label_fr' => 'M×Coef', 'decimals' => 2],
                ['key' => 'subject_rank', 'label_fr' => 'Rang'],
                ['key' => 'cote_min_max', 'label_fr' => 'Cote', 'format' => '[{min}–{max}]'],
                ['key' => 'appreciation', 'label_fr' => 'Appréciation'],
                ['key' => 'teacher_visa', 'label_fr' => 'Visa'],
            ],
        ];
    }

    /**
     * Family F (8.4). `uses_rank` and `uses_coefficients` are forced false on
     * the framework, so the configurator must not offer the columns either -
     * a nursery card has no Rang box to leave blank.
     *
     * @return array<string, mixed>
     */
    public static function nurseryPayload(): array
    {
        $payload = self::bulletinPayload();
        $payload['layout'] = 'minedub_nursery_report';
        $payload['blocks']['totals_row'] = false;
        $payload['blocks']['general_average_and_rank'] = false;
        $payload['blocks']['mention'] = false;
        $payload['blocks']['class_statistics'] = false;
        $payload['marks_columns'] = [
            ['key' => 'subject_name', 'label_fr' => 'Domaine'],
            ['key' => 'competencies_assessed', 'label_fr' => 'Compétences'],
        ];

        return $payload;
    }
}

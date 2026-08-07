<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

/**
 * What an AssessmentComponent IS, derived from its code
 * (docs/specs/01-assessment.md 5.3).
 *
 * Deliberately NOT a column. §5.3 gives a component `code`, `name`, `name_fr`,
 * `max_score`, `order_index` and `is_active` and nothing else, and schools name
 * their columns freely - a school with a `DEVOIR` component must not be blocked
 * because the enum has no case for it. So this classifies the well-known codes
 * and answers `Other` for the rest, and no behaviour anywhere is allowed to
 * depend on getting anything better than `Other`.
 *
 * The pipeline itself keys on `code` (see ComponentMark) and on ComponentWeight
 * rows, never on this. Its jobs are presentation - grouping and ordering the
 * entry grid - and 16.2, where an examination's marks have to be routed to the
 * component that represents the exam.
 */
enum ComponentKind: string
{
    /** Continuous assessment - the *note de classe*. */
    case ContinuousAssessment = 'continuous_assessment';

    /** The end-of-period examination. */
    case Examination = 'examination';

    /** Practical / laboratory work - *travaux pratiques*. */
    case Practical = 'practical';

    /** Oral examination. */
    case Oral = 'oral';

    /** Anything a school names for itself. Carries no special behaviour. */
    case Other = 'other';

    /**
     * Codes are compared case-insensitively but are otherwise matched exactly:
     * guessing from a prefix would classify `EXEMPT` as an examination.
     */
    public static function fromCode(string $code): self
    {
        return match (mb_strtoupper(trim($code))) {
            'CA', 'CC', 'NOTE_CLASSE' => self::ContinuousAssessment,
            'EXAM', 'EXAMEN', 'COMPO' => self::Examination,
            'TP', 'PRATIQUE', 'PRACTICAL' => self::Practical,
            'ORAL' => self::Oral,
            default => self::Other,
        };
    }

    public function label(string $locale = 'en'): string
    {
        return __('opes.component_kind.'.$this->value, [], $locale);
    }

    /**
     * 01-assessment 16.2: an Exam's marks feed the pipeline through the
     * component that represents the examination. A framework with no such
     * component cannot receive them, and that is reported rather than guessed.
     */
    public function isExamination(): bool
    {
        return $this === self::Examination;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Domain;

/**
 * A curriculum version's lifecycle (module-gap-analysis gap #2).
 *
 * Two states only, and the transition is one-way: a draft is editable, a
 * published version is locked forever. There is no "unpublish" - teaching
 * plans and assessments may already reference a published version, so a
 * change is always a NEW version cloned by ReviseCurriculum, never an edit
 * in place.
 */
enum CurriculumStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(string $locale = 'en'): string
    {
        return __('opes.curriculum.status_'.$this->value, [], $locale);
    }
}

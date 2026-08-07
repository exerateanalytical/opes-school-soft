<?php

declare(strict_types=1);

namespace App\Modules\Academics\Domain;

/**
 * The track dimension of a SchoolSection (docs/specs/00-core.md 8).
 *
 * `normal` is the enseignement normal (teacher training) track - the name is
 * the Cameroonian term of art, not a default.
 */
enum Track: string
{
    case General = 'general';
    case Technical = 'technical';
    case Normal = 'normal';

    public function label(string $locale = 'en'): string
    {
        return __('opes.tracks.'.$this->value, [], $locale);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Activities\Domain;

/**
 * docs/specs/2026-08-12-module-gap-analysis.md gap #1: the four activity
 * families in one table rather than four sibling modules. An excursion is
 * the only member that carries extra structure (destination, departure and
 * return, per-student guardian consent - the row 15 tie-in), enforced by
 * CHECK constraints on `activities` and by CreateActivity.
 */
enum ActivityType: string
{
    case Club = 'club';
    case Sport = 'sport';
    case Event = 'event';
    case Excursion = 'excursion';

    /** Whether this family carries the excursion extras + consent flow. */
    public function isExcursion(): bool
    {
        return $this === self::Excursion;
    }
}

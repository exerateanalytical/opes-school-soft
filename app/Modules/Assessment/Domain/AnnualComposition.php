<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

/**
 * How a year-level average is composed (docs/specs/01-assessment.md §9.3).
 *
 * The three are NOT interchangeable: §9.2 shows a 0.145 divergence between
 * MeanOfLeafPeriods and MeanOfTerms for one student with a missing sequence —
 * enough to move a rank and, at a band boundary, a mention.
 */
enum AnnualComposition: string
{
    /** Default, MINESEC: unweighted mean of the SIX sequence averages, nulls skipped. */
    case MeanOfLeafPeriods = 'mean_of_leaf_periods';

    /** Normalised weighted mean of the immediate children (§9.1 applied recursively). */
    case WeightedChildren = 'weighted_children';

    /** Unweighted mean of the term averages. Offered, never defaulted. */
    case MeanOfTerms = 'mean_of_terms';
}

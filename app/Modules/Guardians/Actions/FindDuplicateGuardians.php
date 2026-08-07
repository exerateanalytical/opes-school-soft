<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

use App\Modules\Guardians\Models\Guardian;

/**
 * docs/specs/07-students.md 7.7 - the duplicate-match key, implemented exactly
 * as specified: three tiers, tried IN ORDER, first tier with a hit wins.
 *
 *   1. `id_number_blind_index` (exact)
 *   2. normalised `phone` (exact)
 *   3. `last_name` + `first_name` + `date_of_birth` (exact)
 *
 * Every tier is an EXACT match. There is no fuzzy name matching here and there
 * must never be one: 7.7 says silent merge on a fuzzy name match across two
 * unrelated families is a data-protection incident, and in Cameroon the same
 * surname across unrelated households is entirely ordinary. Tier 3 therefore
 * requires the date of birth as well, and returns nothing at all when the date
 * of birth is unknown - two adults sharing a name is not evidence.
 *
 * "First tier wins" rather than a union, because the tiers are ordered by
 * confidence. A national-ID hit is near-certain; surfacing a same-phone
 * household member alongside it would invite the operator to link the wrong
 * person. The caller PRESENTS the result ("Link to existing guardian Bela
 * Merceline?"); nothing here merges, and nothing here blocks.
 */
final class FindDuplicateGuardians
{
    public const TIER_ID_NUMBER = 'id_number';

    public const TIER_PHONE = 'phone';

    public const TIER_NAME_AND_DOB = 'name_and_dob';

    /**
     * @return array{tier: string|null, candidates: list<Guardian>}
     */
    public function handle(
        ?string $idNumber = null,
        ?string $phone = null,
        ?string $lastName = null,
        ?string $firstName = null,
        ?string $dateOfBirth = null,
        ?int $excludeGuardianId = null,
    ): array {
        $blindIndex = Guardian::blindIndexFor($idNumber);

        if ($blindIndex !== null) {
            $hits = $this->query($excludeGuardianId)
                ->where('id_number_blind_index', '=', $blindIndex)
                ->get()
                ->all();

            if ($hits !== []) {
                return ['tier' => self::TIER_ID_NUMBER, 'candidates' => array_values($hits)];
            }
        }

        $normalisedPhone = Guardian::normalisePhone($phone);

        if ($normalisedPhone !== null) {
            // Matched against BOTH phone columns: a household routinely gives
            // the father's handset as the mother's alternative number, and a
            // second record keyed on it is the same duplicate the first tier
            // would have caught had an ID card been on file.
            $hits = $this->query($excludeGuardianId)
                ->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($normalisedPhone): void {
                    $q->where('phone', '=', $normalisedPhone)
                        ->orWhere('alternative_phone', '=', $normalisedPhone);
                })
                ->get()
                ->all();

            if ($hits !== []) {
                return ['tier' => self::TIER_PHONE, 'candidates' => array_values($hits)];
            }
        }

        if ($lastName !== null && $firstName !== null && $dateOfBirth !== null) {
            // The name columns are `ai_ci`, so this comparison is already
            // accent- and case-insensitive at the storage layer - "Bélinga"
            // finds "belinga" without any normalisation in PHP. That is a
            // property of the collation chosen in the migration, not an
            // accident, and it is why tier 3 can be an exact match and still
            // be useful.
            $hits = $this->query($excludeGuardianId)
                ->where('last_name', '=', $lastName)
                ->where('first_name', '=', $firstName)
                ->whereDate('date_of_birth', '=', $dateOfBirth)
                ->get()
                ->all();

            if ($hits !== []) {
                return ['tier' => self::TIER_NAME_AND_DOB, 'candidates' => array_values($hits)];
            }
        }

        return ['tier' => null, 'candidates' => []];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Guardian>
     */
    private function query(?int $excludeGuardianId): \Illuminate\Database\Eloquent\Builder
    {
        $query = Guardian::query()->where('is_archived', '=', false);

        if ($excludeGuardianId !== null) {
            // So that re-running detection against an already-saved guardian
            // (an edit, or a merge review) does not report the row as its own
            // duplicate.
            $query->whereKeyNot($excludeGuardianId);
        }

        return $query;
    }
}

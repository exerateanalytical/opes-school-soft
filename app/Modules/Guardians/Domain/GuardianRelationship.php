<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/**
 * docs/specs/07-students.md 7.2.
 *
 * 15.6 records this vocabulary as PROVISIONAL - the standard relationship list
 * expected on official Cameroonian school records is unverified. `Other` plus
 * the mandatory `relationship_other` free text is the escape hatch that keeps
 * the enum from losing data while that question is open.
 *
 * The relationship is descriptive only. It grants NOTHING: every authorization
 * decision reads the flag columns through GuardianScopeMatrix, never this.
 */
enum GuardianRelationship: string
{
    case Father = 'father';
    case Mother = 'mother';
    case Stepfather = 'stepfather';
    case Stepmother = 'stepmother';
    case Grandparent = 'grandparent';
    case Uncle = 'uncle';
    case Aunt = 'aunt';
    case Sibling = 'sibling';
    case LegalGuardian = 'legal_guardian';
    case Sponsor = 'sponsor';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** 7.2: `relationship_other` is required when, and only when, this is `other`. */
    public function requiresFreeText(): bool
    {
        return $this === self::Other;
    }
}

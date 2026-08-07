<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * docs/specs/07-students.md 8.1.
 *
 * A guardian-uploaded document lands as `Unverified` and is never
 * auto-verified (7.5 row 24); only staff move it out of that state.
 */
enum DocumentVerificationStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Rejected = 'rejected';
}

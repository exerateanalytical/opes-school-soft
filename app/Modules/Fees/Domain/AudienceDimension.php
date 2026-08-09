<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * 04-fees §2.2.1 (H) - the dimensions an audience/exclusion criterion can
 * test. `enrollment_status` values are DERIVED from enrollment history
 * (07-students C4), never from a mutable person-level flag.
 */
enum AudienceDimension: string
{
    case EnrollmentStatus = 'enrollment_status';
    case Gender = 'gender';
    case BoardingStatus = 'boarding_status';
    case TransportStatus = 'transport_status';
    case Stream = 'stream';
    case ClassLevel = 'class_level';
    case SchoolSection = 'school_section';
    case Nationality = 'nationality';
    case House = 'house';
}

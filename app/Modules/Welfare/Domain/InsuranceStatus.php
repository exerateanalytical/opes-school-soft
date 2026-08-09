<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * Status of ONE student's cover under a policy (a student_insurances row,
 * design doc §14). Only `active` rows count as cover: the uninsured-students
 * report treats lapsed and cancelled certificates as absence of cover.
 */
enum InsuranceStatus: string
{
    case Active = 'active';
    case Lapsed = 'lapsed';
    case Cancelled = 'cancelled';
}

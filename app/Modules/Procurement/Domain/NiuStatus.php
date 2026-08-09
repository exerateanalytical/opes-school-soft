<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §3.1 - the verification state of a
 * supplier's NIU. Absence or inactivity of the NIU CHANGES the withholding
 * rate (§6), so "we do not know" is an explicit state, never a null.
 */
enum NiuStatus: string
{
    case Unknown = 'unknown';
    case Active = 'active';
    case Inactive = 'inactive';
    case NotFound = 'not_found';
    case NoneDeclared = 'none_declared';
}

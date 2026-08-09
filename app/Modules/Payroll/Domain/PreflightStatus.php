<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * The outcome of one persisted preflight check row
 * (docs/specs/05-hr-payroll.md 9.1).
 */
enum PreflightStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Warning = 'warning';
}

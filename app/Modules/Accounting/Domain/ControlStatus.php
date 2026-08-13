<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * The outcome of one control-account identity,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.1.
 *
 * NotConfigured is not a failure. It means a docs/specs/02-accounting.md §22
 * gate blocks the check, and the screen must name that gate rather than
 * render a zero that looks like agreement.
 */
enum ControlStatus: string
{
    case Reconciled = 'reconciled';
    case Difference = 'difference';
    case NotConfigured = 'not_configured';
}

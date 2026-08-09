<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §4.4 - which comparison a line gets.
 *
 * Three-way: PO price x PO qty ↔ receipt qty ↔ invoice price x qty, for
 * goods lines where receipts are required. Two-way: PO ↔ invoice, for
 * services and goods where `receipt_required_for_goods = false`. None:
 * direct invoices below `po_required_above` - approval then needs the
 * `procurement.invoice_approve_unmatched` permission and a stored reason.
 */
enum MatchMode: string
{
    case ThreeWay = 'three_way';
    case TwoWay = 'two_way';
    case None = 'none';
}

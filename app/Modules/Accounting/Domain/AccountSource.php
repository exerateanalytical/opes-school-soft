<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * How a PostingRuleLine resolves its account. docs/specs/02-accounting.md §11.1.
 */
enum AccountSource: string
{
    /** A fixed account code on the line itself (`account_code`). */
    case Literal = 'literal';

    /** A payload path yielding an account id (`account_path`). */
    case PayloadPath = 'payload_path';

    /** A settings key holding the configured account id (`account_path`). */
    case Setting = 'setting';
}

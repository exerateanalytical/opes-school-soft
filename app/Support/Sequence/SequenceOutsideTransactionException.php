<?php

declare(strict_types=1);

namespace App\Support\Sequence;

use RuntimeException;

/**
 * Thrown when SequenceAllocator::allocate() is called with no open
 * transaction.
 *
 * This is a programming error, not a runtime condition, and it is fatal on
 * purpose. The row lock a sequence relies on is released at commit; with no
 * transaction the lock is released the instant the UPDATE returns, so two
 * concurrent callers can both read the same next_value. Worse, the number
 * would be consumed even if the work it was allocated for later rolled back -
 * which for a gapless series (00-core 12, OHADA AUDCIF Art. 19) is a
 * compliance defect, not an inconvenience.
 */
final class SequenceOutsideTransactionException extends RuntimeException
{
    public static function forSeries(string $series): self
    {
        return new self(
            "Sequence [{$series}] was allocated outside a transaction. "
            .'Wrap the allocation and the insert that consumes it in one DB::transaction().'
        );
    }
}

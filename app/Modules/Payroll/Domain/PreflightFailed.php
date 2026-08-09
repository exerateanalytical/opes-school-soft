<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use DomainException;

/**
 * The refusal (docs/specs/05-hr-payroll.md 9.1): one or more fatal
 * preflight checks failed, the run computed NOTHING, and there is no
 * "proceed anyway". The persisted PayrollPreflightResult rows carry the
 * specifics; this exception carries the failing codes so a caller (and a
 * test) can assert exactly which check refused.
 */
final class PreflightFailed extends DomainException
{
    /**
     * @param  list<PreflightCheckCode>  $failed
     */
    private function __construct(public readonly array $failed, string $message)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<PreflightCheckCode>  $failed
     */
    public static function withCodes(array $failed): self
    {
        return new self($failed, sprintf(
            'Payroll preflight refused the run: %s. Fix the configuration; there is no proceed-anyway (05-hr-payroll 9.1).',
            implode(', ', array_map(static fn (PreflightCheckCode $code): string => $code->value, $failed)),
        ));
    }

    public function hasFailed(PreflightCheckCode $code): bool
    {
        return in_array($code, $this->failed, true);
    }
}

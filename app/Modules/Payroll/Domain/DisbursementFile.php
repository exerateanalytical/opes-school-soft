<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * The rendered bank / mobile-money disbursement artefact
 * (docs/specs/05-hr-payroll.md 8.8): a configurable fixed-layout or CSV
 * file. Value object - the caller decides whether to stream or store it.
 */
final readonly class DisbursementFile
{
    public function __construct(
        public string $filename,
        public string $contents,
        public int $exportedLineCount,
        public int $exportedTotal,
    ) {}
}

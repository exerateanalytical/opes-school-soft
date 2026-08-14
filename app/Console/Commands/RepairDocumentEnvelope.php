<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Reporting\Actions\FreezeEnvelopeFromPrintLog;
use App\Modules\Reporting\Models\DocumentPrintLog;
use App\Modules\Reporting\Models\IssuedDocument;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * One-shot recovery for documents issued BEFORE `render_envelope` existed
 * AND already stranded by a rename - the reprint path's own backfill cannot
 * reach them, because it only runs after a re-render that reproduces the
 * recorded hash, and for these that re-render is precisely what fails.
 *
 * The recovery key is the audit trail: `document_print_logs
 * .subject_label_at_time` recorded the label AS AT ISSUE, which
 * PrintReportCard's docblock already says it exists to do. This tries
 * EXACTLY ONE candidate - that recorded label - and freezes the envelope
 * only if the resulting bytes reproduce `content_hash`. It is not a search:
 * a document whose recorded label does not reproduce is reported and left
 * untouched, because forcing an envelope that does not reproduce would turn
 * an honest refusal into a silent forgery.
 */
final class RepairDocumentEnvelope extends Command
{
    /** @var string */
    protected $signature = 'opes:documents:repair-envelope {--dry-run : Report what would change and write nothing}';

    /** @var string */
    protected $description = 'Freeze the render envelope on documents issued before the column existed, using the label recorded at issue.';

    public function handle(FreezeEnvelopeFromPrintLog $freeze): int
    {
        $repaired = 0;
        $alreadyFine = 0;
        $unrecoverable = 0;

        IssuedDocument::query()
            ->whereNull('render_envelope')
            ->orderBy('id')
            ->chunkById(50, function (Collection $documents) use ($freeze, &$repaired, &$alreadyFine, &$unrecoverable): void {
                /** @var IssuedDocument $document */
                foreach ($documents as $document) {
                    // The FIRST log is the issue-time print; later logs are
                    // duplicata copies whose label may already postdate a
                    // rename. Only the first is evidence of what was issued.
                    $label = DocumentPrintLog::query()
                        ->where('issued_document_id', $document->getKey())
                        ->orderBy('id')
                        ->value('subject_label_at_time');

                    if (! is_string($label) || $label === '') {
                        $unrecoverable++;
                        $this->line(sprintf('  %s: no print log records the label at issue', (string) ($document->serial ?? $document->getKey())));

                        continue;
                    }

                    try {
                        $outcome = $freeze->handle((int) $document->getKey(), $label, (bool) $this->option('dry-run'));
                    } catch (Throwable $e) {
                        $unrecoverable++;
                        $this->line(sprintf('  %s: %s', (string) ($document->serial ?? $document->getKey()), $e->getMessage()));

                        continue;
                    }

                    if ($outcome === 'unrecoverable') {
                        $this->line(sprintf('  %s: the label recorded at issue does not reproduce the recorded hash; left untouched', (string) ($document->serial ?? $document->getKey())));
                    } elseif ($outcome === 'repaired') {
                        $this->line(sprintf('  %s: envelope frozen from the label recorded at issue', (string) ($document->serial ?? $document->getKey())));
                    }

                    match ($outcome) {
                        'repaired' => $repaired++,
                        'already_fine' => $alreadyFine++,
                        default => $unrecoverable++,
                    };
                }
            });

        $this->info(sprintf('repaired: %d · already fine: %d · unrecoverable: %d', $repaired, $alreadyFine, $unrecoverable));

        return self::SUCCESS;
    }
}

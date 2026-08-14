<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Reporting\Models\IssuedDocument;
use Illuminate\Support\Facades\DB;

/**
 * Re-renders one issued document with the label recorded on its FIRST print
 * log and freezes the envelope only if the bytes reproduce the recorded
 * content_hash. Lives in the module rather than the command so the proof
 * ("it reproduced, therefore it is the original") is testable on its own and
 * cannot drift away from RenderDocument's reprint path.
 */
final class FreezeEnvelopeFromPrintLog
{
    public function __construct(private readonly RenderDocument $render) {}

    /**
     * @return 'repaired'|'already_fine'|'unrecoverable'
     */
    public function handle(int $issuedDocumentId, string $recordedLabel, bool $dryRun = false): string
    {
        /** @var IssuedDocument $issued */
        $issued = IssuedDocument::query()->findOrFail($issuedDocumentId);

        if ($issued->render_envelope !== null) {
            return 'already_fine';
        }

        $candidate = $this->render->rebuildEnvelope($issued, $recordedLabel);

        if ($candidate === null) {
            return 'unrecoverable';
        }

        if ($dryRun) {
            return 'repaired';
        }

        DB::transaction(function () use ($issued, $candidate): void {
            $issued->render_envelope = $candidate;
            $issued->save();
        });

        return 'repaired';
    }
}

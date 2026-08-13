<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * docs/specs/10-documents.md 4.2 - the registry of snapshot SOURCES: which
 * table a template's `snapshot_source` name reads, which column holds the
 * immutable JSON payload and which column carries the generation/version.
 *
 * Pure data (the actual read is a DB::table call in RenderDocument, because
 * Domain/ imports no Laravel). New snapshot-backed payload stores register
 * here; a template naming an unregistered source falls back to the payload
 * its calling Action assembled from its own immutable rows - the receipt
 * pattern, where the "snapshot" is the append-only Payment/Receipt row pair
 * rather than a JSON column, frozen instead onto `issued_documents.
 * payload_snapshot` (RenderDocument::issueOriginal/loadSnapshot).
 *
 * WARNING for whoever adds an entry here later: `loadSnapshot()` decides
 * which branch to take from the template's CURRENT `snapshot_source`/
 * `has()` state, not what was true at issue time. If a template that has
 * already issued documents under the receipt pattern is later registered
 * here, its old documents' reprints will stop reading their frozen
 * `payload_snapshot` and instead try to look up `snapshot_id` in the newly-
 * registered table - where it was never written - and fail with a
 * ValidationException instead of reproducing history. Registering a source
 * for a template with pre-existing receipt-pattern issues needs a data
 * migration alongside it, not just this array entry.
 */
final class SnapshotSourceMap
{
    /**
     * @var array<string, array{table: string, payload: string, version: string|null}>
     */
    private const SOURCES = [
        'ReportCardSnapshot' => [
            'table' => 'report_card_snapshots',
            'payload' => 'payload',
            'version' => 'generation',
        ],
    ];

    public static function has(string $source): bool
    {
        return array_key_exists($source, self::SOURCES);
    }

    /**
     * @return array{table: string, payload: string, version: string|null}
     */
    public static function get(string $source): array
    {
        return self::SOURCES[$source];
    }
}

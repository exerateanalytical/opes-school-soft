<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\AssetTagBarcode;
use App\Modules\Reporting\Domain\Code39Image;
use App\Modules\Reporting\Domain\RenderedDocument;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Asset labels - one sticker, or a sheet of them for a stock-take.
 *
 * Goes through RenderDocument like every other PDF in this platform
 * (10-documents 4.8: it is THE only path to a PDF), on a LIVE template: a
 * label is a working artefact, not a certificate. Nothing about a sticker
 * needs to reproduce byte-for-byte in five years, and burning a serial per
 * label would put a gap in a statutory counter every time one peels off a
 * projector and gets reprinted.
 *
 * Cross-module reads use DB::table; `asset_categories` is this module's own,
 * but the pattern is the register's throughout and ModuleBoundaryTest holds
 * it there.
 */
final class PrintAssetLabel
{
    /**
     * The most labels one sheet render will build. A stock-take of a large
     * secondary school is a few hundred assets; ten thousand base64 PNGs in
     * one HTML string is an out-of-memory, not a print job.
     */
    public const SHEET_LIMIT = 400;

    public function __construct(private readonly RenderDocument $render) {}

    public function handle(int $assetId): RenderedDocument
    {
        Gate::authorize(AssetPermission::VIEW);

        $label = $this->label($assetId);

        if ($label === null) {
            throw new DomainException("Asset {$assetId} does not exist; there is nothing to label.");
        }

        return $this->render->handle(
            templateCode: 'ASSET-LABEL',
            subjectType: 'Asset',
            subjectId: $assetId,
            subjectLabel: $label['tag_number'].' — '.$label['name'],
            data: $label,
        );
    }

    /**
     * @param  list<int>  $assetIds
     */
    public function sheet(array $assetIds): RenderedDocument
    {
        Gate::authorize(AssetPermission::VIEW);

        if ($assetIds === []) {
            throw new DomainException('Select at least one asset before printing a label sheet.');
        }

        if (count($assetIds) > self::SHEET_LIMIT) {
            throw new DomainException(
                'A label sheet prints at most '.self::SHEET_LIMIT.' labels at a time; '
                .count($assetIds).' were selected.'
            );
        }

        $labels = [];

        foreach ($assetIds as $assetId) {
            $label = $this->label($assetId);

            if ($label !== null) {
                $labels[] = $label;
            }
        }

        if ($labels === []) {
            throw new DomainException('None of the selected assets exist; there is nothing to label.');
        }

        return $this->render->handle(
            templateCode: 'ASSET-LABEL-SHEET',
            subjectType: 'AssetLabelSheet',
            // A sheet is not "about" one asset. The first asset's id is a
            // stable, non-null subject for the print log - which is what the
            // log is for: WHO printed WHAT, WHEN.
            subjectId: $labels[0]['asset_id'],
            subjectLabel: count($labels).' asset labels',
            data: ['labels' => $labels],
        );
    }

    /**
     * The label payload for one asset, or null when it does not exist.
     *
     * The second meta line is the SERIAL NUMBER rather than the location the
     * label design first asked for: `assets.location_id` is polymorphic
     * across `rooms` and `store_locations` with no discriminator column (see
     * the 270002 migration), so there is no join that resolves it to a name
     * without guessing, and a guess printed on a sticker is worse than a
     * blank line.
     *
     * @return array{asset_id: int, tag_number: string, name: string, category: string, serial_number: string|null, barcode_uri: string|null}|null
     */
    private function label(int $assetId): ?array
    {
        $row = DB::table('assets as a')
            ->leftJoin('asset_categories as c', 'c.id', '=', 'a.asset_category_id')
            ->where('a.id', $assetId)
            ->select(['a.id', 'a.tag_number', 'a.name', 'a.serial_number', 'c.name as category_name'])
            ->first();

        if ($row === null) {
            return null;
        }

        $tag = (string) $row->tag_number;

        // A tag that cannot carry a Code 39 barcode reading back as ITSELF
        // gets NO barcode. The register contains imported and hand-written
        // legacy tags, and a barcode that scans as a different asset is worse
        // than none - a stock-take believes the scanner.
        $barcode = AssetTagBarcode::tryFromCanonical($tag);

        return [
            'asset_id' => (int) $row->id,
            'tag_number' => $tag,
            'name' => (string) $row->name,
            'category' => is_string($row->category_name) ? $row->category_name : '—',
            'serial_number' => is_string($row->serial_number) ? $row->serial_number : null,
            'barcode_uri' => $barcode === null ? null : Code39Image::dataUri($barcode->barcodePayload()),
        ];
    }
}

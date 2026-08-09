<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Reporting\Models\DocumentSeries;
use App\Modules\Reporting\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplate>
 */
class DocumentTemplateFactory extends Factory
{
    /** @var class-string<DocumentTemplate> */
    protected $model = DocumentTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'T-'.fake()->unique()->lexify('?????'),
            'name' => 'Test document',
            'name_fr' => 'Document de test',
            'module' => 'Reporting',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'duplex' => 'none',
            'series_code' => null,
            'is_snapshot_backed' => false,
            'snapshot_source' => null,
            'carries_qr' => false,
            'carries_barcode' => false,
            'state_header' => 'none',
            'signature_roles' => null,
            'min_phase' => 'v1',
            'bulk_printable' => false,
            'blade_view' => 'documents.blocks.document_footer',
            'version' => 1,
            'is_active' => true,
        ];
    }

    public function snapshotBacked(string $source = 'ReportCardSnapshot'): self
    {
        return $this->state(fn (): array => [
            'is_snapshot_backed' => true,
            'snapshot_source' => $source,
        ]);
    }

    public function withSeries(?DocumentSeries $series = null): self
    {
        return $this->state(fn (): array => [
            'series_code' => ($series ?? DocumentSeries::factory()->create())->code,
        ]);
    }
}

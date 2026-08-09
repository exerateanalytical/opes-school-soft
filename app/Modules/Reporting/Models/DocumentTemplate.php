<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use App\Modules\Reporting\Domain\Orientation;
use App\Modules\Reporting\Domain\PaperSize;
use App\Modules\Reporting\Domain\SignatureRole;
use Database\Factories\DocumentTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * docs/specs/10-documents.md 4.1 - one row per REGISTERED printable.
 *
 * The signature-role allow-list (2.3) is validated here, in the model's save
 * hook, rather than only in a screen Action: "any other role fails template
 * validation at save" has to hold for every path that saves a template,
 * including seeding and future imports. `minister` and friends are refused
 * BY NAME with the 13.2 message.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property string $module
 * @property string $paper_size
 * @property string $orientation
 * @property string $duplex
 * @property string|null $series_code
 * @property bool $is_snapshot_backed
 * @property string|null $snapshot_source
 * @property bool $carries_qr
 * @property bool $carries_barcode
 * @property string $state_header
 * @property array<int, mixed>|null $signature_roles
 * @property string $min_phase
 * @property bool $bulk_printable
 * @property view-string $blade_view
 * @property int $version
 * @property bool $is_active
 */
final class DocumentTemplate extends Model
{
    /** @use HasFactory<DocumentTemplateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'name_fr', 'module',
        'paper_size', 'orientation', 'duplex',
        'series_code', 'is_snapshot_backed', 'snapshot_source',
        'carries_qr', 'carries_barcode', 'state_header',
        'signature_roles', 'min_phase', 'bulk_printable',
        'blade_view', 'version', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_snapshot_backed' => 'boolean',
            'carries_qr' => 'boolean',
            'carries_barcode' => 'boolean',
            'bulk_printable' => 'boolean',
            'is_active' => 'boolean',
            'version' => 'integer',
            'signature_roles' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (DocumentTemplate $template): void {
            $roles = $template->signature_roles;

            if ($roles === null) {
                return;
            }

            foreach ($roles as $role) {
                if (! is_string($role)) {
                    throw new RuntimeException(
                        'signature_roles must be an ordered list of role names (10-documents 4.1).'
                    );
                }

                if (SignatureRole::isDenied($role)) {
                    // 13.2, quoted as the spec requires: state offices never
                    // sign a school-issued document.
                    throw new RuntimeException(sprintf(
                        'Signature role [%s] is forbidden on every document in this suite: signature blocks '
                        .'for the Minister, the GCE Board Chairman or the Director of the Office of the '
                        .'Baccalauréat make the product a credential-forgery tool (10-documents 2.2 / 13.2).',
                        $role,
                    ));
                }

                if (SignatureRole::tryFrom($role) === null) {
                    throw new RuntimeException(sprintf(
                        'Signature role [%s] is not on the 2.3 allow-list; any other role fails template '
                        .'validation at save (10-documents 2.3).',
                        $role,
                    ));
                }
            }
        });
    }

    protected static function newFactory(): DocumentTemplateFactory
    {
        return DocumentTemplateFactory::new();
    }

    public function paperSize(): PaperSize
    {
        return PaperSize::from($this->paper_size);
    }

    public function orientation(): Orientation
    {
        return Orientation::from($this->orientation);
    }

    /**
     * @return BelongsTo<DocumentSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(DocumentSeries::class, 'series_code', 'code');
    }
}

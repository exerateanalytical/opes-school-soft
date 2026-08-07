<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file attached at step 5, docs/specs/07-students.md 6.1.
 *
 * `path` is a key on the PRIVATE disk and is never rendered as a URL: 14 of
 * the acceptance criteria requires a logged-out request for any student
 * document to 404, which a public disk cannot satisfy.
 *
 * @property int $id
 * @property int $admission_application_id
 * @property string $document_type
 * @property string $original_name
 * @property string $path
 * @property string|null $mime_type
 * @property int $size_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AdmissionApplicationDocument extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'admission_application_id',
        'document_type',
        'original_name',
        'path',
        'mime_type',
        'size_bytes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'admission_application_id' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AdmissionApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id');
    }
}

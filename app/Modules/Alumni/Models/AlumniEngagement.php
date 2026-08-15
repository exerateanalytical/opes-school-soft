<?php

declare(strict_types=1);

namespace App\Modules\Alumni\Models;

use App\Modules\Alumni\Domain\EngagementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One touch point in an alumnus's interaction log. Append-only from the
 * UI: RecordEngagement creates, nothing edits or deletes.
 *
 * @property int $id
 * @property int $alumnus_record_id
 * @property EngagementType $type
 * @property Carbon $engaged_on
 * @property string $note
 * @property int|null $recorded_by
 */
final class AlumniEngagement extends Model
{
    /** @use HasFactory<\Database\Factories\AlumniEngagementFactory> */
    use HasFactory;

    protected $table = 'alumni_engagements';

    protected $fillable = [
        'alumnus_record_id', 'type', 'engaged_on', 'note', 'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alumnus_record_id' => 'integer',
            'type' => EngagementType::class,
            'engaged_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<AlumnusRecord, $this>
     */
    public function alumnusRecord(): BelongsTo
    {
        return $this->belongsTo(AlumnusRecord::class);
    }

    protected static function newFactory(): \Database\Factories\AlumniEngagementFactory
    {
        return \Database\Factories\AlumniEngagementFactory::new();
    }
}

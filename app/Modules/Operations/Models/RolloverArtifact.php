<?php

declare(strict_types=1);

namespace App\Modules\Operations\Models;

use App\Modules\Operations\Domain\RolloverStep;
use Database\Factories\RolloverArtifactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row the rollover created, recorded for undo (phase-07 plan decision 2).
 * `entity_type` is the owning TABLE name so undo can delete through
 * DB::table() without touching any other module's models.
 *
 * @property int $id
 * @property int $rollover_run_id
 * @property string $entity_type
 * @property int $entity_id
 * @property int $step
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RolloverArtifact extends Model
{
    /** @use HasFactory<RolloverArtifactFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rollover_run_id' => 'integer',
            'entity_id' => 'integer',
            'step' => 'integer',
        ];
    }

    public function step(): RolloverStep
    {
        return RolloverStep::from($this->step);
    }

    /**
     * @return BelongsTo<RolloverRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(RolloverRun::class, 'rollover_run_id');
    }

    protected static function newFactory(): RolloverArtifactFactory
    {
        return RolloverArtifactFactory::new();
    }
}

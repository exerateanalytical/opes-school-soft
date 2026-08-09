<?php

declare(strict_types=1);

namespace App\Modules\Operations\Models;

use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use Database\Factories\RolloverRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The resumability record of one academic-year rollover
 * (docs/specs/08-operations.md §6.3). UNIQUE(from, to) makes the whole run
 * idempotent; `current_step` + `step_states` let a killed run resume at the
 * first incomplete step.
 *
 * The from/to academic years and the operator are exposed as plain ids, not
 * relations - Academics and Identity rows are read via DB::table by the
 * Actions (module-boundary rule).
 *
 * @property int $id
 * @property int $academic_year_from_id
 * @property int|null $academic_year_to_id
 * @property int $current_step
 * @property array<int|string, mixed>|null $step_states
 * @property string|null $inputs_hash
 * @property string $status
 * @property int $operator_id
 * @property int|null $backup_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RolloverRun extends Model
{
    /** @use HasFactory<RolloverRunFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'academic_year_from_id' => 'integer',
            'academic_year_to_id' => 'integer',
            'current_step' => 'integer',
            'step_states' => 'array',
            'operator_id' => 'integer',
            'backup_id' => 'integer',
        ];
    }

    public function status(): RolloverRunStatus
    {
        return RolloverRunStatus::from($this->status);
    }

    public function currentStep(): RolloverStep
    {
        return RolloverStep::from($this->current_step);
    }

    /**
     * @return HasMany<RolloverArtifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(RolloverArtifact::class);
    }

    /**
     * @return HasMany<RolloverBalanceCarry, $this>
     */
    public function balanceCarries(): HasMany
    {
        return $this->hasMany(RolloverBalanceCarry::class);
    }

    /**
     * The mandatory verified pre-rollover backup (§6.2 step 0).
     *
     * @return BelongsTo<Backup, $this>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'backup_id');
    }

    protected static function newFactory(): RolloverRunFactory
    {
        return RolloverRunFactory::new();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A referral out of the sick bay (docs/plans/phase-10.md §3 row 9), always
 * anchored to the consultation that prompted it. OPEN until followed_up_at
 * is set by CloseReferral - the timestamp IS the state. `reason` and
 * `notes` are clinical narrative about a minor and carry the `'encrypted'`
 * cast (StudentMedicalRecord.detail pattern).
 *
 * @property int $id
 * @property int $consultation_id
 * @property string $referred_to
 * @property string $reason
 * @property Carbon $referred_on
 * @property Carbon|null $followed_up_at
 * @property string|null $notes
 * @property int|null $referred_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MedicalReferral extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'consultation_id', 'referred_to', 'reason', 'referred_on',
        'followed_up_at', 'notes', 'referred_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consultation_id' => 'integer',
            // 00-core 9.5: health data about a minor, never plaintext at rest.
            'reason' => 'encrypted',
            'notes' => 'encrypted',
            'referred_on' => 'date',
            'followed_up_at' => 'datetime',
            'referred_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MedicalConsultation, $this>
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class, 'consultation_id');
    }

    /**
     * @param  Builder<MedicalReferral>  $query
     * @return Builder<MedicalReferral>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('followed_up_at');
    }
}

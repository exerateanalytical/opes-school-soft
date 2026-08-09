<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Models;

use App\Modules\Welfare\Domain\VisitorHostType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One gate-register row (docs/plans/phase-10.md §3 row 10): a visitor is on
 * site exactly while checked_out_at is NULL, and the schema holds the
 * physical-badge invariant (active_badge_key NULL-unique: one badge, one
 * neck). id_document_ref is identity data about a private individual and
 * carries the `'encrypted'` cast exactly as StudentMedicalRecord.detail
 * does: the DB column holds ciphertext, only model reads decrypt. host_id
 * is a plain integer, NOT a relation - it points at users OR students
 * depending on host_type, and Welfare never reaches into other modules'
 * Models (ModuleBoundaryTest); host identity is joined via DB::table inside
 * the screen.
 *
 * @property int $id
 * @property string $visitor_name
 * @property string $phone
 * @property string|null $id_document_ref
 * @property string $purpose
 * @property VisitorHostType $host_type
 * @property int|null $host_id
 * @property string $badge_no
 * @property Carbon $checked_in_at
 * @property Carbon|null $checked_out_at
 * @property string|null $gate_pass_no
 * @property int|null $logged_by
 * @property string|null $active_badge_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class VisitorLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'visitor_name', 'phone', 'id_document_ref', 'purpose',
        'host_type', 'host_id', 'badge_no',
        'checked_in_at', 'checked_out_at', 'gate_pass_no', 'logged_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Identity document reference: never plaintext at rest.
            'id_document_ref' => 'encrypted',
            'host_type' => VisitorHostType::class,
            'host_id' => 'integer',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'logged_by' => 'integer',
        ];
    }

    /**
     * Visitors currently inside the fence.
     *
     * @param  Builder<VisitorLog>  $query
     * @return Builder<VisitorLog>
     */
    public function scopeOnSite(Builder $query): Builder
    {
        return $query->whereNull('checked_out_at');
    }
}

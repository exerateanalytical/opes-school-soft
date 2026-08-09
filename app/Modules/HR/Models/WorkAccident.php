<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\HR\Domain\WorkAccidentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/05-hr-payroll.md 11.5. The CNPS declaration deadline is NEEDS
 * VERIFICATION, so no due date is fabricated; `declared` requires the
 * recorded declared_to_cnps_at (CHECK in the migration).
 *
 * @property int $id
 * @property int $staff_member_id
 * @property Carbon $occurred_at
 * @property string $location
 * @property string $description
 * @property string|null $witness_names
 * @property Carbon|null $declared_to_cnps_at
 * @property string|null $cnps_reference
 * @property int|null $medical_certificate_document_id
 * @property numeric-string $days_lost
 * @property WorkAccidentStatus $status
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class WorkAccident extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_member_id',
        'occurred_at',
        'location',
        'description',
        'witness_names',
        'declared_to_cnps_at',
        'cnps_reference',
        'medical_certificate_document_id',
        'days_lost',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'declared_to_cnps_at' => 'datetime',
            'days_lost' => 'decimal:1',
            'status' => WorkAccidentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<StaffMember, $this>
     */
    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_member_id');
    }
}

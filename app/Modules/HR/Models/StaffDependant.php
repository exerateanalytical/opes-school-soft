<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Replaces the deleted `dependants_count` (docs/specs/05-hr-payroll.md 3.6).
 *
 * Feeds CNPS family-allowance ENTITLEMENT - a benefit CNPS pays. It is not an
 * input to any contribution rate (the PF rate is a flat sector rate) and it
 * is NOT an input to IRPP (defect N3: Cameroonian salary IRPP has no
 * quotient familial and no dependants relief).
 *
 * @property int $id
 * @property int $staff_member_id
 * @property string $full_name
 * @property string $relationship
 * @property Carbon $date_of_birth
 * @property bool $is_schooled
 * @property bool $cnps_allowance_eligible
 * @property int|null $birth_certificate_document_id
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StaffDependant extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_member_id',
        'full_name',
        'relationship',
        'date_of_birth',
        'is_schooled',
        'cnps_allowance_eligible',
        'birth_certificate_document_id',
        'valid_from',
        'valid_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_schooled' => 'boolean',
            'cnps_allowance_eligible' => 'boolean',
            'birth_certificate_document_id' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StaffMember, $this>
     */
    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use Database\Factories\StaffMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A staff directory record. HR proper (contracts, grades, payroll) is Phase 11;
 * this model carries only what is needed to name a member of staff.
 *
 * @property int $id
 * @property string $staff_no
 * @property string $first_name
 * @property string $last_name
 * @property string|null $other_names
 * @property string $gender
 * @property Carbon|null $date_of_birth
 * @property string $phone
 * @property string|null $email
 * @property string|null $photo_path
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StaffMember extends Model
{
    /** @use HasFactory<StaffMemberFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'staff_no',
        'first_name',
        'last_name',
        'other_names',
        'gender',
        'date_of_birth',
        'phone',
        'email',
        'photo_path',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    /**
     * The name as it is read out: given names first, family name last. Other
     * names are dropped rather than abbreviated - an initial that is not on
     * the person's papers is worse than no initial.
     */
    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * @param  Builder<StaffMember>  $query
     * @return Builder<StaffMember>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     */
    protected static function newFactory(): StaffMemberFactory
    {
        return StaffMemberFactory::new();
    }
}

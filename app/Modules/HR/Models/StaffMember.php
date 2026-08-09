<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\HR\Domain\CnpsRegistrationStatus;
use Database\Factories\StaffMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * Identity, statutory identifiers and payment coordinates - NOTHING
 * employment-related (docs/specs/05-hr-payroll.md 3.3, fixing defect H6).
 * Position, grade, dates and pay live on StaffContract; dependants live on
 * StaffDependant (`dependants_count` does not exist - defect N3: it was never
 * an IRPP input); the risk class lives on the EMPLOYER profile (H2).
 *
 * @property int $id
 * @property string $staff_no
 * @property string $first_name
 * @property string $last_name
 * @property string|null $other_names
 * @property string $gender
 * @property Carbon|null $date_of_birth
 * @property string|null $place_of_birth
 * @property string $nationality
 * @property string $phone
 * @property string|null $email
 * @property string|null $photo_path
 * @property string|null $national_id_type
 * @property string|null $national_id_number
 * @property string|null $national_id_blind_index
 * @property string|null $cnps_number
 * @property string|null $cnps_number_blind_index
 * @property CnpsRegistrationStatus $cnps_registration_status
 * @property Carbon|null $cnps_registered_on
 * @property Carbon|null $cnps_registration_deadline
 * @property string|null $niu
 * @property string|null $bank_name
 * @property string|null $bank_account
 * @property string|null $mobile_money_number
 * @property string|null $marital_status
 * @property string|null $next_of_kin_name
 * @property string|null $next_of_kin_relationship
 * @property string|null $next_of_kin_phone
 * @property int|null $photo_document_id
 * @property string $status
 * @property bool $is_archived
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StaffMember extends Model
{
    /** @use HasFactory<StaffMemberFactory> */
    use HasFactory;

    /**
     * 11.5: CNPS worker registration is due within 8 days of hire.
     */
    public const CNPS_REGISTRATION_DAYS = 8;

    /** @var list<string> */
    protected $fillable = [
        'staff_no',
        'first_name',
        'last_name',
        'other_names',
        'gender',
        'date_of_birth',
        'place_of_birth',
        'nationality',
        'phone',
        'email',
        'photo_path',
        'national_id_type',
        'national_id_number',
        'national_id_blind_index',
        'cnps_number',
        'cnps_number_blind_index',
        'cnps_registration_status',
        'cnps_registered_on',
        'cnps_registration_deadline',
        'niu',
        'bank_name',
        'bank_account',
        'mobile_money_number',
        'marital_status',
        'next_of_kin_name',
        'next_of_kin_relationship',
        'next_of_kin_phone',
        'photo_document_id',
        'status',
        'is_archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            // 00-core 9.5. Non-deterministic, therefore unsearchable - which
            // is exactly why the blind-index columns exist beside them.
            'national_id_number' => 'encrypted',
            'cnps_number' => 'encrypted',
            'bank_name' => 'encrypted',
            'bank_account' => 'encrypted',
            'mobile_money_number' => 'encrypted',
            'cnps_registration_status' => CnpsRegistrationStatus::class,
            'cnps_registered_on' => 'date',
            'cnps_registration_deadline' => 'date',
            'photo_document_id' => 'integer',
            'is_archived' => 'boolean',
            'version' => 'integer',
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
     * @return HasMany<StaffContract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(StaffContract::class);
    }

    /**
     * @return HasMany<StaffDependant, $this>
     */
    public function dependants(): HasMany
    {
        return $this->hasMany(StaffDependant::class);
    }

    /**
     * The HMAC blind index for an encrypted identifier (00-core 9.5) - the
     * same construction as Guardian/Student: keyed on the application key so
     * the value survives a restore, canonicalised to upper-case alphanumerics
     * first because `CM-123 456`, `cm123456` and `CM/123456` are one national
     * ID card transcribed by three different clerks.
     */
    public static function blindIndexFor(?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }

        $canonical = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $identifier));

        if ($canonical === '') {
            return null;
        }

        return hash_hmac('sha256', $canonical, (string) Config::string('app.key'));
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

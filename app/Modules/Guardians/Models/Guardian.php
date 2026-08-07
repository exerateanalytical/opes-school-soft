<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Models;

use App\Modules\Guardians\Domain\Gender;
use App\Modules\Guardians\Domain\GuardianIdType;
use App\Modules\Guardians\Domain\GuardianLanguage;
use App\Modules\Guardians\Domain\GuardianStatus;
use App\Modules\Guardians\Domain\MaritalStatus;
use App\Modules\Guardians\Domain\PhoneNumber;
use App\Modules\Guardians\Domain\PreferredContactMethod;
use App\Modules\Guardians\Domain\ResidentialStatus;
use Database\Factories\GuardianFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * docs/specs/07-students.md 7.1.
 *
 * @property int $id
 * @property string $guardian_no
 * @property string|null $title
 * @property string $first_name
 * @property string $last_name
 * @property Carbon|null $date_of_birth
 * @property Gender $gender
 * @property string $nationality
 * @property GuardianIdType|null $id_type
 * @property string|null $id_number
 * @property string|null $id_number_blind_index
 * @property string|null $occupation
 * @property string|null $employer
 * @property MaritalStatus|null $marital_status
 * @property string $phone
 * @property string|null $alternative_phone
 * @property string|null $email
 * @property string|null $address_line
 * @property string|null $city
 * @property string|null $region
 * @property string|null $country
 * @property ResidentialStatus|null $residential_status
 * @property PreferredContactMethod $preferred_contact_method
 * @property GuardianLanguage $language
 * @property string|null $emergency_contact_name
 * @property string|null $emergency_contact_phone
 * @property string|null $emergency_contact_relationship
 * @property string|null $emergency_contact_address
 * @property string|null $photo_path
 * @property GuardianStatus $status
 * @property bool $notify_sms
 * @property bool $notify_email
 * @property bool $notify_push
 * @property bool $receives_reports
 * @property bool $receives_invoices
 * @property int|null $portal_user_id
 * @property bool $is_archived
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Guardian extends Model
{
    /** @use HasFactory<GuardianFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'guardian_no',
        'title',
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'nationality',
        'id_type',
        'id_number',
        'id_number_blind_index',
        'occupation',
        'employer',
        'marital_status',
        'phone',
        'alternative_phone',
        'email',
        'address_line',
        'city',
        'region',
        'country',
        'residential_status',
        'preferred_contact_method',
        'language',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'emergency_contact_address',
        'photo_path',
        'status',
        'notify_sms',
        'notify_email',
        'notify_push',
        'receives_reports',
        'receives_invoices',
        'portal_user_id',
        'is_archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'id_type' => GuardianIdType::class,
            // 00-core 9.5. Non-deterministic, therefore unsearchable - which
            // is exactly why `id_number_blind_index` exists beside it.
            'id_number' => 'encrypted',
            'marital_status' => MaritalStatus::class,
            'residential_status' => ResidentialStatus::class,
            'preferred_contact_method' => PreferredContactMethod::class,
            'language' => GuardianLanguage::class,
            'status' => GuardianStatus::class,
            'notify_sms' => 'boolean',
            'notify_email' => 'boolean',
            'notify_push' => 'boolean',
            'receives_reports' => 'boolean',
            'receives_invoices' => 'boolean',
            'portal_user_id' => 'integer',
            'is_archived' => 'boolean',
        ];
    }

    /**
     * The 7.7 tier-1 match key.
     *
     * Keyed on the application key rather than a random salt so that the value
     * is reproducible across a restore: a blind index that changes when the
     * app boots would silently stop detecting duplicates, and nothing would
     * fail.
     *
     * Canonicalised to upper-case alphanumerics first, because `CM-123 456`,
     * `cm123456` and `CM/123456` are one national ID card transcribed by three
     * different clerks. Without this the index is exact on the PUNCTUATION as
     * much as on the number, and tier 1 - the near-certain tier - quietly
     * degrades to catching only clerks who type identically.
     */
    public static function blindIndexFor(?string $idNumber): ?string
    {
        if ($idNumber === null) {
            return null;
        }

        $canonical = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $idNumber));

        if ($canonical === '') {
            return null;
        }

        return hash_hmac('sha256', $canonical, (string) Config::string('app.key'));
    }

    /** E.164, so that 7.7's tier-2 exact match can actually be exact. */
    public static function normalisePhone(?string $phone): ?string
    {
        return PhoneNumber::normalise($phone);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isActive(): bool
    {
        return $this->status === GuardianStatus::Active;
    }

    /**
     * @return HasMany<StudentGuardian, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    protected static function newFactory(): GuardianFactory
    {
        return GuardianFactory::new();
    }
}

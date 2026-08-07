<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Models;

use App\Modules\Admissions\Domain\ApplicantRelationship;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Guardian data captured at step 3, docs/specs/07-students.md 6.1.
 *
 * Explicitly NOT a Guardian. These rows are form state on a draft; the real
 * Guardian is matched-or-created at conversion (6.3 step 5, 7.7). The
 * authorization booleans here are PROPOSALS - nothing is authorised until a
 * StudentGuardian link exists.
 *
 * @property int $id
 * @property int $admission_application_id
 * @property int $position
 * @property string|null $title
 * @property string $first_name
 * @property string $last_name
 * @property string $gender
 * @property Carbon|null $date_of_birth
 * @property ApplicantRelationship $relationship
 * @property string|null $relationship_other
 * @property bool $is_primary
 * @property string|null $id_type
 * @property string|null $id_number
 * @property string|null $occupation
 * @property string|null $employer
 * @property string $phone
 * @property string|null $alternative_phone
 * @property string|null $email
 * @property string|null $address_line
 * @property string|null $city
 * @property string|null $region
 * @property string $language
 * @property bool $has_custody
 * @property bool $receives_reports
 * @property bool $receives_invoices
 * @property bool $is_emergency_contact
 * @property bool $is_authorised_for_pickup
 * @property bool $is_fee_payer
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AdmissionApplicationGuardian extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'admission_application_id',
        'position',
        'title',
        'first_name',
        'last_name',
        'gender',
        'date_of_birth',
        'relationship',
        'relationship_other',
        'is_primary',
        'id_type',
        'id_number',
        'occupation',
        'employer',
        'phone',
        'alternative_phone',
        'email',
        'address_line',
        'city',
        'region',
        'language',
        'has_custody',
        'receives_reports',
        'receives_invoices',
        'is_emergency_contact',
        'is_authorised_for_pickup',
        'is_fee_payer',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'admission_application_id' => 'integer',
            'position' => 'integer',
            'date_of_birth' => 'date',
            'relationship' => ApplicantRelationship::class,
            'is_primary' => 'boolean',
            'has_custody' => 'boolean',
            'receives_reports' => 'boolean',
            'receives_invoices' => 'boolean',
            'is_emergency_contact' => 'boolean',
            'is_authorised_for_pickup' => 'boolean',
            'is_fee_payer' => 'boolean',

            // Same protection the real Guardian gives it (7.1).
            'id_number' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<AdmissionApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** How the relationship reads on screen, honouring the free-text case. */
    public function relationshipLabel(string $locale = 'en'): string
    {
        if ($this->relationship->requiresFreeText() && is_string($this->relationship_other) && $this->relationship_other !== '') {
            return $this->relationship_other;
        }

        return $this->relationship->label($locale);
    }
}

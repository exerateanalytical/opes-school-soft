<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Database\Factories\EmployerProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The school as an EMPLOYER (docs/specs/05-hr-payroll.md 3.1) - effective-
 * dated because a CNPS regime reclassification or risk-class change applies
 * FROM a date and must not rewrite prior payslips.
 *
 * `cnps_regime` and `rp_risk_class` are transcribed from the CNPS
 * notification letter (referenced NOT NULL) and drive every employer
 * contribution the school pays - defects N2 and H2 live and die here.
 *
 * `proration_basis`, `ceiling_prorates_partial_month`: NULL until the
 * customer decides (2.4). No default. A run containing any partial month
 * fails preflight while they are NULL.
 *
 * `cnps_regime`, `proration_basis` and `irpp_mode` are string-typed here on
 * purpose: their enums belong to the Payroll Domain package (F2's scope) and
 * this model must not race it. The DB ENUM constrains the values either way.
 *
 * @property int $id
 * @property string $cnps_employer_number
 * @property string $dipe_number
 * @property string $niu
 * @property string|null $dgi_centre
 * @property int $tdl_commune_id
 * @property string $cnps_regime
 * @property string $rp_risk_class
 * @property int $cnps_notification_document_id
 * @property string $cnps_notification_reference
 * @property string|null $proration_basis
 * @property bool|null $ceiling_prorates_partial_month
 * @property string $irpp_mode
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class EmployerProfile extends Model
{
    /** @use HasFactory<EmployerProfileFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'cnps_employer_number',
        'dipe_number',
        'niu',
        'dgi_centre',
        'tdl_commune_id',
        'cnps_regime',
        'rp_risk_class',
        'cnps_notification_document_id',
        'cnps_notification_reference',
        'proration_basis',
        'ceiling_prorates_partial_month',
        'irpp_mode',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tdl_commune_id' => 'integer',
            'cnps_notification_document_id' => 'integer',
            'ceiling_prorates_partial_month' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'created_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Commune, $this>
     */
    public function tdlCommune(): BelongsTo
    {
        return $this->belongsTo(Commune::class, 'tdl_commune_id');
    }

    /**
     * The profile version in force at a payroll period END date (4.3: the
     * period end drives selection, never the run date).
     *
     * @param  Builder<EmployerProfile>  $query
     * @return Builder<EmployerProfile>
     */
    public function scopeCovering(Builder $query, string $date): Builder
    {
        return $query
            ->where('effective_from', '<=', $date)
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $date);
            });
    }

    /**
     * Both proration decisions taken (2.4)? While false, any run containing
     * a partial month fails preflight check 3.
     */
    public function prorationConfigured(): bool
    {
        return $this->proration_basis !== null
            && $this->ceiling_prorates_partial_month !== null;
    }

    protected static function newFactory(): EmployerProfileFactory
    {
        return EmployerProfileFactory::new();
    }
}

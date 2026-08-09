<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Domain\BracketBasis;
use App\Modules\Payroll\Domain\CnpsRegime;
use App\Modules\Payroll\Domain\RateBasis;
use App\Modules\Payroll\Domain\RateShape;
use Database\Factories\StatutoryRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A statutory rate row (docs/specs/05-hr-payroll.md 4). Ships as a SHELL -
 * amount columns NULL, `is_verified = false`, invisible to the engine -
 * until the bursar configures it from the school's own CNPS notification
 * letter or DGI notice. `locked` rows are append-only, enforced by BEFORE
 * UPDATE/DELETE triggers; "changing a rate" is always close-and-supersede.
 *
 * Rates are integer basis points at Rate::SCALE (100 000 = 100%);
 * `flat_amount`, `ceiling_amount`, bands are SIGNED whole FCFA.
 *
 * @property int $id
 * @property string $code
 * @property string $label
 * @property string|null $label_fr
 * @property RateShape $shape
 * @property RateBasis $basis
 * @property BracketBasis|null $bracket_basis
 * @property int|null $employee_rate_bp
 * @property int|null $employer_rate_bp
 * @property int|null $flat_amount
 * @property int|null $ceiling_amount
 * @property int|null $floor_amount
 * @property int|null $band_from
 * @property int|null $band_to
 * @property string|null $risk_class
 * @property CnpsRegime|null $cnps_regime
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string $source_citation
 * @property int|null $source_document_id
 * @property bool $is_verified
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property bool $locked
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StatutoryRate extends Model
{
    /** @use HasFactory<StatutoryRateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'label',
        'label_fr',
        'shape',
        'basis',
        'bracket_basis',
        'employee_rate_bp',
        'employer_rate_bp',
        'flat_amount',
        'ceiling_amount',
        'floor_amount',
        'band_from',
        'band_to',
        'risk_class',
        'cnps_regime',
        'effective_from',
        'effective_to',
        'source_citation',
        'source_document_id',
        'is_verified',
        'verified_by',
        'verified_at',
        'locked',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'shape' => RateShape::class,
            'basis' => RateBasis::class,
            'bracket_basis' => BracketBasis::class,
            'employee_rate_bp' => 'integer',
            'employer_rate_bp' => 'integer',
            'flat_amount' => 'integer',
            'ceiling_amount' => 'integer',
            'floor_amount' => 'integer',
            'band_from' => 'integer',
            'band_to' => 'integer',
            'cnps_regime' => CnpsRegime::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
            'locked' => 'boolean',
        ];
    }

    protected static function newFactory(): StatutoryRateFactory
    {
        return StatutoryRateFactory::new();
    }
}

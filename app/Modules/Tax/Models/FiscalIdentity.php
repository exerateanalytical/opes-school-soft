<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use App\Modules\Tax\Domain\LegalForm;
use App\Modules\Tax\Domain\TaxCentreType;
use App\Modules\Tax\Domain\TaxRegime;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * docs/specs/03-tax-procurement.md §2 - the school's fiscal identity.
 * Singleton row (CHECK id = 1 at the database); read by every document
 * renderer, so it also exposes the §2.2 inv. 5 completeness check.
 *
 * NIU immutability (§2.2 inv. 1): once `fiscal_identity_confirmed_at` is
 * set, the observer below rejects any change to `niu` unless it flows
 * through CorrectFiscalIdentity, which opens the one-shot bypass. A NIU
 * typo silently propagates onto every printed invoice and every filed
 * declaration - hence the ceremony.
 *
 * @property int $id
 * @property string|null $legal_name
 * @property LegalForm|null $legal_form
 * @property string|null $niu
 * @property \Illuminate\Support\Carbon|null $niu_issued_on
 * @property string|null $rccm_number
 * @property string|null $rccm_registry
 * @property \Illuminate\Support\Carbon|null $rccm_registered_on
 * @property string|null $tax_centre_code
 * @property string|null $tax_centre_name
 * @property TaxCentreType|null $tax_centre_type
 * @property TaxRegime|null $tax_regime
 * @property \Illuminate\Support\Carbon|null $tax_regime_effective_from
 * @property bool $is_tva_registered
 * @property \Illuminate\Support\Carbon|null $tva_registered_from
 * @property string|null $ministry_accreditation_number
 * @property string|null $ministry_accreditation_authority
 * @property \Illuminate\Support\Carbon|null $ministry_accreditation_date
 * @property \Illuminate\Support\Carbon|null $ministry_accreditation_expires_on
 * @property int|null $ministry_accreditation_document_id
 * @property int $fiscal_year_end_month
 * @property int $fiscal_year_end_day
 * @property int|null $fiscal_identity_confirmed_by
 * @property \Illuminate\Support\Carbon|null $fiscal_identity_confirmed_at
 */
final class FiscalIdentity extends Model
{
    public const SINGLETON_ID = 1;

    protected $table = 'fiscal_identities';

    /** Singleton PK is assigned explicitly, never auto-incremented. */
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'legal_name',
        'legal_form',
        'niu',
        'niu_issued_on',
        'rccm_number',
        'rccm_registry',
        'rccm_registered_on',
        'tax_centre_code',
        'tax_centre_name',
        'tax_centre_type',
        'tax_regime',
        'tax_regime_effective_from',
        'is_tva_registered',
        'tva_registered_from',
        'ministry_accreditation_number',
        'ministry_accreditation_authority',
        'ministry_accreditation_date',
        'ministry_accreditation_expires_on',
        'ministry_accreditation_document_id',
        'fiscal_year_end_month',
        'fiscal_year_end_day',
        'fiscal_identity_confirmed_by',
        'fiscal_identity_confirmed_at',
    ];

    /**
     * One-shot bypass for the NIU-immutability observer, opened ONLY by
     * CorrectFiscalIdentity inside its transaction. Static because the
     * observer fires on a different model instance than the Action holds.
     */
    private static bool $niuCorrectionInProgress = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legal_form' => LegalForm::class,
            'tax_centre_type' => TaxCentreType::class,
            'tax_regime' => TaxRegime::class,
            'niu_issued_on' => 'date',
            'rccm_registered_on' => 'date',
            'tax_regime_effective_from' => 'date',
            'is_tva_registered' => 'boolean',
            'tva_registered_from' => 'date',
            'ministry_accreditation_date' => 'date',
            'ministry_accreditation_expires_on' => 'date',
            'fiscal_year_end_month' => 'integer',
            'fiscal_year_end_day' => 'integer',
            'fiscal_identity_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (FiscalIdentity $identity): void {
            if (! $identity->isDirty('niu')) {
                return;
            }

            if ($identity->getOriginal('fiscal_identity_confirmed_at') === null) {
                return;
            }

            if (self::$niuCorrectionInProgress) {
                return;
            }

            throw new DomainException(
                'The NIU is immutable once the fiscal identity is confirmed; '
                .'use CorrectFiscalIdentity with a reason and supporting document (03-tax-procurement §2.2).'
            );
        });
    }

    public static function current(): ?self
    {
        return self::query()->find(self::SINGLETON_ID);
    }

    /**
     * Run $callback with the NIU-immutability observer bypassed. Only
     * CorrectFiscalIdentity may call this; the flag is always restored.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withNiuCorrection(callable $callback): mixed
    {
        self::$niuCorrectionInProgress = true;

        try {
            return $callback();
        } finally {
            self::$niuCorrectionInProgress = false;
        }
    }

    public function isConfirmed(): bool
    {
        return $this->fiscal_identity_confirmed_at !== null;
    }

    /**
     * §2.2 inv. 5 - the fields without which no document may print.
     *
     * @return list<string> the missing field names, empty when complete
     */
    public function missingDocumentIdentityFields(): array
    {
        $missing = [];

        foreach (['niu', 'tax_regime', 'tax_centre_name', 'legal_name'] as $field) {
            $value = $this->getAttribute($field);

            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * §5.2 - is the ministry accreditation present and unexpired at $date?
     * Null expiry = indefinite.
     */
    public function hasValidAccreditationOn(string $date): bool
    {
        $number = $this->ministry_accreditation_number;

        if ($number === null || trim($number) === '') {
            return false;
        }

        $expiresOn = $this->ministry_accreditation_expires_on;

        return $expiresOn === null || $expiresOn->toDateString() >= $date;
    }
}

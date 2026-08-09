<?php

declare(strict_types=1);

namespace App\Modules\Tax\Models;

use App\Modules\Tax\Domain\ProrataRounding;
use App\Modules\Tax\Domain\WithholdingRecognition;
use Illuminate\Database\Eloquent\Model;

/**
 * docs/specs/03-tax-procurement.md §5.4 / §6.3 - the tax-engine switches
 * whose legally correct values are NEEDS VERIFICATION. Singleton (CHECK
 * id = 1); NULL means unconfigured-and-blocking, per 00-core §16.
 *
 * @property int $id
 * @property WithholdingRecognition|null $withholding_recognition
 * @property ProrataRounding|null $prorata_rounding
 * @property int|null $confirmed_by
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 */
final class TaxSettings extends Model
{
    public const SINGLETON_ID = 1;

    protected $table = 'tax_settings';

    /** Singleton PK is assigned explicitly, never auto-incremented. */
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'withholding_recognition',
        'prorata_rounding',
        'confirmed_by',
        'confirmed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'withholding_recognition' => WithholdingRecognition::class,
            'prorata_rounding' => ProrataRounding::class,
            'confirmed_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return self::query()->find(self::SINGLETON_ID);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * docs/specs/03-tax-procurement.md §4.2 - the ordered approval bands: a
 * 5,000,000 FCFA order needs the Principal, a 50,000 one the Bursar.
 * `required_role` stores an Identity Role enum value as a string; bands are
 * evaluated in `sequence` order and the FIRST band containing the amount
 * decides.
 *
 * @property int $id
 * @property int $min_amount
 * @property int|null $max_amount
 * @property string $required_role
 * @property int $sequence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ApprovalThreshold extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'min_amount',
        'max_amount',
        'required_role',
        'sequence',
    ];

    /** The first band (by sequence) whose [min, max] contains the amount. */
    public static function bandFor(int $amount): ?self
    {
        /** @var self|null $band */
        $band = self::query()
            ->where('min_amount', '<=', $amount)
            ->where(function ($query) use ($amount): void {
                $query->whereNull('max_amount')->orWhere('max_amount', '>=', $amount);
            })
            ->orderBy('sequence')
            ->first();

        return $band;
    }
}

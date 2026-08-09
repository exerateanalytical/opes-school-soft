<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The TDL payee (docs/specs/05-hr-payroll.md 3.1, 11.2): the Taxe de
 * Developpement Local collected from staff is remitted to a commune, per the
 * commune's own schedule, not to the DGI.
 *
 * @property int $id
 * @property string $name
 * @property string|null $region
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Commune extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'region',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}

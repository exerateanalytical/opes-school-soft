<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A convention collective (docs/specs/05-hr-payroll.md 2.4). The table SHIPS
 * EMPTY: which national convention covers the customer school is NEEDS
 * VERIFICATION, and `is_verified` stays FALSE until the customer confirms -
 * an unverified agreement must not classify anybody.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $reference
 * @property int|null $source_document_id
 * @property bool $is_verified
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CollectiveAgreement extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'reference',
        'source_document_id',
        'is_verified',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_document_id' => 'integer',
            'is_verified' => 'boolean',
        ];
    }
}

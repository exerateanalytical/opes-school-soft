<?php

declare(strict_types=1);

namespace App\Modules\Operations\Models;

use Database\Factories\LicenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The locally cached licence (docs/specs/08-operations.md §4.2-§4.3). A
 * cache, never an authority: the payload+signature pair is re-verified
 * offline on every status check. This model carries NO validity logic -
 * that belongs to the Licensing services, which must never trust a database
 * row that has not just passed signature verification.
 *
 * @property int $id
 * @property array<string, mixed> $payload
 * @property string $signature
 * @property string|null $fingerprint
 * @property string $source
 * @property Carbon|null $expires_at
 * @property Carbon|null $next_check_after
 * @property int|null $grace_days
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Licence extends Model
{
    /** @use HasFactory<LicenceFactory> */
    use HasFactory;

    public const SOURCE_FILE = 'file';

    public const SOURCE_ACTIVATION = 'activation';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'date',
            'next_check_after' => 'datetime',
            'grace_days' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): LicenceFactory
    {
        return LicenceFactory::new();
    }
}

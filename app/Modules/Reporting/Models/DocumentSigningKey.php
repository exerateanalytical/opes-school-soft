<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * docs/specs/10-documents.md 17.1 - one row per ECDSA P-256 keypair this
 * instance has ever signed QR verification tokens with.
 *
 * Exactly ONE row is active (enforced by RotateDocumentSigningKey's
 * transaction, not a partial index MySQL cannot express); retired keys stay
 * forever because retirement stops SIGNING, never VERIFICATION - a
 * certificate printed under key 1 must still verify after ten rotations.
 *
 * The private key is ENCRYPTED AT REST via the cast below. The public key is
 * plaintext BY DESIGN: 17.1 prints it on the recovery sheet and publishes it
 * in the About window, because an offline verifier needs nothing else.
 *
 * @property int $id
 * @property string $key_id
 * @property string $private_key
 * @property string $public_key
 * @property string $algorithm
 * @property bool $is_active
 * @property Carbon $activated_at
 * @property Carbon|null $retired_at
 * @property int|null $created_by
 */
final class DocumentSigningKey extends Model
{
    public const ALGORITHM_ES256 = 'ES256';

    /** @var list<string> */
    protected $fillable = [
        'key_id', 'private_key', 'public_key', 'algorithm',
        'is_active', 'activated_at', 'retired_at', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** The key that signs NEW tokens - null only before first provisioning. */
    public static function active(): ?self
    {
        return self::query()->where('is_active', true)->orderByDesc('id')->first();
    }
}

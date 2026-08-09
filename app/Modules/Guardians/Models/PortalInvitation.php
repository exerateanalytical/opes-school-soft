<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Models;

use App\Modules\Guardians\Domain\PortalSubjectType;
use Database\Factories\PortalInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An admin-issued portal activation code (docs/plans/phase-12-13.md 12.2,
 * migration 2026_08_09_300003).
 *
 * Only the SHA-256 hash of the code is stored. The plaintext exists exactly
 * once, in the return value of IssuePortalInvitation, for the issuing screen
 * to show; this model can neither produce nor verify a code on its own -
 * hashing and normalisation live in the Actions so there is one write path
 * and one read path.
 *
 * @property int $id
 * @property PortalSubjectType $subject_type
 * @property int $subject_id
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int|null $used_by_user_id
 * @property Carbon|null $revoked_at
 * @property int $issued_by
 * @property Carbon $issued_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PortalInvitation extends Model
{
    /** @use HasFactory<PortalInvitationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'subject_type',
        'subject_id',
        'code_hash',
        'expires_at',
        'used_at',
        'used_by_user_id',
        'revoked_at',
        'issued_by',
        'issued_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_type' => PortalSubjectType::class,
            'subject_id' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'used_by_user_id' => 'integer',
            'revoked_at' => 'datetime',
            'issued_by' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * Still redeemable at $now: never used, never revoked, not yet expired.
     * One method so the issue flow (revoke my predecessors), the activation
     * flow (may I redeem) and the admin panel (what do I show) cannot drift
     * on what "open" means.
     */
    public function isOpen(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->greaterThanOrEqualTo($now);
    }

    protected static function newFactory(): PortalInvitationFactory
    {
        return PortalInvitationFactory::new();
    }
}

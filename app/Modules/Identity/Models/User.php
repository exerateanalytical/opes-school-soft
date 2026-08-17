<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Support\Audit\Actor;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $username
 * @property bool $is_official
 * @property string $status
 * @property string|null $password
 * @property \Illuminate\Support\Carbon|null $must_change_password_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    // Phase 12 (docs/plans/phase-12-13.md 12.4): personal access tokens for
    // the read-only v1 API. Token abilities are Permission enum values; the
    // `can:` route gates still check the USER's permissions, so a token can
    // only ever narrow what its owner may already do, never widen it.
    use HasApiTokens;

    use HasRoles;
    use Notifiable;

    /** @var list<string> */
    // `is_official` is deliberately NOT fillable: the blue tick is worth
    // nothing if a mass-assigned array can award it. Identity\Actions\
    // MarkUserOfficial sets the attribute directly, under `user.manage`.
    protected $fillable = ['name', 'email', 'username', 'password', 'status'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_official' => 'boolean',
            'must_change_password_at' => 'datetime',
        ];
    }

    /**
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     *
     * @return UserFactory
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Identity owns the conversion, so no other module needs to know about
     * this class in order to attribute an audit entry (00-core 6.2).
     */
    public function toAuditActor(): Actor
    {
        return new Actor((int) $this->getKey(), $this->name);
    }
}

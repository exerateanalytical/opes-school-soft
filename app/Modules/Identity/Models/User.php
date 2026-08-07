<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Support\Audit\Actor;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $status
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'status'];

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

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 */
class RecoveryCredential extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<RecoveryCredential>  $query
     * @return Builder<RecoveryCredential>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }
}

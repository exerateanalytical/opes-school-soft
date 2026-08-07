<?php

declare(strict_types=1);

namespace App\Modules\Operations\Models;

use App\Modules\Operations\Domain\BackupKind;
use App\Modules\Operations\Domain\BackupStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $kind
 * @property string $status
 * @property string $path
 * @property string|null $sha256
 * @property int|null $size_bytes
 * @property array<string, mixed>|null $manifest
 * @property Carbon|null $completed_at
 * @property Carbon|null $verified_at
 * @property string|null $failure_detail
 */
class Backup extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function kind(): BackupKind
    {
        return BackupKind::from($this->kind);
    }

    public function status(): BackupStatus
    {
        return BackupStatus::from($this->status);
    }

    /**
     * @param  Builder<Backup>  $query
     * @return Builder<Backup>
     */
    public function scopeHealthy(Builder $query): Builder
    {
        return $query->where('status', BackupStatus::Healthy->value);
    }
}

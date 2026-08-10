<?php

declare(strict_types=1);

namespace App\Modules\Forms\Models;

use App\Modules\Forms\Domain\DraftStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $form_key
 * @property array<string, mixed> $payload
 * @property DraftStatus $status
 * @property \Illuminate\Support\Carbon|null $held_at
 */
final class FormDraft extends Model
{
    protected $table = 'form_drafts';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'form_key', 'subject_type', 'subject_id',
        'payload', 'status', 'held_at', 'hold_label',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => DraftStatus::class,
            'held_at' => 'datetime',
        ];
    }
}

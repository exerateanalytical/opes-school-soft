<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * THE AUTHORITATIVE RECORD (docs/specs/05-hr-payroll.md 10, fixing C7).
 * Written once at approval; INSERT-only is enforced by BEFORE
 * UPDATE/DELETE triggers that reject unconditionally. Every re-render,
 * re-export and audit read reads THIS - never a recomputation.
 *
 * UPDATED_AT is absent on purpose: a row that can never change needs no
 * update stamp, and the triggers would reject Eloquent touching it anyway.
 *
 * @property int $id
 * @property int $payroll_item_id
 * @property int $snapshot_version
 * @property string $payload
 * @property string $payload_hash
 * @property string|null $rendered_pdf_hash
 * @property Carbon|null $created_at
 */
final class PayrollItemSnapshot extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'payroll_item_id',
        'snapshot_version',
        'payload',
        'payload_hash',
        'rendered_pdf_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PayrollItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedPayload(): array
    {
        /** @var array<string, mixed> */
        return json_decode($this->payload, true, flags: JSON_THROW_ON_ERROR);
    }
}

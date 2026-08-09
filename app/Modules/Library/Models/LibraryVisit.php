<?php

declare(strict_types=1);

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 06-assets-stores.md §10.9 - the turnstile row behind "Daily Visits".
 *
 * @property int $id
 * @property int|null $library_member_id
 * @property string $visited_on
 * @property string|null $visited_at_time
 * @property string|null $purpose
 * @property int $recorded_by
 */
final class LibraryVisit extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'library_member_id', 'visited_on', 'visited_at_time', 'purpose', 'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'library_member_id' => 'integer',
            'recorded_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LibraryMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'library_member_id');
    }
}

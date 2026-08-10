<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Domain\JournalType;
use Database\Factories\JournalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Support\Retention\Immutable10Year;

/**
 * 02-accounting §3. Persistence only - the "AN/CL are is_system and
 * immutable outside the year-end Actions" rule (§3, §18) is enforced by
 * ConfigureJournal, never here.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property JournalType $type
 * @property int|null $default_debit_account_id
 * @property int|null $default_credit_account_id
 * @property int|null $treasury_account_id
 * @property bool $requires_maker_checker
 * @property string $piece_no_format
 * @property bool $is_system
 * @property bool $is_active
 * @property bool $is_archived
 */
final class Journal extends Model
{
    use Immutable10Year;
    /** @use HasFactory<JournalFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'name_fr', 'type',
        'default_debit_account_id', 'default_credit_account_id', 'treasury_account_id',
        'requires_maker_checker', 'piece_no_format',
        'is_system', 'is_active', 'is_archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => JournalType::class,
            'requires_maker_checker' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    protected static function newFactory(): JournalFactory
    {
        return JournalFactory::new();
    }
}

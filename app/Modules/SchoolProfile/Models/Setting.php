<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Models;

use App\Modules\SchoolProfile\Domain\SettingClass;
use App\Modules\SchoolProfile\Domain\SettingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $key
 * @property string $value_type
 * @property string $setting_class
 * @property string|null $validation_rule
 * @property string|null $locked_reason
 * @property string|null $value
 * @property int|null $updated_by
 * @property Carbon|null $locked_at
 */
class Setting extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
        ];
    }

    public function type(): SettingType
    {
        return SettingType::from($this->value_type);
    }

    public function settingClass(): SettingClass
    {
        return SettingClass::from($this->setting_class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function typedValue(): mixed
    {
        $decoded = json_decode((string) $this->getRawOriginal('value'), true, 512, JSON_THROW_ON_ERROR);

        return $this->type()->cast($decoded);
    }
}

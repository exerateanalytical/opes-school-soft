<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/**
 * docs/specs/07-students.md §9.3. `open` → `submitted` is a conditional
 * UPDATE with an affected-rows check (a double-tapped submit cannot write
 * twice); `submitted` → `amended` requires a reason and an audit entry.
 * Submitted/amended registers are never deleted (model observer).
 */
enum RegisterStatus: string
{
    case Open = 'open';
    case Submitted = 'submitted';
    case Amended = 'amended';

    public function label(string $locale = 'en'): string
    {
        return __('attendance.register_status.'.$this->value, [], $locale);
    }

    /** Registers that count in denominators: actually taken, not drafts. */
    public function countsAsTaken(): bool
    {
        return $this !== self::Open;
    }
}

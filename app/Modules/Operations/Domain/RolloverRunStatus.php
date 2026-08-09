<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

/**
 * Lifecycle of a rollover run (docs/specs/08-operations.md §6.3). `failed`
 * is not terminal in the "give up" sense - a failed run is resumable after
 * a power cut; `completed` and `undone` are final.
 */
enum RolloverRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Undone = 'undone';
    case Failed = 'failed';

    public function label(string $locale = 'en'): string
    {
        return __('rollover.run_status.'.$this->value, [], $locale);
    }

    /**
     * A run in this state may execute (or re-execute) steps.
     */
    public function isResumable(): bool
    {
        return $this === self::Running || $this === self::Failed;
    }

    /**
     * Undo applies only to work that actually happened and has not already
     * been rolled back (§6.3 "reversible within a window" - the window check
     * itself is live data, not status).
     */
    public function isUndoable(): bool
    {
        return $this !== self::Undone;
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Undone;
    }
}

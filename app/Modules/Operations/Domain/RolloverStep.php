<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

/**
 * The ten wizard steps of the academic-year rollover, plus the mandatory
 * step-0 pre-flight (docs/specs/08-operations.md §6.2). Int-backed because
 * `rollover_runs.current_step` stores the ordinal and resume logic compares
 * ordinals.
 */
enum RolloverStep: int
{
    case Preflight = 0;
    case CreateNewYear = 1;
    case CopyClassGroups = 2;
    case CopySubjectAllocations = 3;
    case CopyAssessmentPeriods = 4;
    case CopyFeeStructures = 5;
    case PromoteStudents = 6;
    case CarryBalances = 7;
    case ArchiveLeavers = 8;
    case ReassignTeachers = 9;
    case FlipActiveYear = 10;

    public function label(string $locale = 'en'): string
    {
        return __('rollover.step.'.$this->value, [], $locale);
    }

    public function isFirst(): bool
    {
        return $this === self::Preflight;
    }

    public function isLast(): bool
    {
        return $this === self::FlipActiveYear;
    }

    /**
     * The step that follows this one, or null after the flip - the guard a
     * resume loop terminates on.
     */
    public function next(): ?self
    {
        return self::tryFrom($this->value + 1);
    }

    /**
     * Steps that create rows recorded in `rollover_artifacts`. Preflight only
     * validates, and the flip mutates `is_current` in place - neither leaves
     * an artifact to undo.
     */
    public function createsArtifacts(): bool
    {
        return $this !== self::Preflight && $this !== self::FlipActiveYear;
    }

    /**
     * Whether a run standing at $currentStep may execute this step: strictly
     * in order, no skipping (§6.2 "each step previewable", resumed runs
     * restart at the first incomplete step).
     */
    public function isRunnableAt(self $currentStep): bool
    {
        return $this->value === $currentStep->value;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Domain;

/**
 * The five steps of the admission wizard, docs/specs/07-students.md 6.2.
 *
 * The labels are the MOCKUP's, which 6.2 resolves explicitly in the mockup's
 * favour where the brief's alternate labelling ("Student Information /
 * Guardian Information / Previous School / Documents Upload / Review &
 * Confirm") disagrees. Both name the same five stages; only one can be on
 * screen, and the spec picked this one.
 *
 * Backed by int rather than string because the wizard's whole job is ordering:
 * `>=` on the backing value is the "has this step been completed" test, and
 * spelling that against a string enum needs a lookup table that can go stale.
 */
enum WizardStep: int
{
    case BasicInformation = 1;
    case AcademicDetails = 2;
    case ParentGuardian = 3;
    case OtherInformation = 4;
    case DocumentsReview = 5;

    public const FIRST = 1;

    public const LAST = 5;

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    public function isAfter(self $other): bool
    {
        return $this->value > $other->value;
    }

    public function next(): ?self
    {
        return self::tryFrom($this->value + 1);
    }

    public function previous(): ?self
    {
        return self::tryFrom($this->value - 1);
    }

    /**
     * The translation key suffix. Kept separate from the case name so a label
     * can be reworded in lang/ without renaming a PHP case that migrations and
     * saved drafts reference by number.
     */
    public function key(): string
    {
        return match ($this) {
            self::BasicInformation => 'basic_information',
            self::AcademicDetails => 'academic_details',
            self::ParentGuardian => 'parent_guardian',
            self::OtherInformation => 'other_information',
            self::DocumentsReview => 'documents_review',
        };
    }

    public function label(string $locale = 'en'): string
    {
        $labels = trans('opes.admissions_screen.steps', [], $locale);

        if (is_array($labels) && is_string($labels[$this->key()] ?? null)) {
            return $labels[$this->key()];
        }

        return 'opes.admissions_screen.steps.'.$this->key();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * What a given import file contains (00-core §15 Phase 2).
 *
 * The column lists here are the contract between the downloadable template
 * and the validator, so a school that fills in the template it was given
 * cannot produce a file the validator rejects wholesale.
 */
enum ImportKind: string
{
    case Students = 'students';
    case Guardians = 'guardians';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Students => 'Students',
            self::Guardians => 'Guardians',
            self::Staff => 'Staff',
        };
    }

    /**
     * Columns a file of this kind MUST carry. A missing one is a row error,
     * never a silent null.
     *
     * @return list<string>
     */
    public function requiredColumns(): array
    {
        return match ($this) {
            self::Students => ['first_name', 'last_name', 'date_of_birth', 'gender'],
            self::Guardians => ['first_name', 'last_name', 'phone'],
            self::Staff => ['first_name', 'last_name', 'hired_on'],
        };
    }

    /**
     * Recognised but not required. Anything outside both lists is ignored,
     * so a school's own extra columns do not break the import.
     *
     * @return list<string>
     */
    public function optionalColumns(): array
    {
        return match ($this) {
            self::Students => [
                'middle_name', 'preferred_name', 'place_of_birth', 'nationality',
                'religion', 'blood_group', 'genotype', 'phone', 'email',
                'birth_certificate_no', 'national_id_number',
            ],
            self::Guardians => [
                'middle_name', 'email', 'relationship', 'national_id_number',
                'occupation', 'address',
            ],
            self::Staff => [
                'middle_name', 'gender', 'phone', 'email', 'job_title',
                'national_id_number',
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function allColumns(): array
    {
        return array_merge($this->requiredColumns(), $this->optionalColumns());
    }
}

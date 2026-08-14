<?php

declare(strict_types=1);

namespace App\Modules\Alumni\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The relationship a school keeps with a graduate after the enrollment
 * graph closes (gap #3, 2026-08-12 gap analysis).
 *
 * `student_id` is a plain integer column: the identity row stays in the
 * Students module and is read via DB::table at the boundaries, never
 * through a cross-module Model import (00-core 6.2). The two `*_name`
 * columns are label-at-time copies frozen by ConvertGraduateToAlumnus - a
 * later rename of the class group or the academic year must not rewrite
 * what this cohort's diploma class was called.
 *
 * @property int $id
 * @property int $student_id
 * @property int $graduation_year
 * @property string $final_class_group_name
 * @property string $academic_year_name
 * @property string|null $current_occupation
 * @property string|null $current_organisation
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property bool $is_deceased
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $updated_by
 */
final class AlumnusRecord extends Model
{
    /** @use HasFactory<\Database\Factories\AlumnusRecordFactory> */
    use HasFactory;

    protected $table = 'alumnus_records';

    protected $fillable = [
        'student_id', 'graduation_year', 'final_class_group_name',
        'academic_year_name', 'current_occupation', 'current_organisation',
        'contact_email', 'contact_phone', 'is_deceased', 'notes',
        'created_by', 'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'graduation_year' => 'integer',
            'is_deceased' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AlumniEngagement, $this>
     */
    public function engagements(): HasMany
    {
        return $this->hasMany(AlumniEngagement::class);
    }

    /** Whether the record carries any way of reaching the alumnus. */
    public function isReachable(): bool
    {
        return $this->contact_email !== null || $this->contact_phone !== null;
    }
}

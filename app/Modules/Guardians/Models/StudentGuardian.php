<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Models;

use App\Modules\Guardians\Domain\GuardianAuthorizationFlags;
use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Domain\GuardianRelationship;
use App\Modules\Guardians\Domain\GuardianScopeMatrix;
use App\Support\Clock\BusinessDate;
use Database\Factories\StudentGuardianFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/07-students.md 7.2 - the student/guardian link.
 *
 * NO ELOQUENT RELATION TO Student. tests/Architecture/ModuleBoundaryTest.php
 * forbids `App\Modules\Guardians` from using `App\Modules\Students\Models`
 * absolutely - the comment in that file records that the one historical
 * exception was removed and "the rule is absolute again". A `belongsTo(Student
 * ::class)` would be exactly that import. Callers that need student columns
 * join `students` by `student_id` in a query (see FindDuplicateGuardians and
 * LinkGuardian for the pattern), or go through the Students module's own
 * Actions. `student_id` is still a real FK with RESTRICT at the database
 * level, so referential integrity is not what is being traded away here -
 * only the convenience accessor.
 *
 * @property int $id
 * @property int $student_id
 * @property int $guardian_id
 * @property GuardianRelationship $relationship
 * @property string|null $relationship_other
 * @property bool $is_primary
 * @property bool $has_custody
 * @property bool $receives_reports
 * @property bool $receives_invoices
 * @property bool $is_emergency_contact
 * @property bool $is_authorised_for_pickup
 * @property bool $is_fee_payer
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property string|null $revocation_reason
 * @property int|null $primary_key_col
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guardian|null $guardian
 */
final class StudentGuardian extends Model
{
    /** @use HasFactory<StudentGuardianFactory> */
    use HasFactory;

    /**
     * The five columns 7.5 reads, in the table's own order. Named once so that
     * SetGuardianAuthorization's before/after audit payload and the successor
     * row it inserts cannot drift apart from each other.
     *
     * @var list<string>
     */
    public const AUTHORIZATION_FLAGS = [
        'has_custody',
        'receives_reports',
        'receives_invoices',
        'is_emergency_contact',
        'is_fee_payer',
    ];

    /**
     * `is_authorised_for_pickup` and `is_primary` are scope-bearing columns on
     * the link that 7.5 does not give a matrix row. They are audited on change
     * with the rest, but they are not inputs to GuardianScopeMatrix: pickup is
     * a gate-desk decision made on paper, and 7.5 states outright that
     * is_primary grants nothing.
     *
     * @var list<string>
     */
    public const AUDITED_SCOPE_COLUMNS = [
        'is_primary',
        'has_custody',
        'receives_reports',
        'receives_invoices',
        'is_emergency_contact',
        'is_authorised_for_pickup',
        'is_fee_payer',
    ];

    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'guardian_id',
        'relationship',
        'relationship_other',
        'is_primary',
        'has_custody',
        'receives_reports',
        'receives_invoices',
        'is_emergency_contact',
        'is_authorised_for_pickup',
        'is_fee_payer',
        'valid_from',
        'valid_to',
        'revocation_reason',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'guardian_id' => 'integer',
            'relationship' => GuardianRelationship::class,
            'is_primary' => 'boolean',
            'has_custody' => 'boolean',
            'receives_reports' => 'boolean',
            'receives_invoices' => 'boolean',
            'is_emergency_contact' => 'boolean',
            'is_authorised_for_pickup' => 'boolean',
            'is_fee_payer' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'primary_key_col' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /**
     * 7.3, verbatim:
     *
     *     WHERE valid_from <= CURDATE()
     *       AND (valid_to IS NULL OR valid_to >= CURDATE())
     *
     * with CURDATE() replaced by business_date() (00-core 7.5) because the
     * server clock is UTC and Cameroon is UTC+1: between 00:00 and 01:00 local
     * time MySQL's CURDATE() is still YESTERDAY, and a link that expired at
     * midnight would keep granting access for an hour.
     *
     * $asOf is passed in, not read here, so that one request resolves ONE date
     * and every query in it agrees. A request that spans midnight must not see
     * two different answers - which is exactly what re-evaluating the clock per
     * query would produce.
     *
     * A `valid_from` in the future grants nothing; that is the first clause,
     * and it is what makes SetGuardianAuthorization's tomorrow-dated successor
     * row safe to insert inside the same transaction that closes its
     * predecessor.
     *
     * @param  Builder<StudentGuardian>  $query
     * @return Builder<StudentGuardian>
     */
    public function scopeValidOn(Builder $query, ?string $asOf = null): Builder
    {
        $date = $asOf ?? BusinessDate::today();

        return $query
            ->where('valid_from', '<=', $date)
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date);
            });
    }

    /**
     * The same predicate as scopeValidOn, in PHP, for a row already in memory.
     *
     * Two spellings of one rule is a real risk, so the truth table in
     * tests/Feature/Guardians/GuardianAuthorizationTest.php asserts BOTH
     * against the same fixtures, one clause at a time, and would fail if they
     * ever diverged.
     */
    public function isValid(?string $asOf = null): bool
    {
        $date = $asOf ?? BusinessDate::today();

        if ($this->valid_from->toDateString() > $date) {
            return false;
        }

        if ($this->valid_to === null) {
            return true;
        }

        return $this->valid_to->toDateString() >= $date;
    }

    /**
     * Project this link into the pure value object the 7.5 matrix consumes.
     *
     * `guardianIsActive` is a conjunctive gate on every row, so it is resolved
     * here rather than being left to each caller to remember. `relationLoaded`
     * is checked so that a caller who eager-loaded the guardian does not pay
     * for a query per link when walking a child's guardian list.
     */
    public function authorizationFlags(?string $asOf = null): GuardianAuthorizationFlags
    {
        $guardian = $this->relationLoaded('guardian') ? $this->guardian : $this->guardian()->first();

        return new GuardianAuthorizationFlags(
            isValid: $this->isValid($asOf),
            guardianIsActive: $guardian?->isActive() ?? false,
            hasCustody: $this->has_custody,
            receivesReports: $this->receives_reports,
            receivesInvoices: $this->receives_invoices,
            isFeePayer: $this->is_fee_payer,
            isEmergencyContact: $this->is_emergency_contact,
        );
    }

    /**
     * "May the holder of this link do $capability, today?"
     *
     * Takes a string so that policies and route middleware can name a
     * capability without importing the enum. An unrecognised string returns
     * FALSE, not an exception: 7.5's deny-by-default reading rule says a
     * capability absent from the table is denied, and a typo in a policy must
     * fail closed. The compile-time safety net is on the other side - the enum
     * match in GuardianScopeMatrix is exhaustive, so a new capability cannot
     * be added without a rule.
     */
    public function authorises(string $capability, ?string $asOf = null): bool
    {
        $case = GuardianCapability::tryFrom($capability);

        if ($case === null) {
            return false;
        }

        return GuardianScopeMatrix::allows($this->authorizationFlags($asOf), $case);
    }

    /**
     * @return BelongsTo<Guardian, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    protected static function newFactory(): StudentGuardianFactory
    {
        return StudentGuardianFactory::new();
    }
}

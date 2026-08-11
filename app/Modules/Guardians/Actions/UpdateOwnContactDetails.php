<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Actions;

use App\Modules\Guardians\Models\Guardian;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Row 29: a guardian editing THEIR OWN contact details - and, inseparably,
 * row 30: nobody, ever, editing an authorization flag from here.
 *
 * The two rows live in one class on purpose. Row 30 is not a missing feature
 * to be added later; it is the boundary that makes row 29 safe. A guardian who
 * could edit `has_custody`, `receives_reports`, `receives_invoices` or
 * `is_fee_payer` on their own link would be able to grant themselves every
 * other row in the matrix - the whole 7.5 table collapses to "whatever the
 * parent last saved". So an attempt on any of those fields is refused AND
 * audited as a security event, because a client that tries it is either broken
 * or hostile and the school should be able to tell which.
 *
 * ALLOWED is a closed allow-list, never a deny-list: a deny-list silently
 * grants every column added to `guardians` after today, which is exactly how
 * this kind of hole opens years later.
 *
 * `status`, `is_archived` and `portal_user_id` are absent for the same reason
 * as the flags - they are the school's judgements about the guardian, not the
 * guardian's own facts about themselves.
 */
final class UpdateOwnContactDetails
{
    /**
     * The guardian's own facts. Everything else on the row belongs to the
     * school.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        'phone',
        'alternative_phone',
        'email',
        'address_line',
        'city',
        'region',
        'country',
        'occupation',
        'employer',
        'preferred_contact_method',
        'language',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'emergency_contact_address',
        'notify_sms',
        'notify_email',
        'notify_push',
    ];

    /**
     * Fields whose presence is not a validation error but a SECURITY event.
     * Named explicitly rather than derived, so the audit message can say which
     * boundary was tested.
     *
     * @var list<string>
     */
    public const FORBIDDEN = [
        'has_custody',
        'receives_reports',
        'receives_invoices',
        'is_fee_payer',
        'is_emergency_contact',
        'authorization_flags',
        'status',
        'is_archived',
        'portal_user_id',
        'guardian_no',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Guardian $guardian, array $attributes, ?Actor $actor = null): Guardian
    {
        $attempted = array_values(array_intersect(array_keys($attributes), self::FORBIDDEN));

        if ($attempted !== []) {
            // Audited BEFORE the refusal, so a rejected attempt still leaves a
            // trace. A 403 the school never hears about teaches nobody
            // anything.
            app(WriteAuditEntry::class)->handle(
                action: AuditAction::PermissionGranted,
                module: 'Guardians',
                auditableType: Guardian::class,
                auditableId: (int) $guardian->getKey(),
                before: null,
                after: [
                    'refused' => 'row_30_authorization_edit',
                    'fields' => $attempted,
                ],
                actor: $actor,
            );

            throw ValidationException::withMessages([
                'fields' => 'Only the school can change these details.',
            ])->status(403);
        }

        $changes = array_intersect_key($attributes, array_flip(self::ALLOWED));

        if ($changes === []) {
            return $guardian;
        }

        if (array_key_exists('phone', $changes)) {
            // Normalised on the way in, exactly as CreateGuardian does: 7.7's
            // duplicate detection is an EXACT match on this column, and a
            // column holding four spellings of one handset never matches.
            $normalised = Guardian::normalisePhone(
                is_string($changes['phone']) ? $changes['phone'] : null
            );

            if ($normalised === null) {
                throw ValidationException::withMessages([
                    'phone' => 'A guardian must have a usable telephone number.',
                ]);
            }

            $changes['phone'] = $normalised;
        }

        if (array_key_exists('alternative_phone', $changes) && is_string($changes['alternative_phone'])) {
            $changes['alternative_phone'] = Guardian::normalisePhone($changes['alternative_phone']);
        }

        $before = [];

        foreach (array_keys($changes) as $field) {
            $before[$field] = $guardian->getAttribute($field);
        }

        return DB::transaction(function () use ($guardian, $changes, $before, $actor): Guardian {
            $guardian->fill($changes)->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Guardians',
                auditableType: Guardian::class,
                auditableId: (int) $guardian->getKey(),
                before: $before,
                after: $changes,
                actor: $actor,
            );

            return $guardian->refresh();
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * The ONLY writer of `school_document_profiles` - the row
 * RenderDocument::captureSchoolChrome() reads for EVERY printed document
 * (letterhead, crest, address, ministry headers, signatures, stamp, the
 * bilingual flag). The table shipped with 0 rows and no UI, which is why
 * every document in the product renders bare.
 *
 * A singleton by design, exactly like `fiscal_identities`: this is a
 * single-tenant-per-deployment platform, so `id = 1` is the school, and an
 * upsert on that key is the whole storage contract.
 *
 * Every field is nullable. 00-core §16: a school that has not filled these
 * in gets a letterhead WITHOUT them, never a guessed or seeded one - a wrong
 * ministry header on a statutory document is worse than none.
 *
 * Editing this row is SAFE for already-issued documents: the render
 * envelope frozen onto each issued document at issue time (Phase 2 of the
 * 2026-08-14 fix plan) means a reprint reads the chrome AS AT ISSUE, never
 * this row's current state. Only documents issued AFTER a save wear it.
 */
final class SaveDocumentProfile
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input, Actor $actor): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $data = Validator::make($input, [
            'address_line1' => ['nullable', 'string', 'max:160'],
            'address_line2' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:80'],
            'region' => ['nullable', 'string', 'max:80'],
            'po_box' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:60'],
            'phone_alt' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:190'],
            'website' => ['nullable', 'string', 'max:190'],
            'authorisation_line' => ['nullable', 'string', 'max:200'],
            'state_header_enabled' => ['nullable', 'boolean'],
            // Required WHEN the header is on: a state header is the block that
            // makes a document look official, and printing an empty one is a
            // worse claim than printing none.
            'ministry_en' => ['nullable', 'string', 'max:160', 'required_if:state_header_enabled,true,1'],
            'ministry_fr' => ['nullable', 'string', 'max:160', 'required_if:state_header_enabled,true,1'],
            'regional_delegation_en' => ['nullable', 'string', 'max:160'],
            'regional_delegation_fr' => ['nullable', 'string', 'max:160'],
            'divisional_delegation_en' => ['nullable', 'string', 'max:160'],
            'divisional_delegation_fr' => ['nullable', 'string', 'max:160'],
            'bilingual_documents' => ['nullable', 'boolean'],
            'default_document_language' => ['nullable', 'in:en,fr'],
            'crest_path' => ['nullable', 'string', 'max:255'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'principal_signature_path' => ['nullable', 'string', 'max:255'],
            'registrar_signature_path' => ['nullable', 'string', 'max:255'],
            'school_stamp_path' => ['nullable', 'string', 'max:255'],
        ])->validate();

        DB::transaction(function () use ($data, $actor): void {
            $existing = DB::table('school_document_profiles')->where('id', 1)->first();

            DB::table('school_document_profiles')->updateOrInsert(
                ['id' => 1],
                array_merge($data, [
                    'updated_at' => now(),
                    'created_at' => $existing === null ? now() : $existing->created_at,
                ]),
            );

            // Auditable because it changes what EVERY printed document says
            // about the institution issuing it (00-core §14). SettingChanged,
            // not Updated: this row IS institution-level configuration, the
            // same class of change WriteSetting records under that action.
            $this->audit->handle(
                action: AuditAction::SettingChanged,
                module: 'schoolprofile',
                auditableType: 'SchoolDocumentProfile',
                auditableId: 1,
                before: $existing === null ? null : (array) $existing,
                after: $data,
                actor: $actor,
            );
        });
    }
}

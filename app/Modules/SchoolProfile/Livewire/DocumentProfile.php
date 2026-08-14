<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\SchoolProfile\Actions\SaveDocumentProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /settings/school-identity - the letterhead every printed document wears.
 *
 * `school_document_profiles` shipped with 0 rows and no screen that writes
 * it, so every invoice, receipt, bulletin and attestation rendered without a
 * crest, an address, a phone number or a ministry header. That is not a
 * cosmetic gap: 10-documents §4.7's school_header block promises those
 * fields and silently dropped the middle third of the letterhead.
 *
 * Signature and crest PATHS only, no upload widget: the paths point at
 * storage the deployment already manages, and an upload flow is a separate
 * slice with its own validation and virus-scanning questions.
 */
#[Layout('layouts.app')]
final class DocumentProfile extends Component
{
    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $city = '';

    public string $region = '';

    public string $poBox = '';

    public string $phone = '';

    public string $phoneAlt = '';

    public string $email = '';

    public string $website = '';

    public string $authorisationLine = '';

    public bool $stateHeaderEnabled = false;

    public string $ministryEn = '';

    public string $ministryFr = '';

    public string $regionalDelegationEn = '';

    public string $regionalDelegationFr = '';

    public string $divisionalDelegationEn = '';

    public string $divisionalDelegationFr = '';

    public bool $bilingualDocuments = false;

    public string $defaultDocumentLanguage = 'en';

    public string $crestPath = '';

    public string $logoPath = '';

    public string $principalSignaturePath = '';

    public string $registrarSignaturePath = '';

    public string $schoolStampPath = '';

    public function mount(): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $row = DB::table('school_document_profiles')->where('id', 1)->first();

        if ($row === null) {
            return;
        }

        $this->addressLine1 = (string) ($row->address_line1 ?? '');
        $this->addressLine2 = (string) ($row->address_line2 ?? '');
        $this->city = (string) ($row->city ?? '');
        $this->region = (string) ($row->region ?? '');
        $this->poBox = (string) ($row->po_box ?? '');
        $this->phone = (string) ($row->phone ?? '');
        $this->phoneAlt = (string) ($row->phone_alt ?? '');
        $this->email = (string) ($row->email ?? '');
        $this->website = (string) ($row->website ?? '');
        $this->authorisationLine = (string) ($row->authorisation_line ?? '');
        $this->stateHeaderEnabled = (bool) $row->state_header_enabled;
        $this->ministryEn = (string) ($row->ministry_en ?? '');
        $this->ministryFr = (string) ($row->ministry_fr ?? '');
        $this->regionalDelegationEn = (string) ($row->regional_delegation_en ?? '');
        $this->regionalDelegationFr = (string) ($row->regional_delegation_fr ?? '');
        $this->divisionalDelegationEn = (string) ($row->divisional_delegation_en ?? '');
        $this->divisionalDelegationFr = (string) ($row->divisional_delegation_fr ?? '');
        $this->bilingualDocuments = (bool) $row->bilingual_documents;
        $this->defaultDocumentLanguage = (string) ($row->default_document_language ?? 'en');
        $this->crestPath = (string) ($row->crest_path ?? '');
        $this->logoPath = (string) ($row->logo_path ?? '');
        $this->principalSignaturePath = (string) ($row->principal_signature_path ?? '');
        $this->registrarSignaturePath = (string) ($row->registrar_signature_path ?? '');
        $this->schoolStampPath = (string) ($row->school_stamp_path ?? '');
    }

    public function save(SaveDocumentProfile $save): void
    {
        $this->resetErrorBag();

        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        try {
            $save->handle([
                'address_line1' => $this->addressLine1 ?: null,
                'address_line2' => $this->addressLine2 ?: null,
                'city' => $this->city ?: null,
                'region' => $this->region ?: null,
                'po_box' => $this->poBox ?: null,
                'phone' => $this->phone ?: null,
                'phone_alt' => $this->phoneAlt ?: null,
                'email' => $this->email ?: null,
                'website' => $this->website ?: null,
                'authorisation_line' => $this->authorisationLine ?: null,
                'state_header_enabled' => $this->stateHeaderEnabled,
                'ministry_en' => $this->ministryEn ?: null,
                'ministry_fr' => $this->ministryFr ?: null,
                'regional_delegation_en' => $this->regionalDelegationEn ?: null,
                'regional_delegation_fr' => $this->regionalDelegationFr ?: null,
                'divisional_delegation_en' => $this->divisionalDelegationEn ?: null,
                'divisional_delegation_fr' => $this->divisionalDelegationFr ?: null,
                'bilingual_documents' => $this->bilingualDocuments,
                'default_document_language' => $this->defaultDocumentLanguage,
                'crest_path' => $this->crestPath ?: null,
                'logo_path' => $this->logoPath ?: null,
                'principal_signature_path' => $this->principalSignaturePath ?: null,
                'registrar_signature_path' => $this->registrarSignaturePath ?: null,
                'school_stamp_path' => $this->schoolStampPath ?: null,
            ], $user->toAuditActor());
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->messages() as $field => $messages) {
                $this->addError((string) $field, (string) ($messages[0] ?? ''));
            }

            return;
        }

        session()->flash('status', __('opes.school_identity.saved'));
    }

    /**
     * The go-live blocker nobody could find: the shipped fiscal identity is a
     * SPECIMEN, so every money document carries the SPECIMEN watermark - and
     * /settings/fiscal-identity, the screen that clears it, had zero inbound
     * links anywhere in the product.
     */
    public function fiscalIdentityIsProvisional(): bool
    {
        $row = DB::table('fiscal_identities')->where('id', 1)->first();

        return $row === null || $row->fiscal_identity_confirmed_at === null;
    }

    public function render(): mixed
    {
        return view('livewire.schoolprofile.document-profile', [
            'fiscalProvisional' => $this->fiscalIdentityIsProvisional(),
        ]);
    }
}

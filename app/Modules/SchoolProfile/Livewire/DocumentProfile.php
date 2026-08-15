<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Livewire;

use App\Modules\Identity\Domain\Permission;
use App\Modules\SchoolProfile\Actions\SaveDocumentProfile;
use App\Support\Storage\StoredImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * /settings/school-identity - the letterhead every printed document wears.
 *
 * `school_document_profiles` shipped with 0 rows and no screen that writes
 * it, so every invoice, receipt, bulletin and attestation rendered without a
 * crest, an address, a phone number or a ministry header. That is not a
 * cosmetic gap: 10-documents §4.7's school_header block promises those
 * fields and silently dropped the middle third of the letterhead.
 *
 * The five branding marks (crest, logo, two signatures, stamp) are UPLOADED
 * here. They land under a content-hashed filename via StoredImage, which is
 * what stops a replacement breaking the reprint of every document issued
 * before it - see that class for the full argument.
 */
#[Layout('layouts.app')]
final class DocumentProfile extends Component
{
    // The codebase's FIRST Livewire upload. Livewire parks the temp file on
    // the default disk under livewire-tmp/ and prunes it after 24h on its
    // own; the only rule this screen has to honour is that a temporary file
    // is moved into permanent storage inside save() and never carried across
    // a request.
    use WithFileUploads;

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

    public ?TemporaryUploadedFile $crestUpload = null;

    public ?TemporaryUploadedFile $logoUpload = null;

    public ?TemporaryUploadedFile $principalSignatureUpload = null;

    public ?TemporaryUploadedFile $registrarSignatureUpload = null;

    public ?TemporaryUploadedFile $schoolStampUpload = null;

    /**
     * slot => [upload property, path property, database column].
     *
     * One list rather than five near-identical blocks: the validation, the
     * store, the delete-on-replace and the preview all walk it, so a sixth
     * image is one line rather than four edits that can disagree.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const IMAGE_SLOTS = [
        'crest' => ['crestUpload', 'crestPath', 'crest_path'],
        'logo' => ['logoUpload', 'logoPath', 'logo_path'],
        'principal_signature' => ['principalSignatureUpload', 'principalSignaturePath', 'principal_signature_path'],
        'registrar_signature' => ['registrarSignatureUpload', 'registrarSignaturePath', 'registrar_signature_path'],
        'school_stamp' => ['schoolStampUpload', 'schoolStampPath', 'school_stamp_path'],
    ];

    /**
     * Livewire validation for the five upload slots.
     *
     * `image` plus an explicit mimes list plus a dimension cap, all three:
     * `image` alone admits SVG (a script-capable document served from this
     * app's own origin), the mimes list alone admits a 12 000 px scan that
     * makes every PDF 40 MB, and the dimension cap alone admits a renamed
     * executable.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rule = [
            'nullable',
            'image',
            'mimes:'.implode(',', StoredImage::ALLOWED_EXTENSIONS),
            'max:'.StoredImage::MAX_KILOBYTES,
            'dimensions:max_width='.StoredImage::MAX_DIMENSION.',max_height='.StoredImage::MAX_DIMENSION,
        ];

        $rules = [];

        foreach (self::IMAGE_SLOTS as [$uploadProperty]) {
            $rules[$uploadProperty] = $rule;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        $messages = [];

        foreach (self::IMAGE_SLOTS as [$uploadProperty]) {
            $messages[$uploadProperty.'.image'] = (string) __('opes.school_identity.upload_not_an_image');
            $messages[$uploadProperty.'.mimes'] = (string) __('opes.school_identity.upload_wrong_type', [
                'types' => strtoupper(implode(', ', StoredImage::ALLOWED_EXTENSIONS)),
            ]);
            $messages[$uploadProperty.'.max'] = (string) __('opes.school_identity.upload_too_large', [
                'kb' => StoredImage::MAX_KILOBYTES,
            ]);
            $messages[$uploadProperty.'.dimensions'] = (string) __('opes.school_identity.upload_too_big', [
                'px' => StoredImage::MAX_DIMENSION,
            ]);
        }

        return $messages;
    }

    /**
     * Clear a slot. The file itself is deleted at SAVE, not here - an
     * operator who clicks Remove and then Cancel must get their image back,
     * and a delete on click cannot be undone.
     */
    public function removeImage(string $slot): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        if (! array_key_exists($slot, self::IMAGE_SLOTS)) {
            return;
        }

        [$uploadProperty, $pathProperty] = self::IMAGE_SLOTS[$slot];

        $this->{$uploadProperty} = null;
        $this->{$pathProperty} = '';
    }

    /**
     * Move every pending upload into permanent, content-hashed storage and
     * point the path properties at it.
     *
     * Nothing is DELETED here. The files a save orphans are worked out
     * against the persisted row afterwards, and only once the database write
     * has actually succeeded - deleting first and then failing the write
     * would lose the image AND keep the old path.
     */
    private function storeUploads(): void
    {
        foreach (self::IMAGE_SLOTS as $slot => [$uploadProperty, $pathProperty]) {
            $upload = $this->{$uploadProperty};

            if (! $upload instanceof TemporaryUploadedFile) {
                continue;
            }

            $this->{$pathProperty} = StoredImage::putContents(
                $slot,
                (string) file_get_contents((string) $upload->getRealPath()),
                strtolower($upload->getClientOriginalExtension()),
            );

            // Release the temporary file immediately: a TemporaryUploadedFile
            // held on a public property is re-serialised into every
            // subsequent request's payload.
            $upload->delete();
            $this->{$uploadProperty} = null;
        }
    }

    /**
     * Which of the given paths no slot holds any more - the set safe to
     * delete.
     *
     * "No longer held by any slot" rather than "was replaced in this slot":
     * a school that swaps its crest and its logo for each other must not have
     * either file deleted.
     *
     * @param  array<string, string>  $candidates
     * @return list<string>
     */
    private function orphanedPaths(array $candidates): array
    {
        $kept = [];

        foreach (self::IMAGE_SLOTS as [, $pathProperty]) {
            $kept[] = (string) $this->{$pathProperty};
        }

        return array_values(array_filter(
            array_unique(array_values($candidates)),
            static fn (string $path): bool => $path !== '' && ! in_array($path, $kept, true),
        ));
    }

    /**
     * The image path each slot holds in the DATABASE right now.
     *
     * @return array<string, string>
     */
    private function persistedImagePaths(): array
    {
        $row = DB::table('school_document_profiles')->where('id', 1)->first();

        $paths = [];

        foreach (self::IMAGE_SLOTS as $slot => [, , $column]) {
            $paths[$slot] = $row === null ? '' : (string) ($row->{$column} ?? '');
        }

        return $paths;
    }

    public function mount(): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->hydrateFromDatabase();
    }

    /**
     * Discard in-progress edits and re-read the persisted row. The shared
     * <x-settings-form> Cancel control calls this, and clears its own dirty
     * flag on the same click - so Cancel means "put it back", not "leave the
     * screen", which is the one thing a settings Cancel must never be
     * ambiguous about.
     */
    public function cancel(): void
    {
        Gate::authorize(Permission::SettingEdit->value);

        $this->resetErrorBag();
        $this->hydrateFromDatabase();
    }

    private function hydrateFromDatabase(): void
    {
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

        // Validate the uploads BEFORE anything is written to disk: a refused
        // file must leave no trace at all.
        $this->validate();

        // What each slot held BEFORE this save, read from the row rather than
        // from the path properties: removeImage() has already blanked those,
        // so an in-memory comparison would report "nothing was there" and the
        // cleared file would be orphaned on disk forever.
        $previousPaths = $this->persistedImagePaths();

        $this->storeUploads();

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

            // Roll the disk back to match the refused write. The row still
            // names the PREVIOUS paths, so it is the files this save just
            // wrote that are the orphans - and the previous ones that must
            // survive. (The plan had these two the wrong way round, which
            // would have deleted the images the row still points at.)
            foreach (self::IMAGE_SLOTS as $slot => [, $pathProperty]) {
                $written = (string) $this->{$pathProperty};

                if ($written === $previousPaths[$slot]) {
                    continue;
                }

                // Never delete a file another slot still holds - a crest and
                // a logo can legitimately be the same image.
                if (! in_array($written, $previousPaths, true)) {
                    StoredImage::forget($written, null);
                }

                $this->{$pathProperty} = $previousPaths[$slot];
            }

            return;
        }

        // Only after the row is committed: deleting first and then failing
        // the write would lose the image AND keep the old path.
        //
        // One rule covers replacement AND removal AND both at once: a path
        // the row USED to hold that no slot holds any more is an orphan.
        // orphanedPaths() is what refuses to delete a file another slot still
        // points at, so a school that swaps its crest and its logo keeps
        // both.
        foreach ($this->orphanedPaths($previousPaths) as $orphan) {
            StoredImage::forget($orphan, null);
        }

        // A browser event rather than a session flash: the shared
        // <x-settings-form> listens for it to clear its dirty flag and raise
        // the toast, and a Livewire round trip does not reload the page a
        // session flash would need.
        $this->dispatch('settings-saved');
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

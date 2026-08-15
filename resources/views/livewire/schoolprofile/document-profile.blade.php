{{-- /settings/school-identity - the letterhead every printed document wears.
     Built on the shared <x-settings-form> pattern so this screen, Branding
     and Tax cannot drift apart. Every field's helper text says where the
     value ends up, because "address_line1" alone does not tell an operator
     that it prints on every invoice. --}}
<div>
    <x-settings-form :title="__('opes.school_identity.title')"
                     :description="__('opes.school_identity.subtitle')"
                     submit="save" cancel="cancel">

        @if ($fiscalProvisional)
            <x-slot:banner>
                <div class="rounded-xl border border-heritage-yellow/60 bg-heritage-yellow/15 px-4 py-3 text-sm text-charcoal">
                    <p class="font-semibold">{{ __('opes.school_identity.fiscal_provisional_title') }}</p>
                    <p class="mt-1">{{ __('opes.school_identity.fiscal_provisional_body') }}</p>
                    @can('ledger.configure')
                        <a href="{{ route('tax.fiscal-identity') }}" class="mt-2 inline-block font-medium text-primary hover:underline">
                            {{ __('opes.school_identity.fiscal_provisional_action') }}
                        </a>
                    @endcan
                </div>
            </x-slot:banner>
        @endif

        <x-settings-fieldset :heading="__('opes.school_identity.contacts')"
                             :hint="__('opes.school_identity.contacts_hint')">
            @foreach ([
                'addressLine1' => 'address_line1', 'addressLine2' => 'address_line2',
                'city' => 'city', 'region' => 'region', 'poBox' => 'po_box',
                'phone' => 'phone', 'phoneAlt' => 'phone_alt', 'email' => 'email',
                'website' => 'website', 'authorisationLine' => 'authorisation_line',
            ] as $model => $key)
                <x-settings-field :label="__('opes.school_identity.'.$key)"
                                  :hint="__('opes.school_identity.hint_'.$key)"
                                  :error="$errors->first($key)">
                    <input type="text" wire:model="{{ $model }}"
                           class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </x-settings-field>
            @endforeach
        </x-settings-fieldset>

        <x-settings-fieldset :heading="__('opes.school_identity.state_header')"
                             :hint="__('opes.school_identity.state_header_hint')">
            <x-settings-field :label="__('opes.school_identity.state_header_enabled')" :span="2">
                <label class="flex items-center gap-2 text-sm font-normal">
                    <input type="checkbox" wire:model.live="stateHeaderEnabled">
                    <span>{{ __('opes.school_identity.state_header_enabled_hint') }}</span>
                </label>
            </x-settings-field>

            @if ($stateHeaderEnabled)
                @foreach ([
                    'ministryEn' => 'ministry_en', 'ministryFr' => 'ministry_fr',
                    'regionalDelegationEn' => 'regional_delegation_en',
                    'regionalDelegationFr' => 'regional_delegation_fr',
                    'divisionalDelegationEn' => 'divisional_delegation_en',
                    'divisionalDelegationFr' => 'divisional_delegation_fr',
                ] as $model => $key)
                    <x-settings-field :label="__('opes.school_identity.'.$key)"
                                      :error="$errors->first($key)">
                        <input type="text" wire:model="{{ $model }}"
                               class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    </x-settings-field>
                @endforeach
            @endif
        </x-settings-fieldset>

        <x-settings-fieldset :heading="__('opes.school_identity.marks')"
                             :hint="__('opes.school_identity.marks_hint')">
            @foreach ([
                ['crest', 'crestUpload', 'crestPath', 'crest_path'],
                ['logo', 'logoUpload', 'logoPath', 'logo_path'],
                ['principal_signature', 'principalSignatureUpload', 'principalSignaturePath', 'principal_signature_path'],
                ['registrar_signature', 'registrarSignatureUpload', 'registrarSignaturePath', 'registrar_signature_path'],
                ['school_stamp', 'schoolStampUpload', 'schoolStampPath', 'school_stamp_path'],
            ] as [$slot, $uploadModel, $pathModel, $key])
                <x-settings-field :label="__('opes.school_identity.'.$key)"
                                  :hint="__('opes.school_identity.hint_'.$key)"
                                  :error="$errors->first($uploadModel) ?: $errors->first($key)">
                    <div class="flex items-start gap-3">
                        {{-- The preview. wire:key on the wrapper so Livewire
                             replaces the thumbnail when the path changes
                             instead of reusing a stale img. --}}
                        <span wire:key="preview-{{ $slot }}-{{ $$pathModel }}"
                              class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border-primary bg-sand">
                            @if ($$uploadModel !== null)
                                <img src="{{ $$uploadModel->temporaryUrl() }}" alt="" class="max-h-full max-w-full object-contain">
                            @elseif ($$pathModel !== '')
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($$pathModel) }}"
                                     alt="" class="max-h-full max-w-full object-contain">
                            @else
                                <span class="text-xs text-charcoal/40">{{ __('opes.school_identity.no_image') }}</span>
                            @endif
                        </span>

                        <div class="min-w-0 flex-1">
                            <input type="file" wire:model="{{ $uploadModel }}"
                                   accept="image/png,image/jpeg,image/webp"
                                   class="block w-full text-sm text-charcoal file:mr-3 file:rounded-lg file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-primary/90">

                            <div wire:loading wire:target="{{ $uploadModel }}" class="mt-1 text-xs text-text-secondary">
                                {{ __('opes.school_identity.uploading') }}
                            </div>

                            @if ($$pathModel !== '')
                                <button type="button" wire:click="removeImage('{{ $slot }}')"
                                        class="mt-1 text-xs font-medium text-danger hover:underline">
                                    {{ __('opes.school_identity.remove_image') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </x-settings-field>
            @endforeach
        </x-settings-fieldset>

        <x-settings-fieldset :heading="__('opes.school_identity.language')"
                             :hint="__('opes.school_identity.language_hint')">
            <x-settings-field :label="__('opes.school_identity.default_document_language')"
                              :hint="__('opes.school_identity.hint_default_document_language')">
                <select wire:model="defaultDocumentLanguage"
                        class="w-full rounded-lg border border-border-primary px-3 py-2 text-sm text-charcoal focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    <option value="en">English</option>
                    <option value="fr">Français</option>
                </select>
            </x-settings-field>

            <x-settings-field :label="__('opes.school_identity.bilingual_documents')"
                              :hint="__('opes.school_identity.hint_bilingual_documents')">
                <label class="flex items-center gap-2 text-sm font-normal">
                    <input type="checkbox" wire:model="bilingualDocuments">
                    <span>{{ __('opes.school_identity.bilingual_documents') }}</span>
                </label>
            </x-settings-field>
        </x-settings-fieldset>
    </x-settings-form>
</div>

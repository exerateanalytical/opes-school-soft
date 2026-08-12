{{--
    `/portal/children/{s}/id-card` - mobile/digital-school-id-child-id.png and
    its `-secure` variant.
--}}
<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'profile',
    ])

    @unless ($revealed)
        {{-- The `-secure` design. A shoulder-surfing guard, not authentication,
             and the copy says so - anyone holding this unlocked browser can
             reach the same three facts through the child's profile. --}}
        <x-portal.card>
            <div class="flex flex-col items-center gap-4 py-8 text-center">
                <x-portal.icon name="shield" tone="primary" size="lg"/>
                <p class="max-w-sm text-sm text-charcoal/70">{{ __('opes.guardian_portal.id_card_hidden') }}</p>

                <button type="button" wire:click="reveal"
                        class="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white hover:bg-portal-green-soft">
                    {{ __('opes.guardian_portal.id_card_reveal') }}
                </button>
            </div>
        </x-portal.card>
    @else
        <x-portal.card :padded="false" class="overflow-hidden">
            <div class="bg-portal-green px-4 py-5 text-center">
                <x-portal.crest size="md" class="mx-auto"/>
                <p class="mt-2 text-sm font-bold tracking-[0.2em] text-portal-gold">{{ __('opes.shell.brand') }}</p>
                <p class="mt-0.5 text-[11px] uppercase tracking-widest text-white/60">
                    {{ __('opes.guardian_portal.id_card_title') }}
                </p>
            </div>

            <div class="flex flex-col items-center gap-3 p-5 text-center">
                <x-portal.avatar :name="$childName" size="xl" tone="green" ring
                                 :photo="route('portal.photo.child', $studentId)"/>

                <p class="text-xl font-bold text-charcoal">{{ $childName }}</p>

                @if ($className)
                    <span class="rounded-full bg-portal-tint px-3 py-1 text-xs font-semibold text-primary">
                        {{ $className }}
                    </span>
                @endif

                {{-- The matricule, not a minted token: the platform verifies by
                     serial at a public page, and a second credential here would
                     be a second identity system nobody asked for. --}}
                <div class="mt-2 w-full rounded-xl border-2 border-portal-gold bg-portal-gold/15 px-4 py-3">
                    <p class="font-mono text-lg font-bold tracking-[0.25em] text-primary">{{ $matricule }}</p>
                </div>

                <p class="text-xs text-charcoal/55">{{ __('opes.guardian_portal.id_card_note') }}</p>
            </div>
        </x-portal.card>
    @endunless
</div>

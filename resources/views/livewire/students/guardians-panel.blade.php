@php
    use App\Modules\Guardians\Livewire\Support\LinkPresentation;

    // 09-ui 10: colour reinforces, the word carries the meaning. A raw flag
    // and an effective portal scope are drawn differently on purpose - the
    // first is what a Registrar set, the second is what the guardian can
    // actually reach today, and an expired link shows the first without the
    // second.
    $validityTone = ['current' => 'ok', 'pending' => 'amber', 'expired' => 'red'];
@endphp

<div class="space-y-3">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
            {{ __('opes.students_screen.guardians_heading') }}
        </h2>
        {{-- 7.6 makes every flag change a close-and-succeed Action with its own
             permission and a session revocation; an edit control here that did
             not do that would silently break the audit trail, so the tab is
             read-only and says so. --}}
        <p class="text-xs text-charcoal/50">{{ __('opes.students_screen.read_only_notice') }}</p>
    </div>

    @if ($links->isEmpty())
        <x-empty-state :message="__('opes.students_screen.guardians_empty')"/>
    @else
        <div class="min-w-0 overflow-x-auto rounded border border-border-primary bg-white">
            <table class="w-full min-w-[44rem] border-collapse text-sm">
                <thead class="border-b border-border-primary text-left">
                    <tr class="bg-chrome text-white">
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_guardian') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_relationship') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_validity') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_permissions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-primary">
                    @foreach ($links as $link)
                        @php
                            $guardian = $link->guardian;
                            $validity = LinkPresentation::validity($link);
                            $flags = LinkPresentation::flags($link);
                            $scopes = LinkPresentation::scopes($link);
                        @endphp
                        <tr wire:key="student-guardian-{{ $link->id }}">
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-chrome-light text-xs font-semibold uppercase text-white">
                                        {{ $guardian === null ? '?' : mb_strtoupper(mb_substr($guardian->first_name, 0, 1).mb_substr($guardian->last_name, 0, 1)) }}
                                    </span>
                                    <div class="min-w-0">
                                        @if ($guardian === null)
                                            <span class="text-charcoal/50">{{ __('opes.guardians_screen.not_recorded') }}</span>
                                        @else
                                            <a href="{{ route('guardians.show', $guardian->id) }}"
                                               class="truncate font-medium text-charcoal hover:text-primary">
                                                {{ $guardian->fullName() }}
                                            </a>
                                            <div class="truncate text-xs text-charcoal/60">{{ $guardian->phone }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-charcoal/80">
                                {{ __('opes.guardians_screen.relationship_'.$link->relationship->value) }}
                                @if ($link->relationship_other !== null && $link->relationship_other !== '')
                                    <span class="text-xs text-charcoal/60">({{ $link->relationship_other }})</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <x-status-pill :status="$validityTone[$validity]"
                                                :label="__('opes.students_screen.validity_'.$validity)"/>
                                <div class="mt-1 text-xs text-charcoal/55">
                                    {{ $link->valid_from->translatedFormat('d M Y') }}
                                    &ndash;
                                    {{ $link->valid_to?->translatedFormat('d M Y') ?? '…' }}
                                </div>
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($flags === [])
                                    <span class="text-xs text-charcoal/50">{{ __('opes.students_screen.perm_none') }}</span>
                                @else
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($flags as $flag)
                                            <span class="inline-flex items-center rounded-full border border-border-primary bg-sand/60 px-2 py-0.5 text-xs font-semibold text-charcoal/75">
                                                {{ __('opes.students_screen.perm_'.$flag) }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="mt-1.5 text-xs text-charcoal/55">
                                    <span class="font-medium">{{ __('opes.guardians_screen.effective_scopes') }}:</span>
                                    @if ($scopes === [])
                                        {{ __('opes.guardians_screen.no_effective_scopes') }}
                                    @else
                                        {{ implode(' · ', array_map(fn (string $scope) => __('opes.guardians_screen.scope_'.$scope), $scopes)) }}
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

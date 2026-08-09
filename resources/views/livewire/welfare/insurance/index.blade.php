@php
    /**
     * Status -> pill tone. The WORD carries the meaning (09-ui 10); the
     * colour only reinforces it.
     */
    $policyTone = ['active' => 'ok', 'expired' => 'amber', 'cancelled' => 'red'];
    $policyLabel = ['active' => 'Active', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];

    $coverTone = ['active' => 'ok', 'lapsed' => 'amber', 'cancelled' => 'red'];
    $coverLabel = ['active' => 'Active', 'lapsed' => 'Lapsed', 'cancelled' => 'Cancelled'];

    $claimTone = ['draft' => 'amber', 'submitted' => 'amber', 'settled' => 'ok', 'rejected' => 'red'];
    $claimLabel = ['draft' => 'Draft', 'submitted' => 'Submitted', 'settled' => 'Settled', 'rejected' => 'Rejected'];

    $coverTypeLabel = ['student' => 'Student', 'asset' => 'Asset'];

    $tabs = [
        ['value' => 'policies', 'label' => 'Policies', 'count' => $tabCounts['policies']],
        ['value' => 'insured', 'label' => 'Insured Students', 'count' => $tabCounts['insured']],
        ['value' => 'claims', 'label' => 'Claims', 'count' => $tabCounts['claims']],
        ['value' => 'uninsured', 'label' => 'Uninsured', 'count' => $tabCounts['uninsured']],
    ];
@endphp

<x-list-screen
    title="Student Insurance"
    :breadcrumb="['Dashboard', 'Welfare', 'Insurance']"
    :paginator="$rows"
    empty-message="No insurance records match these filters yet. Policies, certificates and claims appear here as they are recorded."
    rail-title="Coverage Overview"
>
    {{-- Five KPI cards: active policies, insured students, uninsured
         students, open claims, settled amount - dataset-wide numbers. --}}
    <x-slot:kpis>
        <x-kpi-card label="Active Policies" :value="$kpis['policies']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 4.5-3 8.4-7 10-4-1.6-7-5.5-7-10V6l7-3z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Insured Students" :value="$kpis['insured']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="16.5" cy="9.5" r="2.5"/><path stroke-linecap="round" d="M4 19c0-2.8 2.2-5 5-5s5 2.2 5 5M14.5 14.5c2.5.3 4.5 2.2 4.5 4.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Uninsured Students" :value="$kpis['uninsured']" icon-bg="bg-heritage-red">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4M12 17h.01M10.3 3.9L2.6 17a2 2 0 001.7 3h15.4a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Open Claims" :value="$kpis['open_claims']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6M9 16h4M7 3h7l5 5v11a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"/><path stroke-linecap="round" d="M14 3v5h5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Settled Amount" :value="number_format($kpis['settled_total']).' FCFA'" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v10M9.5 9.5c0-1 1.1-1.7 2.5-1.7s2.5.7 2.5 1.7-1.1 1.6-2.5 1.9-2.5.9-2.5 1.9 1.1 1.7 2.5 1.7 2.5-.7 2.5-1.7"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="insurance-filter-policy" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Policy</span>
            <select id="insurance-filter-policy" wire:model.live="policy"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All policies</option>
                @foreach ($policyOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        @if ($statusOptions !== [])
            <label for="insurance-filter-status" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Status</span>
                <select id="insurance-filter-status" wire:model.live="status"
                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label for="insurance-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="insurance-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search by policy no., provider or student..."
                   class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>
    </x-slot:filters>

    <x-slot:tabs>
        @foreach ($tabs as $tabOption)
            <button type="button" wire:click="selectTab('{{ $tabOption['value'] }}')"
                    @if ($tab === $tabOption['value']) aria-current="page" @endif
                    class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $tabOption['value']
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                {{ $tabOption['label'] }}
                <span class="rounded-full bg-sand px-1.5 text-xs text-charcoal/70">{{ $tabOption['count'] }}</span>
            </button>
        @endforeach
    </x-slot:tabs>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            @if ($tab === 'policies')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Policy No.</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Provider</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Cover</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Year</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Premium / Student</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Coverage</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Insured</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @elseif ($tab === 'insured')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Policy</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Certificate No.</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Enrolled On</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @elseif ($tab === 'claims')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Incident Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Policy</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Description</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Claimed</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Settled</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @else
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Cover</th>
            @endif
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="insurance-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
            @if ($tab === 'policies')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->policy_no }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->provider }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $coverTypeLabel[$row->cover_type] ?? $row->cover_type }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->year_name }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->premium_per_student !== null ? number_format($row->premium_per_student) : '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->coverage_start }} → {{ $row->coverage_end }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->insured_count }}</td>
                <td class="px-4 py-2.5"><x-status-pill :status="$policyTone[$row->status] ?? 'ok'" :label="$policyLabel[$row->status] ?? $row->status"/></td>
            @elseif ($tab === 'insured')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_level ?? '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->policy_no }} · {{ $row->provider }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->certificate_no ?? '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->enrolled_on }}</td>
                <td class="px-4 py-2.5"><x-status-pill :status="$coverTone[$row->status] ?? 'ok'" :label="$coverLabel[$row->status] ?? $row->status"/></td>
            @elseif ($tab === 'claims')
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->incident_date }}</td>
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->policy_no }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->first_name !== null ? trim($row->first_name.' '.$row->last_name) : '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ \Illuminate\Support\Str::limit($row->description, 60) }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format($row->amount_claimed) }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->amount_settled !== null ? number_format($row->amount_settled) : '—' }}</td>
                <td class="px-4 py-2.5"><x-status-pill :status="$claimTone[$row->status] ?? 'ok'" :label="$claimLabel[$row->status] ?? $row->status"/></td>
            @else
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_level ?? '—' }}</td>
                <td class="px-4 py-2.5"><x-status-pill status="red" label="Not covered"/></td>
            @endif
        </tr>
    @endforeach

    {{-- Mobile cards: the two or three columns that matter on a handset. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="insurance-card-{{ $tab }}-{{ $row->id }}"
                     class="rounded border border-sand bg-white p-3">
                @if ($tab === 'policies')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->policy_no }} · {{ $row->provider }}</p>
                        <x-status-pill :status="$policyTone[$row->status] ?? 'ok'" :label="$policyLabel[$row->status] ?? $row->status"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $coverTypeLabel[$row->cover_type] ?? $row->cover_type }} cover · {{ $row->insured_count }} insured · ends {{ $row->coverage_end }}</p>
                @elseif ($tab === 'insured')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</p>
                        <x-status-pill :status="$coverTone[$row->status] ?? 'ok'" :label="$coverLabel[$row->status] ?? $row->status"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->policy_no }} · Cert. {{ $row->certificate_no ?? '—' }}</p>
                @elseif ($tab === 'claims')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->policy_no }} · {{ $row->incident_date }}</p>
                        <x-status-pill :status="$claimTone[$row->status] ?? 'ok'" :label="$claimLabel[$row->status] ?? $row->status"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ number_format($row->amount_claimed) }} FCFA claimed · {{ $row->amount_settled !== null ? number_format($row->amount_settled).' FCFA settled' : 'not settled' }}</p>
                @else
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</p>
                        <x-status-pill status="red" label="Not covered"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->matricule }} · {{ $row->class_level ?? '—' }}</p>
                @endif
            </article>
        @endforeach
    </x-slot:cards>

    {{-- Right rail: coverage meter + claims summary + expiring policies. --}}
    <x-slot:rail>
        <div class="space-y-4">
            @php
                $totalStudents = $kpis['insured'] + $kpis['uninsured'];
                $coveragePct = $totalStudents > 0 ? round($kpis['insured'] * 100 / $totalStudents, 2) : 0.0;
            @endphp
            <section aria-label="Coverage overview" class="rounded border border-sand bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">Coverage Overview</h3>
                <p class="text-2xl font-semibold tabular-nums text-charcoal">{{ $kpis['insured'] }}
                    <span class="text-sm font-normal text-charcoal/60">insured students</span></p>
                <div class="mt-2 h-1.5 w-full rounded-full bg-sand">
                    <div class="h-1.5 rounded-full bg-primary" style="width: {{ min(100, (int) round($coveragePct)) }}%"></div>
                </div>
                <ul class="mt-2 space-y-1 text-xs text-charcoal/70">
                    <li class="flex justify-between"><span>Insured</span><span class="tabular-nums">{{ $kpis['insured'] }} ({{ number_format($coveragePct, 2) }}%)</span></li>
                    <li class="flex justify-between"><span>Uninsured</span><span class="tabular-nums">{{ $kpis['uninsured'] }}</span></li>
                    <li class="flex justify-between"><span>Active policies</span><span class="tabular-nums">{{ $kpis['policies'] }}</span></li>
                </ul>
            </section>

            <section aria-label="Claims summary" class="rounded border border-sand bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">Claims Summary</h3>
                <ul class="space-y-1 text-sm text-charcoal/80">
                    <li class="flex justify-between"><span>Draft</span><span class="tabular-nums">{{ $claimsSummary['draft'] }}</span></li>
                    <li class="flex justify-between"><span>Submitted</span><span class="tabular-nums">{{ $claimsSummary['submitted'] }}</span></li>
                    <li class="flex justify-between"><span>Settled</span><span class="tabular-nums">{{ $claimsSummary['settled'] }}</span></li>
                    <li class="flex justify-between"><span>Rejected</span><span class="tabular-nums">{{ $claimsSummary['rejected'] }}</span></li>
                </ul>
                <dl class="mt-2 border-t border-sand pt-2 text-xs text-charcoal/70">
                    <div class="flex justify-between"><dt>Total claimed</dt><dd class="tabular-nums">{{ number_format($claimsSummary['claimed_total']) }} FCFA</dd></div>
                    <div class="flex justify-between"><dt>Total settled</dt><dd class="tabular-nums">{{ number_format($claimsSummary['settled_total']) }} FCFA</dd></div>
                </dl>
            </section>

            <section aria-label="Expiring policies" class="rounded border border-sand bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">Expiring Policies</h3>
                @if ($expiringPolicies === [])
                    <p class="text-sm text-charcoal/60">No policies expiring within 60 days.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($expiringPolicies as $item)
                            <li class="flex items-start justify-between gap-2 text-sm">
                                <div>
                                    <p class="font-medium text-charcoal">{{ $item['policy_no'] }}</p>
                                    <p class="text-xs text-charcoal/60">{{ $item['provider'] }}</p>
                                </div>
                                <span class="whitespace-nowrap text-xs font-semibold text-heritage-red">Ends: {{ $item['coverage_end'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </x-slot:rail>
</x-list-screen>

{{-- One policy's file — /insurance/policies/{policy}. Cover identity and
     the fee-item billing linkage, cover/claim KPIs, every insured student,
     then every claim against the policy with its money totals, plus a
     printable Policy Summary (Assets\Livewire\Show's asset-card pattern).
     Heritage design system, PROGRESS.md §4a Phase 2. --}}

@php
    use App\Support\Money\Money;

    $policyTone = [
        'active' => 'ok',
        'expired' => 'amber',
        'cancelled' => 'red',
    ];

    $claimTone = [
        'draft' => 'amber',
        'submitted' => 'amber',
        'settled' => 'ok',
        'rejected' => 'red',
    ];

    $insuredTone = [
        'active' => 'ok',
        'lapsed' => 'amber',
        'cancelled' => 'red',
    ];
@endphp

<div class="min-w-0 space-y-4">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.welfare_detail.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true">/</li>
            <li><a href="{{ url('/welfare/insurance') }}" class="hover:text-primary">{{ __('opes.welfare_detail.breadcrumb_insurance') }}</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ $policy->policy_no }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold text-charcoal">{{ $policy->policy_no }}</h1>
            <x-status-pill :status="$policyTone[$policy->status] ?? 'ok'" :label="__('opes.welfare_detail.policy_status.'.$policy->status)"/>
            <span class="inline-flex items-center rounded-full border border-badge-blue/40 bg-badge-blue/10 px-2.5 py-0.5 text-xs font-semibold text-badge-blue">
                {{ __('opes.welfare_detail.cover_type.'.$policy->cover_type) }}
            </span>
        </div>
        <a href="{{ url('/welfare/insurance') }}"
           class="rounded-xl border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
            {{ __('opes.welfare_detail.back_to_insurance') }}
        </a>
    </div>

    {{-- ── Cover KPIs ─────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        @foreach ([
            ['label' => __('opes.welfare_detail.kpi_insured_active'), 'value' => $counts['active']],
            ['label' => __('opes.welfare_detail.kpi_insured_total'), 'value' => $counts['total']],
            ['label' => __('opes.welfare_detail.kpi_claims'), 'value' => $claimTotals['total']],
            ['label' => __('opes.welfare_detail.kpi_settled_amount'), 'value' => Money::of($claimTotals['settled_amount'])->format(false)],
            ['label' => __('opes.welfare_detail.kpi_days_remaining'), 'value' => max(0, $daysRemaining)],
        ] as $kpi)
            <div class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-charcoal/50">{{ $kpi['label'] }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-charcoal">{{ $kpi['value'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div class="min-w-0 space-y-4 xl:col-span-2">
            {{-- ── Policy card ────────────────────────────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.policy_details') }}</h2>
                </div>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 px-4 py-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_provider') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ $policy->provider }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_cover_type') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ __('opes.welfare_detail.cover_type.'.$policy->cover_type) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_academic_year') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ $policy->year_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_premium_per_student') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">
                            {{ $policy->premium_per_student !== null ? Money::of((int) $policy->premium_per_student)->format(false) : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_coverage_start') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ $policy->coverage_start }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_coverage_end') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ $policy->coverage_end }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_asset_link') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">
                            {{ $policy->asset_id !== null ? __('opes.welfare_detail.asset_ref', ['id' => $policy->asset_id]) : __('opes.welfare_detail.asset_unlinked') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_updated_at') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ $policy->updated_at ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- ── Billing linkage (the premium is a FeeItem) ─────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.billing_title') }}</h2>
                </div>
                <div class="px-4 py-4">
                    @if ($policy->fee_item_id !== null)
                        <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_fee_item') }}</dt>
                                <dd class="mt-0.5 text-base text-charcoal">{{ $policy->fee_item_code }} — {{ $policy->fee_item_name }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_recurrence') }}</dt>
                                <dd class="mt-0.5 text-base text-charcoal">{{ __('opes.welfare_detail.recurrence.'.$policy->fee_item_recurrence) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_mandatory') }}</dt>
                                <dd class="mt-0.5 text-base text-charcoal">
                                    {{ $policy->fee_item_mandatory ? __('opes.welfare_detail.yes') : __('opes.welfare_detail.no') }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_expected_premium') }}</dt>
                                <dd class="mt-0.5 text-base font-medium tabular-nums text-charcoal">
                                    {{ $premiumTotal !== null ? Money::of($premiumTotal)->format(false) : '—' }}
                                </dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-xs text-charcoal/60">{{ __('opes.welfare_detail.expected_premium_note') }}</p>
                    @else
                        <p class="text-sm text-charcoal/70">{{ __('opes.welfare_detail.no_fee_item') }}</p>
                    @endif
                </div>
            </div>

            {{-- ── Insured students ───────────────────────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.insured_students') }}</h2>
                    <p class="mt-1 text-xs text-charcoal/60">
                        {{ __('opes.welfare_detail.insured_breakdown', [
                            'active' => $counts['active'],
                            'lapsed' => $counts['lapsed'],
                            'cancelled' => $counts['cancelled'],
                        ]) }}
                    </p>
                </div>
                @if ($insuredStudents->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state :message="__('opes.welfare_detail.no_insured_students')"/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-primary text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_student') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_matricule') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_class') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_certificate') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_enrolled') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary bg-white">
                                @foreach ($insuredStudents as $student)
                                    <tr wire:key="insured-{{ $student->id }}">
                                        <td class="px-4 py-2 font-medium text-charcoal">{{ trim($student->first_name.' '.$student->last_name) }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $student->matricule }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $student->class_level ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $student->certificate_no ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $student->enrolled_on }}</td>
                                        <td class="px-4 py-2">
                                            <x-status-pill :status="$insuredTone[$student->status] ?? 'amber'"
                                                           :label="__('opes.welfare_detail.insured_status.'.$student->status)"/>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Claims ─────────────────────────────────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.claims_title') }}</h2>
                    <p class="mt-1 text-xs text-charcoal/60">
                        {{ __('opes.welfare_detail.claims_breakdown', [
                            'submitted' => $claimTotals['submitted'],
                            'settled' => $claimTotals['settled'],
                            'rejected' => $claimTotals['rejected'],
                        ]) }}
                    </p>
                </div>
                @if ($claims->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state :message="__('opes.welfare_detail.no_claims')"/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-primary text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_incident') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_student') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_description') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right">{{ __('opes.welfare_detail.col_claimed') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right">{{ __('opes.welfare_detail.col_settled_amount') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_settled_on') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_recorded_by') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary bg-white">
                                @foreach ($claims as $claim)
                                    <tr wire:key="claim-{{ $claim->id }}">
                                        <td class="px-4 py-2 text-charcoal/70">{{ $claim->incident_date }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">
                                            @if ($claim->first_name !== null)
                                                {{ trim($claim->first_name.' '.$claim->last_name) }}
                                                <span class="block text-xs text-charcoal/60">{{ $claim->certificate_no ?? $claim->matricule }}</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $claim->description }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ Money::of((int) $claim->amount_claimed)->format(false) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">
                                            {{ $claim->amount_settled !== null ? Money::of((int) $claim->amount_settled)->format(false) : '—' }}
                                        </td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $claim->settled_on ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $claim->recorded_by_name ?? '—' }}</td>
                                        <td class="px-4 py-2">
                                            <x-status-pill :status="$claimTone[$claim->status] ?? 'ok'" :label="__('opes.welfare_detail.claim_status.'.$claim->status)"/>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t border-border-primary bg-sand/30 text-sm font-semibold text-charcoal">
                                <tr>
                                    <td class="px-4 py-2" colspan="3">{{ __('opes.welfare_detail.total') }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ Money::of($claimTotals['claimed_amount'])->format(false) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ Money::of($claimTotals['settled_amount'])->format(false) }}</td>
                                    <td class="px-4 py-2" colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── Right rail: printable Policy Summary ───────────────────── --}}
        <div class="min-w-0 space-y-4">
            <section class="rounded-xl border border-border-primary bg-white p-4 shadow-sm print:border-0 print:shadow-none" id="policy-summary-section">
                <div class="flex items-center justify-between gap-3 print:hidden">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.welfare_detail.print_policy_summary') }}</h2>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="window.print()"
                                class="rounded-xl border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ __('opes.welfare_detail.print') }}
                        </button>
                        <button type="button" wire:click="exportPolicySummaryPdf"
                                class="rounded-xl bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                            {{ __('opes.welfare_detail.export_pdf') }}
                        </button>
                    </div>
                </div>

                <dl class="mt-3 space-y-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_policy') }}</dt>
                        <dd class="mt-0.5 text-base font-medium text-charcoal">{{ $policy->policy_no }} — {{ $policy->provider }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.insured_students') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal tabular-nums">{{ $counts['total'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.claims_title') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal tabular-nums">{{ $claimTotals['total'] }}</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.welfare_detail.claims_money_title') }}</h2>
                <dl class="mt-3 space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-charcoal/70">{{ __('opes.welfare_detail.col_claimed') }}</dt>
                        <dd class="text-base font-medium tabular-nums text-charcoal">{{ Money::of($claimTotals['claimed_amount'])->format(false) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-charcoal/70">{{ __('opes.welfare_detail.col_settled_amount') }}</dt>
                        <dd class="text-base font-medium tabular-nums text-charcoal">{{ Money::of($claimTotals['settled_amount'])->format(false) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-charcoal/70">{{ __('opes.welfare_detail.outstanding') }}</dt>
                        <dd class="text-base font-medium tabular-nums text-charcoal">
                            {{ Money::of(max(0, $claimTotals['claimed_amount'] - $claimTotals['settled_amount']))->format(false) }}
                        </dd>
                    </div>
                </dl>
            </section>
        </div>
    </div>
</div>

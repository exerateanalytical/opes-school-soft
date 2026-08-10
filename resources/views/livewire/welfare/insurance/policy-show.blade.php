{{-- One policy's file — /insurance/policies/{policy}. Header summary,
     insured students, then claims against this policy, plus a printable
     Policy Summary (Assets\Livewire\Show's asset-card pattern). --}}

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
@endphp

<div class="min-w-0 space-y-4">
    <nav aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>Dashboard</li>
            <li aria-hidden="true">/</li>
            <li><a href="{{ url('/welfare/insurance') }}" class="hover:text-primary">Insurance</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ $policy->policy_no }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold text-charcoal">{{ $policy->policy_no }}</h1>
            <x-status-pill :status="$policyTone[$policy->status] ?? 'ok'" :label="ucfirst($policy->status)"/>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div class="min-w-0 space-y-4 xl:col-span-2">
            {{-- ── Policy card ────────────────────────────────────────── --}}
            <div class="rounded border border-sand bg-white">
                <div class="border-b border-sand px-4 py-3">
                    <h2 class="text-sm font-semibold text-charcoal">Policy details</h2>
                </div>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 px-4 py-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Provider</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $policy->provider }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Cover type</dt>
                        <dd class="mt-0.5 text-charcoal">{{ ucfirst($policy->cover_type) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Academic year</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $policy->year_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Premium per student</dt>
                        <dd class="mt-0.5 text-charcoal">
                            {{ $policy->premium_per_student !== null ? Money::of((int) $policy->premium_per_student)->format(false) : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Coverage start</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $policy->coverage_start }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Coverage end</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $policy->coverage_end }}</dd>
                    </div>
                </dl>
            </div>

            {{-- ── Insured students ───────────────────────────────────── --}}
            <div class="rounded border border-sand bg-white">
                <div class="border-b border-sand px-4 py-3">
                    <h2 class="text-sm font-semibold text-charcoal">Insured students</h2>
                </div>
                @if ($insuredStudents->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state message="No students insured under this policy."/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-sand text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">Student</th>
                                    <th scope="col" class="px-4 py-2">Matricule</th>
                                    <th scope="col" class="px-4 py-2">Class</th>
                                    <th scope="col" class="px-4 py-2">Certificate</th>
                                    <th scope="col" class="px-4 py-2">Enrolled</th>
                                    <th scope="col" class="px-4 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand bg-white">
                                @foreach ($insuredStudents as $student)
                                    <tr wire:key="insured-{{ $student->id }}">
                                        <td class="px-4 py-2 font-medium text-charcoal">{{ trim($student->first_name.' '.$student->last_name) }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $student->matricule }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $student->class_level ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $student->certificate_no ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $student->enrolled_on }}</td>
                                        <td class="px-4 py-2 capitalize text-charcoal/70">{{ $student->status }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Claims ─────────────────────────────────────────────── --}}
            <div class="rounded border border-sand bg-white">
                <div class="border-b border-sand px-4 py-3">
                    <h2 class="text-sm font-semibold text-charcoal">Claims</h2>
                </div>
                @if ($claims->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state message="No claims recorded against this policy."/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-sand text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">Incident</th>
                                    <th scope="col" class="px-4 py-2">Student</th>
                                    <th scope="col" class="px-4 py-2">Description</th>
                                    <th scope="col" class="px-4 py-2 text-right">Claimed</th>
                                    <th scope="col" class="px-4 py-2 text-right">Settled</th>
                                    <th scope="col" class="px-4 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand bg-white">
                                @foreach ($claims as $claim)
                                    <tr wire:key="claim-{{ $claim->id }}">
                                        <td class="px-4 py-2 text-charcoal/70">{{ $claim->incident_date }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">
                                            {{ $claim->first_name !== null ? trim($claim->first_name.' '.$claim->last_name) : '—' }}
                                        </td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $claim->description }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ Money::of((int) $claim->amount_claimed)->format(false) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">
                                            {{ $claim->amount_settled !== null ? Money::of((int) $claim->amount_settled)->format(false) : '—' }}
                                        </td>
                                        <td class="px-4 py-2">
                                            <x-status-pill :status="$claimTone[$claim->status] ?? 'ok'" :label="ucfirst($claim->status)"/>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── Right rail: printable Policy Summary ───────────────────── --}}
        <div class="min-w-0 space-y-4">
            <section class="rounded border border-sand bg-white p-4 print:border-0 print:shadow-none" id="policy-summary-section">
                <div class="flex items-center justify-between gap-3 print:hidden">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Print policy summary</h2>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="window.print()"
                                class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            Print
                        </button>
                        <button type="button" wire:click="exportPolicySummaryPdf"
                                class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                            Export PDF
                        </button>
                    </div>
                </div>

                <dl class="mt-3 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Policy</dt>
                        <dd class="mt-0.5 font-medium text-charcoal">{{ $policy->policy_no }} — {{ $policy->provider }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Insured students</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $insuredStudents->count() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Claims</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $claims->count() }}</dd>
                    </div>
                </dl>
            </section>
        </div>
    </div>
</div>

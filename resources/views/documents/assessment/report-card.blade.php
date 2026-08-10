{{-- docs/specs/10-documents.md §6.1 and 01-assessment.md §13 - the bulletin.

     EVERY value on this page comes out of $payload, which IS the immutable
     `report_card_snapshots.payload` RenderReportCard wrote inside the
     publication transaction (SnapshotSourceMap maps the source). Nothing here
     re-derives an average, a rank or a statistic: 01-assessment §9.4 makes a
     second implementation of the average a review-blocking defect, and §13's
     T13 re-renders this template after mutating `marks` and asserts the hash
     has not moved.

     Blocks the payload does NOT carry are ABSENT, never blank (§8.4): a
     nursery card has no rank, average or mention, and an empty "Rang" box on
     a printed bulletin invites someone to fill it in by hand. --}}
@extends('documents.layout')

@php
    $lang = $document['language'];
    $fr = $lang === 'fr';

    $student = is_array($payload['student'] ?? null) ? $payload['student'] : [];
    $period = is_array($payload['period'] ?? null) ? $payload['period'] : [];
    $group = is_array($payload['class_group'] ?? null) ? $payload['class_group'] : [];
    $framework = is_array($payload['framework'] ?? null) ? $payload['framework'] : [];
    $subjects = is_array($payload['subjects'] ?? null) ? $payload['subjects'] : [];
    $totals = is_array($payload['totals'] ?? null) ? $payload['totals'] : null;
    $average = is_array($payload['general_average'] ?? null) ? $payload['general_average'] : null;
    $rank = is_array($payload['rank'] ?? null) ? $payload['rank'] : null;
    $stats = is_array($payload['class_statistics'] ?? null) ? $payload['class_statistics'] : null;
    $conseil = is_array($payload['conseil'] ?? null) ? $payload['conseil'] : null;
    $attendance = is_array($payload['attendance'] ?? null) ? $payload['attendance'] : null;
    $remarks = is_array($payload['remarks'] ?? null) ? $payload['remarks'] : null;
    $usesCoefficients = $totals !== null;

    $periodName = $fr
        ? (($period['name_fr'] ?? '') ?: ($period['name'] ?? ''))
        : (($period['name'] ?? '') ?: ($period['name_fr'] ?? ''));

    $dash = '—';
@endphp

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 4pt 0 2pt 0;">{{ __('documents.report_card.title', [], $lang) }}</h2>
    <p class="doc-center doc-small" style="margin: 0 0 8pt 0;">
        {{ __('documents.report_card.period', [], $lang) }}: <strong>{{ $periodName }}</strong>
        &nbsp;·&nbsp;
        {{ __('documents.report_card.class', [], $lang) }}: <strong>{{ $group['name'] ?? $dash }}</strong>
        @if (($period['starts_on'] ?? null) !== null)
            &nbsp;·&nbsp;{{ $period['starts_on'] }} → {{ $period['ends_on'] ?? '' }}
        @endif
    </p>

    {{-- Student identity. --}}
    <table class="doc-block doc-small">
        <tr>
            <td style="width: 55%;">
                <strong>{{ __('documents.report_card.student', [], $lang) }}:</strong>
                {{ trim(($student['first_name'] ?? '').' '.($student['last_name'] ?? '')) ?: $subject['label'] }}
            </td>
            <td style="width: 45%;">
                <strong>{{ __('documents.report_card.matricule', [], $lang) }}:</strong>
                {{ $student['matricule'] ?? $dash }}
            </td>
        </tr>
        <tr>
            <td>
                <strong>{{ __('documents.report_card.date_of_birth', [], $lang) }}:</strong>
                {{ $student['date_of_birth'] ?? $dash }}
            </td>
            <td>
                <strong>{{ __('documents.report_card.gender', [], $lang) }}:</strong>
                {{ $student['gender'] ?? $dash }}
            </td>
        </tr>
        <tr>
            <td>
                <strong>{{ __('documents.report_card.repeater', [], $lang) }}:</strong>
                {{ ($student['is_repeat'] ?? false)
                    ? __('documents.report_card.yes', [], $lang)
                    : __('documents.report_card.no', [], $lang) }}
            </td>
            <td>
                <strong>{{ __('documents.report_card.boarding_status', [], $lang) }}:</strong>
                {{ $student['boarding_status'] ?? $dash }}
            </td>
        </tr>
    </table>

    {{-- 8.4 / T19: Family F (nursery) carries no numeric card at all. --}}
    @if (isset($payload['competency_note']))
        <p class="doc-small"><em>{{ __('documents.report_card.competency_note', [], $lang) }}</em></p>
    @endif

    {{-- The per-subject table. 13.5's columns; the coefficient pair is present
         only when the framework uses coefficients (i.e. the payload carries a
         totals row). --}}
    <table class="doc-block" style="font-size: 8pt;">
        <thead>
            <tr style="border-bottom: 0.7pt solid #333;">
                <th style="text-align: left; padding: 2pt;">{{ __('documents.report_card.subject', [], $lang) }}</th>
                @if ($usesCoefficients)
                    <th style="text-align: right; padding: 2pt;">{{ __('documents.report_card.coefficient', [], $lang) }}</th>
                @endif
                <th style="text-align: right; padding: 2pt;">{{ __('documents.report_card.mark', [], $lang) }}</th>
                @if ($usesCoefficients)
                    <th style="text-align: right; padding: 2pt;">{{ __('documents.report_card.mark_times_coef', [], $lang) }}</th>
                @endif
                @if ($rank !== null)
                    <th style="text-align: right; padding: 2pt;">{{ __('documents.report_card.subject_rank', [], $lang) }}</th>
                @endif
                <th style="text-align: right; padding: 2pt;">{{ __('documents.report_card.class_average_subject', [], $lang) }}</th>
                <th style="text-align: right; padding: 2pt;">{{ __('documents.report_card.cote_min_max', [], $lang) }}</th>
                <th style="text-align: left; padding: 2pt;">{{ __('documents.report_card.appreciation', [], $lang) }}</th>
                <th style="text-align: left; padding: 2pt; width: 46pt;">{{ __('documents.report_card.teacher_visa', [], $lang) }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($subjects as $row)
                <tr style="border-bottom: 0.3pt solid #bbb;">
                    <td style="padding: 2pt;">{{ $fr ? ($row['subject_name_fr'] ?? $row['subject_name'] ?? '') : ($row['subject_name'] ?? $row['subject_name_fr'] ?? '') }}</td>
                    @if ($usesCoefficients)
                        <td style="padding: 2pt; text-align: right;">{{ $row['coefficient'] ?? $dash }}</td>
                    @endif
                    <td style="padding: 2pt; text-align: right;">
                        {{ $row['subject_score'] ?? __('documents.report_card.not_assessed', [], $lang) }}
                    </td>
                    @if ($usesCoefficients)
                        <td style="padding: 2pt; text-align: right;">{{ $row['score_times_coef'] ?? $dash }}</td>
                    @endif
                    @if ($rank !== null)
                        <td style="padding: 2pt; text-align: right;">
                            {{ $row['subject_rank'] ?? $dash }}@if (($row['subject_rank'] ?? null) !== null && ($row['subject_rank_denominator'] ?? null) !== null)/{{ $row['subject_rank_denominator'] }}@endif
                        </td>
                    @endif
                    <td style="padding: 2pt; text-align: right;">{{ $row['class_average_subject'] ?? $dash }}</td>
                    <td style="padding: 2pt; text-align: right;">
                        @if (($row['cote_min'] ?? null) !== null && ($row['cote_max'] ?? null) !== null)
                            {{ $row['cote_min'] }} / {{ $row['cote_max'] }}
                        @else
                            {{ $dash }}
                        @endif
                    </td>
                    <td style="padding: 2pt;">{{ $row['appreciation'] ?? ($row['grade_letter'] ?? '') }}</td>
                    <td style="padding: 2pt;"></td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" style="padding: 4pt;">{{ __('documents.report_card.no_subjects', [], $lang) }}</td>
                </tr>
            @endforelse
        </tbody>
        @if ($totals !== null)
            <tfoot>
                <tr style="border-top: 0.7pt solid #333;">
                    <th style="text-align: left; padding: 2pt;">{{ __('documents.report_card.totals', [], $lang) }}</th>
                    <th style="text-align: right; padding: 2pt;">{{ $totals['sum_coefficient'] ?? $dash }}</th>
                    <th style="padding: 2pt;"></th>
                    <th style="text-align: right; padding: 2pt;">{{ $totals['sum_score_times_coef'] ?? $dash }}</th>
                    <th colspan="5" style="padding: 2pt;"></th>
                </tr>
            </tfoot>
        @endif
    </table>

    {{-- 13.6: the stated derivation, verbatim from the snapshot. This is where
         a parent, class master or inspector checks the arithmetic by hand; a
         card that prints only the final average cannot be verified. --}}
    @if ($totals !== null && ($totals['derivation'] ?? null) !== null)
        <p class="doc-small" style="margin: 0 0 6pt 0;">
            <strong>{{ __('documents.report_card.derivation', [], $lang) }}:</strong> {{ $totals['derivation'] }}
        </p>
    @endif

    {{-- Average / rank / mention, then the class statistics as at publication. --}}
    @if ($average !== null || $rank !== null || $stats !== null)
        <table class="doc-block doc-small">
            <tr>
                <td style="width: 50%; vertical-align: top; padding-right: 8pt;">
                    @if ($average !== null)
                        <table>
                            <tr>
                                <td><strong>{{ __('documents.report_card.general_average', [], $lang) }}</strong></td>
                                <td style="text-align: right; font-weight: bold;">
                                    {{ $average['display'] ?? __('documents.report_card.not_assessed', [], $lang) }}
                                    @if (($framework['max_score'] ?? null) !== null)
                                        / {{ $framework['max_score'] }}
                                    @endif
                                </td>
                            </tr>
                            @if ($rank !== null)
                                <tr>
                                    <td><strong>{{ __('documents.report_card.rank', [], $lang) }}</strong></td>
                                    <td style="text-align: right;">
                                        @if (($rank['is_ranked'] ?? false) && ($rank['position'] ?? null) !== null)
                                            {{ $rank['position'] }} {{ __('documents.report_card.of', [], $lang) }} {{ $rank['denominator'] ?? $dash }}
                                        @else
                                            {{ __('documents.report_card.not_ranked', [], $lang) }}
                                            @if (($rank['nc_reason'] ?? null) !== null)
                                                ({{ $rank['nc_reason'] }})
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endif
                            @if (($payload['mention'] ?? null) !== null)
                                <tr>
                                    <td><strong>{{ __('documents.report_card.mention', [], $lang) }}</strong></td>
                                    <td style="text-align: right;">{{ $payload['mention'] }}</td>
                                </tr>
                            @endif
                            @if (($payload['gpa'] ?? null) !== null)
                                <tr>
                                    <td><strong>{{ __('documents.report_card.gpa', [], $lang) }}</strong></td>
                                    <td style="text-align: right;">{{ $payload['gpa'] }}</td>
                                </tr>
                            @endif
                        </table>
                    @endif
                </td>
                <td style="width: 50%; vertical-align: top;">
                    @if ($stats !== null)
                        <div><strong>{{ __('documents.report_card.class_statistics', [], $lang) }}</strong></div>
                        <table>
                            <tr>
                                <td>{{ __('documents.report_card.stat_n', [], $lang) }}</td>
                                <td style="text-align: right;">{{ $stats['n'] ?? $dash }}</td>
                            </tr>
                            <tr>
                                <td>{{ __('documents.report_card.stat_mean', [], $lang) }}</td>
                                <td style="text-align: right;">{{ $stats['mean'] ?? $dash }}</td>
                            </tr>
                            <tr>
                                <td>{{ __('documents.report_card.stat_max', [], $lang) }}</td>
                                <td style="text-align: right;">{{ $stats['max'] ?? $dash }}</td>
                            </tr>
                            <tr>
                                <td>{{ __('documents.report_card.stat_min', [], $lang) }}</td>
                                <td style="text-align: right;">{{ $stats['min'] ?? $dash }}</td>
                            </tr>
                            <tr>
                                <td>{{ __('documents.report_card.stat_median', [], $lang) }}</td>
                                <td style="text-align: right;">{{ $stats['median'] ?? $dash }}</td>
                            </tr>
                            <tr>
                                {{-- 10.7: the divisor is stated on the report, not
                                     merely in the field name. --}}
                                <td>{{ __('documents.report_card.stat_stdev', [], $lang) }}</td>
                                <td style="text-align: right;">{{ $stats['stdev_population'] ?? $dash }}</td>
                            </tr>
                            <tr>
                                <td>{{ __('documents.report_card.stat_pass_count', [], $lang) }}</td>
                                <td style="text-align: right;">{{ $stats['pass_count'] ?? $dash }}</td>
                            </tr>
                        </table>
                    @endif
                </td>
            </tr>
        </table>
    @endif

    {{-- 15.2: a frozen ranking must SAY it is frozen, otherwise a reader
         comparing two cards from the same class sees an unexplained
         inconsistency. --}}
    @if (($payload['rank_frozen_at'] ?? null) !== null)
        <p class="doc-small">
            <em>{{ __('documents.report_card.rank_frozen', ['date' => $payload['rank_frozen_at']], $lang) }}</em>
        </p>
    @endif

    {{-- The grading scale, stated from the framework the snapshot pinned - not
         from a live grade_bands read, which T13 forbids. --}}
    @if (($framework['max_score'] ?? null) !== null)
        <p class="doc-small" style="margin: 0 0 6pt 0;">
            <strong>{{ __('documents.report_card.grade_scale', [], $lang) }}:</strong>
            0 – {{ $framework['max_score'] }}
            @if (($framework['pass_score'] ?? null) !== null)
                &nbsp;·&nbsp; {{ __('documents.report_card.stat_pass_count', [], $lang) }} ≥ {{ $framework['pass_score'] }}
            @endif
        </p>
    @endif

    {{-- Attendance, conduct and the conseil award. Each block reports itself
         UNAVAILABLE where the capture does not exist in this build (§14, §12):
         the one thing that must never happen is a bulletin carrying an absence
         figure or an award the system invented. --}}
    <table class="doc-block doc-small">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 8pt;">
                <strong>{{ __('documents.report_card.attendance', [], $lang) }}</strong><br>
                @if ($attendance !== null && ($attendance['available'] ?? false) === false)
                    <span class="doc-muted">{{ $attendance['note'] ?? __('documents.report_card.unavailable', [], $lang) }}</span>
                @else
                    <span class="doc-muted">{{ __('documents.report_card.unavailable', [], $lang) }}</span>
                @endif
            </td>
            <td style="width: 50%; vertical-align: top;">
                <strong>{{ __('documents.report_card.conduct', [], $lang) }}</strong><br>
                @php $conduct = is_array($payload['conduct'] ?? null) ? $payload['conduct'] : null; @endphp
                @if ($conduct !== null && ($conduct['available'] ?? false) === false)
                    <span class="doc-muted">{{ $conduct['note'] ?? __('documents.report_card.unavailable', [], $lang) }}</span>
                @elseif ($conduct !== null && ($conduct['recorded'] ?? false) === false)
                    <span class="doc-muted">{{ $dash }}</span>
                @elseif ($conduct !== null)
                    @foreach ((array) ($conduct['values'] ?? []) as $key => $value)
                        @if (is_scalar($value))
                            {{ $key }}: {{ $value }}<br>
                        @endif
                    @endforeach
                @endif
            </td>
        </tr>
    </table>

    <p class="doc-small" style="margin: 0 0 8pt 0;">
        <strong>{{ __('documents.report_card.decision', [], $lang) }}:</strong>
        @if ($conseil !== null && ($conseil['decided'] ?? false) === true && is_array($conseil['decision'] ?? null))
            {{ $conseil['decision']['decision'] ?? ($conseil['decision']['award'] ?? $dash) }}
        @else
            <span class="doc-muted">{{ __('documents.report_card.no_decision', [], $lang) }}</span>
        @endif
    </p>

    @if ($remarks !== null && ($remarks['available'] ?? false) === true && ($remarks['entries'] ?? []) !== [])
        <p class="doc-small"><strong>{{ __('documents.report_card.remarks', [], $lang) }}</strong></p>
        <table class="doc-block doc-small">
            @foreach ($remarks['entries'] as $entry)
                <tr>
                    <td style="width: 22%; padding: 2pt;">{{ $entry['scope'] ?? '' }}</td>
                    <td style="padding: 2pt;">{{ $entry['body'] ?? ($entry['text'] ?? '') }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @include('documents.blocks.signature_block')
    @include('documents.blocks.qr_block')
@endsection

{{-- docs/specs/10-documents.md §7.9 - Testimonial / Attestation de scolarité
     et de conduite. Authored narrative first, structured facts appended;
     the whole payload was frozen at issue by Students\Actions\PrintTestimonial. --}}
@extends('documents.layout')

@php
    $t = $payload['testimonial'];
    $lang = $document['language'];
@endphp

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')

    <h2 class="doc-center" style="margin: 10pt 0 12pt 0; letter-spacing: 2pt; text-transform: uppercase;">
        {{ __('documents.testimonial.title', [], $lang) }}
    </h2>

    @include('documents.blocks.subject_identity', ['identity' => $t['identity']])

    <p style="margin: 12pt 0; line-height: 1.7; text-align: justify; white-space: pre-line;">{{ $t['body'] }}</p>

    <h3 style="margin: 10pt 0 4pt 0; font-size: 10pt;">{{ __('documents.testimonial.facts', [], $lang) }}</h3>
    <div class="doc-rule"></div>

    <table class="doc-block doc-small" style="line-height: 1.9;">
        <tr>
            <td style="width: 50%;"><strong>{{ __('documents.testimonial.attended', [], $lang) }}:</strong>
                {{ __('documents.testimonial.attended_range', ['from' => $t['attended_from'], 'to' => $t['attended_to']], $lang) }}</td>
            <td style="width: 50%;"><strong>{{ __('documents.testimonial.level', [], $lang) }}:</strong> {{ $t['level'] }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('documents.testimonial.attendance_rate', [], $lang) }}:</strong>
                @if ($t['attendance_rate'] !== null)
                    {{ __('documents.testimonial.attendance_rate_value', ['rate' => $t['attendance_rate'], 'registers' => $t['attendance_registers']], $lang) }}
                @else
                    {{ __('documents.testimonial.attendance_unavailable', [], $lang) }}
                @endif
            </td>
            <td>
                @if ($t['conduct_is_clear'])
                    {{ __('documents.testimonial.conduct_clear', [], $lang) }}
                @else
                    {{ __('documents.testimonial.conduct_cases', ['count' => $t['conduct_total_cases']], $lang) }}
                @endif
            </td>
        </tr>
    </table>

    @include('documents.students._stamp')
    @include('documents.blocks.signature_block')
    @include('documents.blocks.qr_block')
@endsection

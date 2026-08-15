{{-- BROADSHEET, A3 landscape (docs/specs/10-documents.md §6.3). Students down,
     subjects across.

     Extends the shared shell so it carries the letterhead, both watermark
     layers and the page footer like every other document; only the table is
     specific to it. Type is 7pt and padding is tight because the point of A3
     here is to fit 16 subject columns at a size a human can still read - not
     to have more white space than A4. --}}
@extends('documents.layout')

@section('content')
    @include('documents.blocks.state_header')
    @include('documents.blocks.school_header')

    <div class="doc-block doc-center">
        <div style="font-size: 12pt; font-weight: bold;">
            {{ __('documents.assessment.broadsheet_title', [], $document['language']) }}
        </div>
        <div class="doc-muted">
            {{ $subject['label'] }}
            @if (! empty($payload['period_name'])) · {{ $payload['period_name'] }} @endif
        </div>
    </div>

    <table style="font-size: 7pt;">
        <thead>
            <tr>
                <th style="border: 0.5pt solid #333; padding: 2pt; text-align: left;">
                    {{ __('documents.assessment.broadsheet_student', [], $document['language']) }}
                </th>
                @foreach (($payload['subjects'] ?? []) as $subjectColumn)
                    <th style="border: 0.5pt solid #333; padding: 2pt; text-align: center;">
                        {{ $subjectColumn['short_name'] ?? ($subjectColumn['name'] ?? '') }}
                        @if (! empty($subjectColumn['coefficient']))
                            <br><span style="font-weight: normal;">×{{ $subjectColumn['coefficient'] }}</span>
                        @endif
                    </th>
                @endforeach
                <th style="border: 0.5pt solid #333; padding: 2pt; text-align: center;">
                    {{ __('documents.assessment.broadsheet_average', [], $document['language']) }}
                </th>
                <th style="border: 0.5pt solid #333; padding: 2pt; text-align: center;">
                    {{ __('documents.assessment.broadsheet_rank', [], $document['language']) }}
                </th>
            </tr>
        </thead>
        <tbody>
            @foreach (($payload['students'] ?? []) as $row)
                <tr>
                    <td style="border: 0.5pt solid #333; padding: 2pt;">{{ $row['name'] ?? '' }}</td>
                    @foreach (($payload['subjects'] ?? []) as $subjectColumn)
                        <td style="border: 0.5pt solid #333; padding: 2pt; text-align: center;">
                            {{-- An em dash, never a 0: "not marked" and "scored
                                 nothing" are different facts about a child. --}}
                            {{ $row['marks'][$subjectColumn['code'] ?? ''] ?? '—' }}
                        </td>
                    @endforeach
                    <td style="border: 0.5pt solid #333; padding: 2pt; text-align: center; font-weight: bold;">
                        {{ $row['average'] ?? '—' }}
                    </td>
                    <td style="border: 0.5pt solid #333; padding: 2pt; text-align: center;">
                        {{ $row['rank'] ?? '—' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @include('documents.blocks.signature_block')
@endsection

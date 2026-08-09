{{-- 10-documents 2.1 - the bilingual state letterhead. TEXT ONLY: no seal,
     no coat of arms, no emblem - there is deliberately nowhere in the data
     for one (2.3). Renders both columns whatever the document language,
     because the real letterhead is bilingual by definition. --}}
@if (($school['state_header'] ?? null) !== null)
    <table class="doc-block doc-small">
        <tr>
            <td style="width: 50%; text-align: center;">
                <strong>{{ __('documents.state_header.republic_fr', [], $document['language']) }}</strong><br>
                <em>{{ __('documents.state_header.motto_fr', [], $document['language']) }}</em><br>
                @if (!empty($school['state_header']['ministry_fr']))
                    {{ $school['state_header']['ministry_fr'] }}<br>
                @endif
                @if (!empty($school['state_header']['regional_delegation_fr']))
                    {{ $school['state_header']['regional_delegation_fr'] }}<br>
                @endif
                @if (!empty($school['state_header']['divisional_delegation_fr']))
                    {{ $school['state_header']['divisional_delegation_fr'] }}<br>
                @endif
            </td>
            <td style="width: 50%; text-align: center;">
                <strong>{{ __('documents.state_header.republic_en', [], $document['language']) }}</strong><br>
                <em>{{ __('documents.state_header.motto_en', [], $document['language']) }}</em><br>
                @if (!empty($school['state_header']['ministry_en']))
                    {{ $school['state_header']['ministry_en'] }}<br>
                @endif
                @if (!empty($school['state_header']['regional_delegation_en']))
                    {{ $school['state_header']['regional_delegation_en'] }}<br>
                @endif
                @if (!empty($school['state_header']['divisional_delegation_en']))
                    {{ $school['state_header']['divisional_delegation_en'] }}<br>
                @endif
            </td>
        </tr>
    </table>
@endif

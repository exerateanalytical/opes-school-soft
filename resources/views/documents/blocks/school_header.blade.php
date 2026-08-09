{{-- 10-documents 4.7 school_header: crest, school name (EN/FR), contacts and
     the FISCAL IDENTITY line (NIU, RCCM) that 03-tax-procurement makes
     mandatory on money documents. The refusal to render a money document
     with an incomplete fiscal identity is enforced in the money-document
     Actions (phase-12-13 D3); this block prints whatever identity it is
     given and prints nothing it is not. --}}
<div class="doc-block doc-center">
    @if (!empty($school['branding']['crest_path']))
        <img src="{{ $school['branding']['crest_path'] }}" alt="" style="height: 52pt;"><br>
    @endif

    @if (!empty($school['name']) || !empty($school['name_fr']))
        <div style="font-size: 13pt; font-weight: bold;">
            {{ $document['language'] === 'fr' ? ($school['name_fr'] ?: $school['name']) : ($school['name'] ?: $school['name_fr']) }}
        </div>
        @if (($school['bilingual'] ?? false) && !empty($school['name']) && !empty($school['name_fr']) && $school['name'] !== $school['name_fr'])
            <div style="font-size: 10pt;">
                {{ $document['language'] === 'fr' ? $school['name'] : $school['name_fr'] }}
            </div>
        @endif
    @endif

    @if (($school['fiscal'] ?? null) !== null)
        <div class="doc-muted">
            @if (!empty($school['fiscal']['niu']))
                {{ __('documents.school.niu', [], $document['language']) }}: {{ $school['fiscal']['niu'] }}
            @endif
            @if (!empty($school['fiscal']['rccm_number']))
                &nbsp;·&nbsp;{{ __('documents.school.rccm', [], $document['language']) }}: {{ $school['fiscal']['rccm_number'] }}
            @endif
        </div>
    @endif

    <div class="doc-rule"></div>
</div>

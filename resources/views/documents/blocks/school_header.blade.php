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

    @php
        // The contact strip. Built by filtering rather than by a chain of
        // @if/separator markup, so a school that supplies only a phone gets
        // "Tel: ..." with no stray bullet before or after it.
        $c = $school['contacts'] ?? [];

        $addressParts = array_values(array_filter([
            $c['address_line1'] ?? null,
            $c['address_line2'] ?? null,
            trim(implode(' ', array_filter([$c['city'] ?? null, $c['region'] ?? null]))) ?: null,
            !empty($c['po_box']) ? __('documents.school.po_box', [], $document['language']).' '.$c['po_box'] : null,
        ]));

        $reachParts = array_values(array_filter([
            !empty($c['phone']) ? __('documents.school.tel', [], $document['language']).' '.$c['phone'] : null,
            $c['phone_alt'] ?? null,
            $c['email'] ?? null,
            $c['website'] ?? null,
        ]));
    @endphp

    @if ($addressParts !== [])
        <div class="doc-muted">{{ implode(' · ', $addressParts) }}</div>
    @endif

    @if ($reachParts !== [])
        <div class="doc-muted">{{ implode(' · ', $reachParts) }}</div>
    @endif

    @if (!empty($c['authorisation_line']))
        <div class="doc-muted">{{ $c['authorisation_line'] }}</div>
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

{{-- The SCHOOL stamp (§2.3's school_stamp branding slot) - the only stamp
     any document in this suite may carry. Never a ministry seal (§2.2). --}}
@if (!empty($school['branding']['school_stamp_path']))
    <div class="doc-center" style="margin-top: 8pt;">
        <img src="{{ $school['branding']['school_stamp_path'] }}" alt="" style="height: 56pt;">
    </div>
@else
    <p class="doc-muted doc-small doc-center" style="margin-top: 14pt;">
        {{ __('documents.certificate.stamp', [], $document['language']) }}
    </p>
@endif

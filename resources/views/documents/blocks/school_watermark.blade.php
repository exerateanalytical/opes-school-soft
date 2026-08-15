{{-- The SCHOOL's own watermark - a SECOND, independent layer beneath the
     status watermark (DUPLICATA / ANNULE / SPECIMEN), not an alternative to
     it.

     One slot would mean the first reprint of any document silently replaces
     the school's mark with DUPLICATA - dropping the institutional mark at
     exactly the moment the copy is most likely to be scrutinised. The two
     answer different questions ("whose document is this" vs "what state is
     this copy in") and both must be able to appear.

     Like the status watermark, this is an OUTPUT overlay: the render that
     gets HASHED passes $document['school_watermark'] = false, so switching a
     watermark on never retroactively breaks an already-issued document's
     reproducibility.

     Image beats text when both are set: a school that uploaded a mark meant
     to use it, and printing both produces an unreadable overlap. --}}
@php
    $schoolWatermark = ($document['school_watermark'] ?? false) ? ($school['watermark'] ?? null) : null;
    $watermarkImage = $school['branding']['watermark_image_uri'] ?? null;
    // Already clamped to 1-30 in RenderDocument::captureSchoolChrome; divided
    // here only because CSS wants a fraction.
    $watermarkAlpha = ($schoolWatermark['opacity'] ?? 8) / 100;
@endphp

@if ($schoolWatermark !== null && (! empty($watermarkImage) || ! empty($schoolWatermark['text'])))
    <div class="doc-school-watermark">
        @if (! empty($watermarkImage))
            <img src="{{ $watermarkImage }}" alt="" style="opacity: {{ $watermarkAlpha }};">
        @else
            <span style="color: rgba(120, 120, 120, {{ $watermarkAlpha }});">{{ $schoolWatermark['text'] }}</span>
        @endif
    </div>
@endif

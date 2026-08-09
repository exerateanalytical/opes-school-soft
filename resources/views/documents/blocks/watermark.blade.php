{{-- 10-documents 4.7 watermark block: DUPLICATA on every reprint (4.5),
     ANNULE/VOID on renders of a revoked document, SPECIMEN for previews and
     demo licences. ~12% opacity, diagonal, behind the content. --}}
@if (($document['watermark'] ?? null) !== null)
    <div class="doc-watermark">
        {{ __('documents.watermark.'.$document['watermark'], [], $document['language']) }}
    </div>
@endif

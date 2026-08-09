{{-- 10-documents 4.7 document_footer, every page: series number, issue date,
     template code+version - and, on LIVE documents only, the
     "Generated on ... by ..." line that stops a working view being mistaken
     for evidence (4.2). Page n of m is drawn by the PDF engine, which is
     the only party that knows m. --}}
<div class="doc-footer doc-muted doc-center">
    <div>
        @if (($document['serial'] ?? null) !== null)
            {{ __('documents.footer.series', [], $document['language']) }}: {{ $document['serial'] }}
            &nbsp;·&nbsp;
        @endif
        @if (($document['issued_at'] ?? null) !== null)
            {{ __('documents.footer.issued_on', [], $document['language']) }}: {{ $document['issued_at'] }}
            &nbsp;·&nbsp;
        @endif
        {{ $document['template_code'] }} v{{ $document['template_version'] }}
    </div>
    @if (($document['generated_at'] ?? null) !== null)
        <div>
            {{ __('documents.footer.generated_on', [
                'datetime' => $document['generated_at'],
                'user' => $document['generated_by'] ?? '',
            ], $document['language']) }}
        </div>
    @endif
    @if (($document['is_duplicate'] ?? false) === true)
        <div>
            {{ __('documents.footer.duplicate_note', [
                'copy' => $document['copy_no'],
                'date' => $document['printed_on'] ?? '',
                'user' => $document['printed_by_name'] ?? '',
            ], $document['language']) }}
        </div>
    @endif
</div>

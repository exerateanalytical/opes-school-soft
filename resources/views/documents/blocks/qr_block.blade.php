{{-- 10-documents 4.7 / 17 qr_block: the signed verification token and the
     scan prompt. NEVER encodes a student datum - the token names only the
     instance, template, serial, hash prefix and date. The vector QR itself
     arrives with the D2 signing stack; until a token exists the block
     renders nothing, because an unverifiable decoration would teach people
     to trust decorations. --}}
@if (($document['qr_token'] ?? null) !== null)
    <div class="doc-block doc-small">
        <div style="font-family: 'DejaVu Sans Mono', monospace; font-size: 5.5pt; word-break: break-all;">
            {{ $document['qr_token'] }}
        </div>
        <div class="doc-muted">{{ __('documents.qr.scan', [], $document['language']) }}</div>
    </div>
@endif

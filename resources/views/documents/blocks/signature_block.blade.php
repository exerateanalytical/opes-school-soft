{{-- 10-documents 4.7 signature_block: the template's ORDERED signature roles,
     each with a bilingual label, ruled line and date. The roles were
     validated against the 2.3 allow-list when the template was saved
     (DocumentTemplate::booted) - by the time this block renders, a forbidden
     role cannot exist in $document['signature_roles'].

     SIGNATURE IMAGES. A scanned signature is printed ABOVE the ruled line,
     for the roles the template actually carries and ONLY those: printing the
     principal's signature on a document he is not a signatory to is a
     forgery, not a convenience. A role with no stored image still gets its
     line and label, so the document remains signable by hand.

     The images arrive as base64 data URIs; dompdf cannot fetch a URL. --}}
@if (($document['signature_roles'] ?? []) !== [])
    @php
        // role => the branding key holding that role's scanned signature.
        // Roles absent from this map sign by hand; that is the default and
        // needs no entry.
        $signatureUris = [
            'principal' => $school['branding']['principal_signature_uri'] ?? null,
            'registrar' => $school['branding']['registrar_signature_uri'] ?? null,
        ];

        $stampUri = $school['branding']['school_stamp_uri'] ?? null;
    @endphp

    <table class="doc-block doc-signatures">
        <tr>
            @foreach ($document['signature_roles'] as $role)
                <td>
                    @if (!empty($signatureUris[$role]))
                        {{-- Fixed height, auto width, and a NEGATIVE bottom
                             margin: the rule's 26pt top margin would
                             otherwise push the two apart into what reads as
                             two unrelated marks. --}}
                        <img src="{{ $signatureUris[$role] }}" alt="" class="doc-signature-image">
                    @endif
                    <div class="doc-signature-line">
                        <strong>{{ __('documents.signature_roles.'.$role, [], $document['language']) }}</strong><br>
                        <span class="doc-muted">{{ __('documents.signature.date_line', [], $document['language']) }}</span>
                    </div>
                </td>
            @endforeach

            @if (!empty($stampUri))
                {{-- The school stamp is not a signature and does not get a
                     ruled line: it sits beside the signatories, as it does on
                     paper. --}}
                <td class="doc-stamp-cell">
                    <img src="{{ $stampUri }}" alt="" class="doc-stamp-image">
                </td>
            @endif
        </tr>
    </table>
@endif

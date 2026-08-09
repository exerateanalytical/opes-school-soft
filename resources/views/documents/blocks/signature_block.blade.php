{{-- 10-documents 4.7 signature_block: the template's ORDERED signature roles,
     each with a bilingual label, ruled line and date. The roles were
     validated against the 2.3 allow-list when the template was saved
     (DocumentTemplate::booted) - by the time this block renders, a
     forbidden role cannot exist in $document['signature_roles']. --}}
@if (($document['signature_roles'] ?? []) !== [])
    <table class="doc-block doc-signatures">
        <tr>
            @foreach ($document['signature_roles'] as $role)
                <td>
                    <div class="doc-signature-line">
                        <strong>{{ __('documents.signature_roles.'.$role, [], $document['language']) }}</strong><br>
                        <span class="doc-muted">{{ __('documents.signature.date_line', [], $document['language']) }}</span>
                    </div>
                </td>
            @endforeach
        </tr>
    </table>
@endif

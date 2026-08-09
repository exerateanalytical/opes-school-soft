{{-- 10-documents 4.7 subject_identity: photo, name, matricule, class group,
     section, academic year - for student documents. Renders only the facts
     it is handed (a missing photo is an absent cell, never a broken image). --}}
<table class="doc-block">
    <tr>
        @if (!empty($identity['photo_path'] ?? null))
            <td style="width: 64pt; vertical-align: top;">
                <img src="{{ $identity['photo_path'] }}" alt="" style="width: 56pt;">
            </td>
        @endif
        <td style="vertical-align: top;">
            <table class="doc-small">
                @if (!empty($identity['name'] ?? null))
                    <tr>
                        <td><strong>{{ __('documents.subject.name', [], $document['language']) }}</strong></td>
                        <td>{{ $identity['name'] }}</td>
                    </tr>
                @endif
                @if (!empty($identity['matricule'] ?? null))
                    <tr>
                        <td><strong>{{ __('documents.subject.matricule', [], $document['language']) }}</strong></td>
                        <td>{{ $identity['matricule'] }}</td>
                    </tr>
                @endif
                @if (!empty($identity['class_group'] ?? null))
                    <tr>
                        <td><strong>{{ __('documents.subject.class_group', [], $document['language']) }}</strong></td>
                        <td>{{ $identity['class_group'] }}</td>
                    </tr>
                @endif
                @if (!empty($identity['section'] ?? null))
                    <tr>
                        <td><strong>{{ __('documents.subject.section', [], $document['language']) }}</strong></td>
                        <td>{{ $identity['section'] }}</td>
                    </tr>
                @endif
                @if (!empty($identity['academic_year'] ?? null))
                    <tr>
                        <td><strong>{{ __('documents.subject.academic_year', [], $document['language']) }}</strong></td>
                        <td>{{ $identity['academic_year'] }}</td>
                    </tr>
                @endif
                @if (!empty($identity['date_of_birth'] ?? null))
                    <tr>
                        <td><strong>{{ __('documents.subject.date_of_birth', [], $document['language']) }}</strong></td>
                        <td>{{ $identity['date_of_birth'] }}</td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

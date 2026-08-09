<?php

declare(strict_types=1);

// docs/specs/10-documents.md 17.2 - the in-app document verification screen.
return [
    'title' => 'Verify a Document',
    'subtitle' => 'Paste or scan the QR token printed on the document. The signature is checked against this school\'s own keys — no internet involved.',
    'token_label' => 'Verification token',
    'token_placeholder' => 'OPES1.…',
    'token_required' => 'Paste or scan a verification token first.',
    'check' => 'Verify',
    'status_valid' => 'VALID',
    'status_revoked' => 'REVOKED',
    'status_superseded' => 'SUPERSEDED',
    'status_not_found' => 'NOT FOUND',
    'not_found_help' => 'This token does not match any document issued by this school. Check that the whole token was scanned or pasted.',
    'superseded_help' => 'This document was reissued. The current document carries serial :serial.',
    'revoked_help' => 'This document has been revoked by the school and is no longer valid.',
    'detail_serial' => 'Serial',
    'detail_template' => 'Document',
    'detail_issued_on' => 'Issued on',
    'detail_issuer' => 'Issued by',
    'detail_superseded_by' => 'Superseded by',
    'empty_hint' => 'Awaiting a token.',
];

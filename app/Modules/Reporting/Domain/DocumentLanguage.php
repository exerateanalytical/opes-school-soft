<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * docs/specs/10-documents.md 4.6 - the two rendering languages. A document's
 * language is the SCHOOL'S (or the SchoolSection's), never the operator's UI
 * locale; resolution order is explicit request -> SchoolSection.document_language
 * -> SchoolProfile.default_document_language, implemented in
 * Reporting\Actions\RenderDocument so there is exactly one resolver.
 */
enum DocumentLanguage: string
{
    case En = 'en';
    case Fr = 'fr';
}

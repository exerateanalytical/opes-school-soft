<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * docs/specs/10-documents.md 4.1 - mirrors `document_templates.orientation`.
 */
enum Orientation: string
{
    case Portrait = 'portrait';
    case Landscape = 'landscape';
}

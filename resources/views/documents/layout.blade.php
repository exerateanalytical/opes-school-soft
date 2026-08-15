{{-- docs/specs/10-documents.md 4.7 - the shared document shell every template extends.
     All human-readable strings come from lang/{en,fr}/documents.php in the DOCUMENT
     language ($document['language']), never the operator's UI locale (4.6). --}}
<!DOCTYPE html>
<html lang="{{ $document['language'] }}">
<head>
    <meta charset="utf-8">
    <title>{{ $document['template_code'] }}</title>
    <style>
        @page { margin: 28pt 32pt 48pt 32pt; }
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9.5pt;
            color: #111;
            margin: 0;
        }
        table { border-collapse: collapse; width: 100%; }
        .doc-block { margin-bottom: 10pt; }
        .doc-rule { border-bottom: 0.7pt solid #333; margin: 6pt 0; }
        .doc-muted { color: #555; font-size: 8pt; }
        .doc-center { text-align: center; }
        .doc-small { font-size: 8pt; }
        .doc-watermark {
            position: fixed;
            top: 38%;
            left: 0;
            width: 100%;
            text-align: center;
            font-size: 64pt;
            font-weight: bold;
            letter-spacing: 8pt;
            color: rgba(120, 120, 120, 0.12);
            transform: rotate(-24deg);
            z-index: 0;
        }
        .doc-footer {
            position: fixed;
            bottom: -34pt;
            left: 0;
            width: 100%;
        }
        .doc-signatures td {
            vertical-align: bottom;
            padding: 14pt 8pt 0 8pt;
            text-align: center;
        }
        .doc-signature-line { border-top: 0.7pt solid #333; margin-top: 26pt; padding-top: 2pt; }

        /* A scanned signature sits ON the rule below it, so its own bottom
           margin is negative: the rule's 26pt top margin would otherwise
           push the two apart into what reads as two unrelated marks. Height
           is fixed and width auto - a scanned signature is any aspect ratio
           at all, and a fixed box squashes half of them. */
        .doc-signature-image { height: 34pt; width: auto; max-width: 150pt; display: block; margin: 0 auto -26pt auto; }
        .doc-stamp-cell { vertical-align: middle; width: 90pt; }
        .doc-stamp-image { height: 70pt; width: auto; max-width: 90pt; opacity: 0.9; }

        /* ── Screen preview ────────────────────────────────────────────────
           The PDF engine paginates against @page above and ignores all of
           this. A BROWSER does not: without it the same markup renders as
           text running edge-to-edge on a white window, which reads as a
           broken page rather than a document. Here the sheet is given its
           real A4 width, centred on a neutral background with a soft
           shadow, so what an operator previews on screen is recognisably
           the sheet that will come out of the printer.

           `position: fixed` on the watermark and footer is right for print
           (once per page) but wrong on screen, where "fixed" pins them to
           the VIEWPORT and they float over the page as it scrolls. On
           screen they become absolute, positioned against the sheet. */
        @media screen {
            body {
                background: #eceeed;
                padding: 24px 0;
            }
            .doc-sheet {
                position: relative;
                width: 210mm;
                min-height: 297mm;
                box-sizing: border-box;
                margin: 0 auto;
                padding: 28pt 32pt 48pt 32pt;
                background: #fff;
                box-shadow: 0 2px 8px rgba(1, 60, 31, .10), 0 16px 40px rgba(1, 60, 31, .10);
            }
            .doc-watermark { position: absolute; }
            .doc-footer {
                position: absolute;
                bottom: 14pt;
                left: 32pt;
                right: 32pt;
                width: auto;
            }
        }

        /* ── Print ─────────────────────────────────────────────────────────
           Printing from the browser must produce the same sheet as the PDF
           engine: no preview chrome, no shadow, and none of the sheet's
           own padding (the @page margin already provides it, and applying
           both would double the margin and push content off the page). */
        @media print {
            body { background: #fff; padding: 0; }
            .doc-sheet {
                width: auto;
                min-height: 0;
                margin: 0;
                padding: 0;
                background: none;
                box-shadow: none;
            }
            /* Keep a table row, a signature block or a totals band from
               being split across a page break mid-row. */
            tr, .doc-signatures, .doc-no-break { page-break-inside: avoid; }
            thead { display: table-header-group; }
            tfoot { display: table-footer-group; }
        }
    </style>
</head>
<body>
{{-- The sheet wrapper is what gives the screen preview a real page to be
     drawn on; in print and in the PDF engine it collapses to nothing (see
     the @media print block) so pagination is unchanged. --}}
<div class="doc-sheet">
    @include('documents.blocks.watermark')
    @include('documents.blocks.document_footer')

    <div style="position: relative; z-index: 1;">
        @yield('content')
    </div>
</div>
</body>
</html>

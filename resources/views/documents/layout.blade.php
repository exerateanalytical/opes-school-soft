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
    </style>
</head>
<body>
    @include('documents.blocks.watermark')
    @include('documents.blocks.document_footer')

    <div style="position: relative; z-index: 1;">
        @yield('content')
    </div>
</body>
</html>

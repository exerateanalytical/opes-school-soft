{{-- ASSET-LABEL, CR80 landscape (85.60 x 53.98 mm). NOT an extension of
     documents.layout: that shell carries a 28pt page margin, a fixed footer
     and two watermark layers, all of which are correct for a certificate and
     ruinous on a 54 mm sticker. A label is its own shell.

     Everything is in points at 72pt/in against the CR80 box PaperSize
     defines (242.65 x 153.01 pt), because 12.1 requires EXACT physical
     sizing - a label printed 3% small no longer lines up with a die-cut
     sheet.

     The second meta line carries the SERIAL NUMBER, not a location:
     assets.location_id is polymorphic across rooms and store_locations with
     no discriminator column, so any join that resolved it to a name would be
     a guess printed on a sticker. --}}
<!DOCTYPE html>
<html lang="{{ $document['language'] }}">
<head>
    <meta charset="utf-8">
    <title>{{ $document['template_code'] }}</title>
    <style>
        @page { margin: 0; }
        body {
            font-family: "DejaVu Sans", sans-serif;
            color: #000;
            margin: 0;
            padding: 6pt 8pt;
            font-size: 7pt;
        }
        .lbl-school { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3pt; }
        .lbl-crest { height: 16pt; width: auto; float: right; }
        .lbl-name { font-size: 9pt; font-weight: bold; margin-top: 2pt; }
        .lbl-meta { font-size: 6.5pt; color: #333; }
        .lbl-barcode { margin-top: 3pt; text-align: center; }
        .lbl-barcode img { height: 30pt; width: auto; max-width: 210pt; }
        .lbl-tag { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5pt; font-weight: bold; letter-spacing: 1pt; text-align: center; }
    </style>
</head>
<body>
    @if (! empty($school['branding']['crest_uri']))
        <img src="{{ $school['branding']['crest_uri'] }}" alt="" class="lbl-crest">
    @endif

    <div class="lbl-school">{{ $school['name'] ?: $school['name_fr'] }}</div>
    <div class="lbl-name">{{ $payload['name'] }}</div>
    <div class="lbl-meta">
        {{ $payload['category'] }}@if (! empty($payload['serial_number'])) · {{ __('documents.assets.serial', [], $document['language']) }} {{ $payload['serial_number'] }}@endif
    </div>

    {{-- A tag that cannot carry a Code 39 barcode that scans back as ITSELF
         prints as text alone. A barcode that reads as a different asset is
         worse than none, because a stock-take believes the scanner. --}}
    @if (! empty($payload['barcode_uri']))
        <div class="lbl-barcode"><img src="{{ $payload['barcode_uri'] }}" alt=""></div>
    @else
        <div class="lbl-meta" style="margin-top: 4pt; text-align: center;">
            {{ __('documents.assets.no_barcode', [], $document['language']) }}
        </div>
    @endif

    <div class="lbl-tag">{{ $payload['tag_number'] }}</div>
</body>
</html>

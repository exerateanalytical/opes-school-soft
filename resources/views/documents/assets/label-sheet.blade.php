{{-- ASSET-LABEL-SHEET, A4 portrait: the stock-take variant. Two columns of
     labels down the page, so a school with an ordinary office printer and a
     sheet of blank labels can do a whole store room in one pass.

     Table layout, not flexbox or grid: dompdf's CSS support is a subset and
     its float/flex handling across a page break is unreliable, while its
     table pagination is solid and `page-break-inside: avoid` on the row keeps
     a label from being sliced in half by the page edge. --}}
<!DOCTYPE html>
<html lang="{{ $document['language'] }}">
<head>
    <meta charset="utf-8">
    <title>{{ $document['template_code'] }}</title>
    <style>
        @page { margin: 12mm 8mm; }
        body { font-family: "DejaVu Sans", sans-serif; color: #000; margin: 0; font-size: 7pt; }
        table { border-collapse: collapse; width: 100%; }
        tr { page-break-inside: avoid; }
        td.lbl {
            width: 50%;
            height: 96pt;
            border: 0.5pt dashed #999;   /* the cut line */
            padding: 6pt 8pt;
            vertical-align: top;
        }
        .lbl-school { font-size: 7.5pt; font-weight: bold; text-transform: uppercase; }
        .lbl-name { font-size: 9pt; font-weight: bold; margin-top: 2pt; }
        .lbl-meta { font-size: 6.5pt; color: #333; }
        .lbl-barcode { margin-top: 3pt; text-align: center; }
        .lbl-barcode img { height: 26pt; width: auto; max-width: 190pt; }
        .lbl-tag { font-family: "DejaVu Sans Mono", monospace; font-size: 8pt; font-weight: bold; letter-spacing: 1pt; text-align: center; }
        .sheet-head { margin-bottom: 6pt; font-size: 8pt; }
    </style>
</head>
<body>
    <div class="sheet-head">
        <strong>{{ $school['name'] ?: $school['name_fr'] }}</strong> ·
        {{ __('documents.assets.label_sheet_title', [], $document['language']) }} ·
        {{ __('documents.assets.label_sheet_count', ['count' => count($payload['labels'])], $document['language']) }}
        @if (! empty($document['generated_at']))
            · {{ $document['generated_at'] }}
        @endif
    </div>

    <table>
        @foreach (array_chunk($payload['labels'], 2) as $row)
            <tr>
                @foreach ($row as $label)
                    <td class="lbl">
                        <div class="lbl-school">{{ $school['name'] ?: $school['name_fr'] }}</div>
                        <div class="lbl-name">{{ $label['name'] }}</div>
                        <div class="lbl-meta">
                            {{ $label['category'] }}@if (! empty($label['serial_number'])) · {{ __('documents.assets.serial', [], $document['language']) }} {{ $label['serial_number'] }}@endif
                        </div>
                        @if (! empty($label['barcode_uri']))
                            <div class="lbl-barcode"><img src="{{ $label['barcode_uri'] }}" alt=""></div>
                        @else
                            <div class="lbl-meta" style="margin-top: 4pt; text-align: center;">
                                {{ __('documents.assets.no_barcode', [], $document['language']) }}
                            </div>
                        @endif
                        <div class="lbl-tag">{{ $label['tag_number'] }}</div>
                    </td>
                @endforeach

                {{-- An odd final row gets an empty cell rather than a
                     half-width one, so the last real label keeps the same
                     dimensions as every other. --}}
                @if (count($row) === 1)
                    <td class="lbl" style="border-color: transparent;"></td>
                @endif
            </tr>
        @endforeach
    </table>
</body>
</html>

<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared XLSX export helper for every report screen (docs: Reports module,
 * 2026-08 build). One entry point so every report's spreadsheet looks and
 * behaves the same: bold header row, frozen header, auto width, a title
 * band above the header.
 *
 * Usage from a Livewire action:
 *   return ExcelExport::download('Trial Balance', ['Account', 'Debit', 'Credit'], $rows, 'trial-balance.xlsx');
 * where $rows is an iterable of arrays/lists matching the header count.
 */
final class ExcelExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<mixed>>  $rows
     */
    public static function download(string $title, array $headers, iterable $rows, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr(preg_replace('/[^A-Za-z0-9 _-]/', '', $title) ?: 'Report', 0, 31));

        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells([1, 1, count($headers), 1]);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->setCellValue('A2', 'Generated '.now()->format('Y-m-d H:i'));
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9);

        $headerRow = 4;

        foreach ($headers as $col => $header) {
            $sheet->setCellValue([$col + 1, $headerRow], $header);
        }

        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A'.$headerRow.':'.$lastCol.$headerRow)->getFont()->setBold(true);
        $sheet->getStyle('A'.$headerRow.':'.$lastCol.$headerRow)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F5132');
        $sheet->getStyle('A'.$headerRow.':'.$lastCol.$headerRow)->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->freezePane('A'.($headerRow + 1));

        $rowNum = $headerRow + 1;

        foreach ($rows as $row) {
            foreach (array_values($row) as $col => $value) {
                $sheet->setCellValue([$col + 1, $rowNum], $value);
            }
            $rowNum++;
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return new StreamedResponse(function () use ($writer): void {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}

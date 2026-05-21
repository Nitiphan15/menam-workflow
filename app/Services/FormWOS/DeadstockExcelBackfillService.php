<?php

namespace App\Services\FormWOS;

use App\Support\Spreadsheet\ChunkReadFilter;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

class DeadstockExcelBackfillService
{
    public function buildPayload(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Deadstock Excel file not found: ' . $file);
        }

        $spreadsheet = IOFactory::load($file);
        $sheet = $this->detailsSheet($spreadsheet);
        if (!$sheet) {
            throw new \RuntimeException('No supported Deadstock details sheet found: ' . $file);
        }

        $snapshotDate = $this->snapshotDateFromFilename($file);
        $headers = $this->headers($sheet);
        $items = [];

        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $values = [];
            foreach ($headers as $column => $header) {
                $values[$header] = trim((string) $sheet->getCell($column . $row)->getFormattedValue());
            }

            if ($this->rowIsBlank($values)) {
                continue;
            }

            $items[] = [
                'company' => $this->pick($values, ['company']),
                'part_id' => null,
                'partnumber' => $this->pick($values, ['partnumber']),
                'part_description' => $this->pick($values, ['part_description']),
                'transaction_number' => $this->pick($values, ['transaction_number']),
                'serialnumber' => $this->pick($values, ['serialnumber']),
                'purchase_date' => $this->dateValue($this->pick($values, ['purchasedate'])),
                'status_time' => null,
                'quantity' => $this->numberValue($this->pick($values, ['quantity'])),
                'unit' => $this->pick($values, ['unit']),
                'unitcost' => $this->numberValue($this->pick($values, ['unitcost'])),
                'part_type_description' => $this->pick($values, ['part_type_description']),
                'customer_id' => null,
                'customer' => $this->pick($values, ['customer']),
                'due_date' => $this->dateValue($this->pick($values, ['due_date'])),
                'salesperson_id' => null,
                'salesperson' => $this->pick($values, ['salesperson']),
                'deadstock_code' => $this->pick($values, ['deadstock_code']),
                'deadstock_desc' => $this->pick($values, ['deadstock_desc']),
                'days_diff' => $this->integerValue($this->pick($values, ['days_diff', 'อายุ stock (วัน)'])),
                'days_overdue' => $this->integerValue($this->pick($values, ['days_overdue', 'เกินกำหนด (วัน)'])),
                'dead_stock_flag' => 1,
            ];
        }

        return [
            'recv_date' => $snapshotDate,
            'as_of' => $snapshotDate,
            'generated' => now('Asia/Bangkok')->toDateTimeString(),
            'items' => $items,
        ];
    }

    public function chunkedBackfill(string $file, int $chunkSize = 500): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Deadstock Excel file not found: ' . $file);
        }

        $snapshotDate = $this->snapshotDateFromFilename($file);
        [$sheetTitle, $headers, $highestRow] = $this->sheetMeta($file);

        $chunks = function () use ($file, $sheetTitle, $headers, $highestRow, $chunkSize) {
            for ($start = 2; $start <= $highestRow; $start += $chunkSize) {
                $end = min($highestRow, $start + $chunkSize - 1);
                $reader = IOFactory::createReaderForFile($file);
                $reader->setReadDataOnly(true);
                $reader->setLoadSheetsOnly([$sheetTitle]);
                $reader->setReadFilter(new ChunkReadFilter($start, $end));
                $spreadsheet = $reader->load($file);
                $sheet = $spreadsheet->getSheetByName($sheetTitle);
                if (!$sheet) {
                    continue;
                }

                $buffer = [];
                for ($row = $start; $row <= $end; $row++) {
                $values = [];
                foreach ($headers as $column => $header) {
                    $values[$header] = trim((string) $sheet->getCell($column . $row)->getFormattedValue());
                }

                if ($this->rowIsBlank($values)) {
                    continue;
                }

                $buffer[] = [
                    'company' => $this->pick($values, ['company']),
                    'part_id' => null,
                    'partnumber' => $this->pick($values, ['partnumber']),
                    'part_description' => $this->pick($values, ['part_description']),
                    'transaction_number' => $this->pick($values, ['transaction_number']),
                    'serialnumber' => $this->pick($values, ['serialnumber']),
                    'purchase_date' => $this->dateValue($this->pick($values, ['purchasedate'])),
                    'status_time' => null,
                    'quantity' => $this->numberValue($this->pick($values, ['quantity'])),
                    'unit' => $this->pick($values, ['unit']),
                    'unitcost' => $this->numberValue($this->pick($values, ['unitcost'])),
                    'part_type_description' => $this->pick($values, ['part_type_description']),
                    'customer_id' => null,
                    'customer' => $this->pick($values, ['customer']),
                    'due_date' => $this->dateValue($this->pick($values, ['due_date'])),
                    'salesperson_id' => null,
                    'salesperson' => $this->pick($values, ['salesperson']),
                    'deadstock_code' => $this->pick($values, ['deadstock_code']),
                    'deadstock_desc' => $this->pick($values, ['deadstock_desc']),
                    'days_diff' => $this->integerValue($this->pick($values, ['days_diff', 'เธญเธฒเธขเธธ stock (เธงเธฑเธ)'])),
                    'days_overdue' => $this->integerValue($this->pick($values, ['days_overdue', 'เน€เธเธดเธเธเธณเธซเธเธ” (เธงเธฑเธ)'])),
                    'dead_stock_flag' => 1,
                ];

                }

                if ($buffer !== []) {
                    yield $buffer;
                }

                $spreadsheet->disconnectWorksheets();
                unset($sheet, $spreadsheet, $reader, $buffer);
            }
        };

        return [
            'recv_date' => $snapshotDate,
            'as_of' => $snapshotDate,
            'generated' => now('Asia/Bangkok'),
            'chunks' => $chunks(),
        ];
    }

    private function sheetMeta(string $file): array
    {
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file);
        $sheet = $this->detailsSheet($spreadsheet);
        if (!$sheet) {
            throw new \RuntimeException('No supported Deadstock details sheet found: ' . $file);
        }

        $meta = [$sheet->getTitle(), $this->headers($sheet), $sheet->getHighestDataRow()];
        $spreadsheet->disconnectWorksheets();

        return $meta;
    }

    private function headers($sheet): array
    {
        $headers = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $value = mb_strtolower(trim((string) $sheet->getCell($column . '1')->getFormattedValue()));
            if ($value !== '') {
                $headers[$column] = $value;
            }
        }

        return $headers;
    }

    private function detailsSheet($spreadsheet)
    {
        foreach (['Details (All)', 'Details'] as $title) {
            $sheet = $spreadsheet->getSheetByName($title);
            if ($sheet) {
                return $sheet;
            }
        }

        return null;
    }

    private function snapshotDateFromFilename(string $file): string
    {
        if (!preg_match('/deadstock_(\d{4}-\d{2}-\d{2})\.xlsx$/i', basename($file), $matches)) {
            throw new \RuntimeException('Cannot resolve snapshot date from filename: ' . basename($file));
        }

        return Carbon::parse($matches[1])->toDateString();
    }

    private function rowIsBlank(array $values): bool
    {
        foreach (['transaction_number', 'serialnumber', 'partnumber'] as $key) {
            if (($values[$key] ?? '') !== '') {
                return false;
            }
        }

        return true;
    }

    private function pick(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $key = mb_strtolower($key);
            $value = trim((string) ($values[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function numberValue(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) str_replace(',', '', $value);
    }

    private function integerValue(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) preg_replace('/[^\d-]+/', '', $value);
    }

    private function dateValue(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }
}

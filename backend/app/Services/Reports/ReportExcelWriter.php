<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class ReportExcelWriter
{
    /**
     * @param  array<int, array{key: string, label: string}>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function write(string $relativePath, array $columns, array $rows): int
    {
        Storage::disk('local')->makeDirectory('reports');

        $fullPath = Storage::disk('local')->path($relativePath);
        $writer = new Writer();
        $writer->openToFile($fullPath);

        $writer->addRow(Row::fromValues(array_map(fn ($col) => $col['label'], $columns)));

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value = $row[$column['key']] ?? '';
                $line[] = $value === null ? '' : (is_scalar($value) ? $value : json_encode($value));
            }
            $writer->addRow(Row::fromValues($line));
        }

        $writer->close();

        return count($rows);
    }

    /**
     * @param  array<int, array{key: string, label: string}>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function render(array $columns, array $rows): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'report-xlsx-');
        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create temporary export file.');
        }

        try {
            $writer = new Writer();
            $writer->openToFile($tempPath);
            $writer->addRow(Row::fromValues(array_map(fn ($col) => $col['label'], $columns)));

            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $column) {
                    $value = $row[$column['key']] ?? '';
                    $line[] = $value === null ? '' : (is_scalar($value) ? $value : json_encode($value));
                }
                $writer->addRow(Row::fromValues($line));
            }

            $writer->close();

            return (string) file_get_contents($tempPath);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}

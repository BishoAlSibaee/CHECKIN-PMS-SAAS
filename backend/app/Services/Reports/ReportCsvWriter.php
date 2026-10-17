<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Storage;

class ReportCsvWriter
{
    /**
     * @param  array<int, array{key: string, label: string}>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function write(string $relativePath, array $columns, array $rows, string $delimiter = ','): int
    {
        Storage::disk('local')->makeDirectory('reports');

        $fullPath = Storage::disk('local')->path($relativePath);
        $lines = ['sep='.$delimiter];
        $lines[] = $this->csvLine(array_map(fn ($col) => $col['label'], $columns), $delimiter);

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = $this->formatCell($row[$column['key']] ?? '');
            }
            $lines[] = $this->csvLine($line, $delimiter);
        }

        $content = implode("\r\n", $lines);
        $bytes = "\xFF\xFE".mb_convert_encoding($content, 'UTF-16LE', 'UTF-8');

        if (file_put_contents($fullPath, $bytes) === false) {
            throw new \RuntimeException('Unable to create report export file.');
        }

        return count($rows);
    }

    /**
     * @param  array<int, scalar|null>  $fields
     */
    private function csvLine(array $fields, string $delimiter): string
    {
        return implode($delimiter, array_map(fn ($value) => $this->escapeCsvField((string) $value, $delimiter), $fields));
    }

    private function escapeCsvField(string $value, string $delimiter): string
    {
        $mustQuote = str_contains($value, $delimiter)
            || str_contains($value, '"')
            || str_contains($value, "\n")
            || str_contains($value, "\r")
            || preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value)
            || preg_match('/[^\x00-\x7F]/', $value);

        if ($mustQuote) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    private function formatCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_scalar($value)) {
            return json_encode($value) ?: '';
        }

        $string = trim((string) $value);
        if ($string === '') {
            return '';
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $string, $matches)) {
            return $matches[1].' '.$matches[2];
        }

        return $string;
    }
}

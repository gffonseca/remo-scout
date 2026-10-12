<?php

namespace App\Services\Import;

use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/** Lê CSV/XLSX: primeira linha = cabeçalho. Detecta separador ; ou , no CSV. */
class SpreadsheetReader
{
    /** @return array{headers: list<string>, rows: list<array<string, mixed>>} */
    public static function read(string $path, string $originalName, int $maxRows): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            $reader = new XlsxReader;
        } elseif (in_array($ext, ['csv', 'txt'], true)) {
            $opts = new CsvOptions;
            $opts->FIELD_DELIMITER = static::detectDelimiter($path);
            $reader = new CsvReader($opts);
        } else {
            throw new \InvalidArgumentException('Formato não suportado. Use .xlsx ou .csv.');
        }

        $reader->open($path);
        $headers = [];
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $i => $row) {
                    $values = array_map(fn ($c) => $c->getValue(), $row->getCells());
                    if ($headers === []) {
                        $headers = static::normalizeHeaders($values);

                        continue;
                    }
                    if (count(array_filter($values, fn ($v) => $v !== null && $v !== '')) === 0) {
                        continue;
                    }
                    if (count($rows) >= $maxRows) {
                        throw new \RuntimeException("Arquivo excede o limite de {$maxRows} linhas por lote.");
                    }
                    $assoc = [];
                    foreach ($headers as $idx => $h) {
                        $assoc[$h] = $values[$idx] ?? null;
                    }
                    $rows[] = $assoc;
                }
                break; // somente a primeira aba
            }
        } finally {
            $reader->close();
        }
        if ($headers === []) {
            throw new \RuntimeException('Planilha vazia ou sem cabeçalho.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** @return list<string> */
    private static function normalizeHeaders(array $values): array
    {
        $out = [];
        foreach ($values as $i => $v) {
            $h = trim((string) $v);
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
            $h = $h === '' ? 'Coluna '.($i + 1) : $h;
            $base = $h;
            $n = 2;
            while (in_array($h, $out, true)) {
                $h = $base.' ('.$n++.')';
            }
            $out[] = $h;
        }

        return $out;
    }

    private static function detectDelimiter(string $path): string
    {
        $line = (string) fgets(fopen($path, 'r'));

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }
}

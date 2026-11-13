<?php

namespace App\Domain\Imports;

use DateInterval;
use DateTimeInterface;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

class SpreadsheetPreviewReader
{
    public const MAX_ROWS = 5000;

    public const MAX_COLUMNS = 100;

    /**
     * @return array{sheetName: string, headers: list<string>, rows: list<list<string>>, totalRows: int, malformedRows: list<int>}
     */
    public function preview(string $path, string $format, int $sampleLimit = 10): array
    {
        $reader = match (strtolower($format)) {
            'csv' => $this->csvReader($path),
            'xlsx' => new XlsxReader,
            default => throw new ImportFileException('Este formato de arquivo não é aceito.'),
        };

        $opened = false;

        try {
            $reader->open($path);
            $opened = true;

            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = null;
                $rows = [];
                $malformedRows = [];
                $totalRows = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $values = [];
                    foreach ($row->getCells() as $cell) {
                        $values[] = $this->cellToString($cell->getValue());
                    }

                    if ($headers === null) {
                        if ($this->isBlankRow($values)) {
                            continue;
                        }

                        $headers = $this->validatedHeaders($values);

                        continue;
                    }

                    if ($this->isBlankRow($values)) {
                        continue;
                    }

                    if (count($values) > count($headers)) {
                        $extraValues = array_slice($values, count($headers));
                        if (count(array_filter($extraValues, static fn (string $value): bool => trim($value) !== '')) > 0) {
                            $malformedRows[] = $totalRows + 2;
                        }
                        $values = array_slice($values, 0, count($headers));
                    }

                    $values = array_pad($values, count($headers), '');
                    $totalRows++;

                    if ($totalRows > self::MAX_ROWS) {
                        throw new ImportFileException('O arquivo ultrapassa o limite de 5.000 linhas. Divida a planilha em arquivos menores.');
                    }

                    if (count($rows) < max(0, $sampleLimit)) {
                        $rows[] = $values;
                    }
                }

                if ($headers === null) {
                    throw new ImportFileException('A primeira planilha está vazia ou não possui cabeçalho.');
                }

                return [
                    'sheetName' => $sheet->getName(),
                    'headers' => $headers,
                    'rows' => $rows,
                    'totalRows' => $totalRows,
                    'malformedRows' => $malformedRows,
                ];
            }

            throw new ImportFileException('O arquivo não contém uma planilha para pré-visualizar.');
        } catch (ImportFileException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ImportFileException('Não foi possível ler o arquivo. Verifique se ele é um CSV ou XLSX válido.', previous: $exception);
        } finally {
            if ($opened) {
                $reader->close();
            }
        }
    }

    private function csvReader(string $path): CsvReader
    {
        $options = new CsvOptions;
        $options->FIELD_DELIMITER = $this->detectCsvDelimiter($path);

        return new CsvReader($options);
    }

    private function detectCsvDelimiter(string $path): string
    {
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new ImportFileException('Não foi possível ler o arquivo. Verifique se ele é um CSV ou XLSX válido.');
        }

        try {
            $firstLine = fgets($stream);
        } finally {
            fclose($stream);
        }

        if ($firstLine === false) {
            throw new ImportFileException('A primeira planilha está vazia ou não possui cabeçalho.');
        }

        $firstLine = rtrim($firstLine, "\r\n");
        $commaColumns = count(str_getcsv($firstLine, ',', '"'));
        $semicolonColumns = count(str_getcsv($firstLine, ';', '"'));

        return $semicolonColumns > $commaColumns ? ';' : ',';
    }

    /**
     * @param  list<string>  $headers
     * @return list<string>
     */
    private function validatedHeaders(array $headers): array
    {
        if ($headers === [] || count($headers) > self::MAX_COLUMNS) {
            throw new ImportFileException('A planilha precisa ter entre 1 e 100 colunas.');
        }

        $seen = [];

        foreach ($headers as $index => $header) {
            $header = trim($header);

            if ($index === 0) {
                $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
            }

            $key = mb_strtolower($header);

            if ($key === '') {
                throw new ImportFileException('Todas as colunas precisam ter um cabeçalho.');
            }

            if (isset($seen[$key])) {
                throw new ImportFileException('Há nomes de coluna repetidos. Renomeie os cabeçalhos para continuar.');
            }

            $seen[$key] = true;
            $headers[$index] = $header;
        }

        return $headers;
    }

    /** @param list<string> $values */
    private function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function cellToString(bool|DateInterval|DateTimeInterface|float|int|string|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\\TH:i:s');
        }

        if ($value instanceof DateInterval) {
            return $value->format('%r%y-%m-%d %h:%i:%s');
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}

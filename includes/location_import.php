<?php

function normalizeLocationHeader(string $header): string
{
    return mb_strtolower(preg_replace('/[^A-Za-z0-9]/', '', trim($header)) ?? '', 'UTF-8');
}

function parseLocationWorkbook(string $filePath): array
{
    $table = readXlsxTable($filePath);
    $aliases = [
        'state' => ['state'],
        'district' => ['district'],
        'taluka' => ['taluka', 'tehsil'],
        'village' => ['village']
    ];
    $columnIndexes = [];
    foreach ($table['headers'] as $index => $header) {
        $normalized = normalizeLocationHeader($header);
        foreach ($aliases as $field => $names) {
            if (!isset($columnIndexes[$field]) && in_array($normalized, $names, true)) {
                $columnIndexes[$field] = $index;
            }
        }
    }

    $missingHeaders = array_values(array_diff(array_keys($aliases), array_keys($columnIndexes)));
    if ($missingHeaders !== []) {
        throw new RuntimeException(
            'The first worksheet must contain State, District, Taluka, and Village columns.'
        );
    }

    $rows = [];
    $errors = [];
    foreach ($table['rows'] as $index => $values) {
        $lineNumber = $index + 2;
        $row = [];
        foreach ($columnIndexes as $field => $columnIndex) {
            $row[$field] = trim((string) ($values[$columnIndex] ?? ''));
        }
        if (implode('', $row) === '') {
            continue;
        }

        $problems = [];
        foreach ($aliases as $field => $_) {
            if ($row[$field] === '') {
                $problems[] = ucfirst($field) . ' is required';
            } elseif (mb_strlen($row[$field], 'UTF-8') > 100) {
                $problems[] = ucfirst($field) . ' must be 100 characters or fewer';
            }
        }
        if ($problems !== []) {
            $errors[] = 'Row ' . $lineNumber . ': ' . implode('; ', $problems) . '.';
            continue;
        }

        $rows[] = $row;
    }

    if ($rows === [] && $errors === []) {
        throw new RuntimeException('No location rows were found beneath the workbook headers.');
    }

    return ['rows' => $rows, 'errors' => $errors];
}

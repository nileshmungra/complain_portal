<?php

function normalizeDealerHeader(string $header): string
{
    return mb_strtolower(preg_replace('/[^A-Za-z0-9]/', '', trim($header)) ?? '', 'UTF-8');
}

function parseDealerStatus(string $status): ?int
{
    return match (mb_strtolower(trim($status), 'UTF-8')) {
        'active', 'enable', 'enabled', 'yes', '1' => 1,
        'inactive', 'deactive', 'deactivated', 'disabled', 'no', '0' => 0,
        default => null
    };
}

function parseDealerWorkbook(string $filePath): array
{
    $table = readXlsxTable($filePath);
    $headerAliases = [
        'dealer_code' => ['dealercode', 'code'],
        'name' => ['dealer', 'dealername', 'name'],
        'address' => ['address'],
        'village' => ['village'],
        'pin_code' => ['pincode', 'postalcode', 'zip'],
        'taluka' => ['taluka', 'tehsil'],
        'district' => ['district'],
        'state' => ['state'],
        'country' => ['country'],
        'mobile' => ['mobile', 'phone', 'phonenumber'],
        'email' => ['email', 'emailid'],
        'status' => ['status']
    ];
    $columnIndexes = [];
    foreach ($table['headers'] as $index => $header) {
        $normalizedHeader = normalizeDealerHeader($header);
        foreach ($headerAliases as $field => $aliases) {
            if (!isset($columnIndexes[$field]) && in_array($normalizedHeader, $aliases, true)) {
                $columnIndexes[$field] = $index;
            }
        }
    }
    if (!isset($columnIndexes['dealer_code'], $columnIndexes['name'])) {
        throw new RuntimeException('The first worksheet must contain Dealer Code and Dealer columns.');
    }

    $rows = [];
    $errors = [];
    foreach ($table['rows'] as $index => $values) {
        $lineNumber = $index + 2;
        $row = [];
        foreach (array_keys($headerAliases) as $field) {
            $columnIndex = $columnIndexes[$field] ?? null;
            $row[$field] = $columnIndex === null
                ? ''
                : trim((string) ($values[$columnIndex] ?? ''));
        }
        if ($row['dealer_code'] === '' && $row['name'] === '') {
            continue;
        }

        $problems = [];
        if ($row['dealer_code'] === '' || mb_strlen($row['dealer_code'], 'UTF-8') > 60) {
            $problems[] = 'Dealer Code is required and must be 60 characters or fewer';
        }
        if ($row['name'] === '' || mb_strlen($row['name'], 'UTF-8') > 200) {
            $problems[] = 'Dealer name is required and must be 200 characters or fewer';
        }
        foreach ([
            'address' => 65535,
            'village' => 100,
            'pin_code' => 20,
            'taluka' => 100,
            'district' => 50,
            'state' => 100,
            'country' => 100,
            'mobile' => 30,
            'email' => 255
        ] as $field => $maxLength) {
            if (mb_strlen($row[$field], 'UTF-8') > $maxLength) {
                $problems[] = ucfirst(str_replace('_', ' ', $field)) . " must be {$maxLength} characters or fewer";
            }
        }
        if ($row['email'] !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
            $problems[] = 'Email is not valid';
        }
        if (isset($columnIndexes['status'])) {
            $activeStatus = parseDealerStatus($row['status']);
            if ($activeStatus === null) {
                $problems[] = 'Status must be Active or Deactive';
            }
        } else {
            $activeStatus = 1;
        }

        if ($problems !== []) {
            $errors[] = 'Row ' . $lineNumber . ': ' . implode('; ', $problems) . '.';
            continue;
        }

        $rows[] = [
            'dealer_code' => $row['dealer_code'],
            'name' => $row['name'],
            'address' => $row['address'] !== '' ? $row['address'] : null,
            'village' => $row['village'] !== '' ? $row['village'] : null,
            'pin_code' => $row['pin_code'] !== '' ? $row['pin_code'] : null,
            'taluka' => $row['taluka'] !== '' ? $row['taluka'] : null,
            'district' => $row['district'] !== '' ? $row['district'] : null,
            'state' => $row['state'] !== '' ? $row['state'] : null,
            'country' => $row['country'] !== '' ? $row['country'] : null,
            'mobile' => $row['mobile'] !== '' ? $row['mobile'] : null,
            'email' => $row['email'] !== '' ? $row['email'] : null,
            'is_active' => $activeStatus
        ];
    }

    if ($rows === [] && $errors === []) {
        throw new RuntimeException('No dealer rows were found beneath the workbook headers.');
    }

    return ['rows' => $rows, 'errors' => $errors];
}

function importDealerRows(mysqli $conn, array $rows, int $actorId): int
{
    $statement = $conn->prepare(
        'INSERT INTO dealers
            (dealer_code, name, address, village, pin_code, taluka, district, state, country, mobile, email, is_active, created_by, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            address = VALUES(address),
            village = VALUES(village),
            pin_code = VALUES(pin_code),
            taluka = VALUES(taluka),
            district = VALUES(district),
            state = VALUES(state),
            country = VALUES(country),
            mobile = VALUES(mobile),
            email = VALUES(email),
            is_active = VALUES(is_active),
            updated_by = VALUES(updated_by),
            updated_at = NOW()'
    );
    $importedCount = 0;
    foreach ($rows as $row) {
        $statement->bind_param(
            'sssssssssssiii',
            $row['dealer_code'],
            $row['name'],
            $row['address'],
            $row['village'],
            $row['pin_code'],
            $row['taluka'],
            $row['district'],
            $row['state'],
            $row['country'],
            $row['mobile'],
            $row['email'],
            $row['is_active'],
            $actorId,
            $actorId
        );
        $statement->execute();
        $importedCount++;
    }

    return $importedCount;
}

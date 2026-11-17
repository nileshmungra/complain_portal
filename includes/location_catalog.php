<?php

function getLocationNamesByDepth(mysqli $conn): array
{
    $result = [
        'states' => [],
        'districts' => [],
        'talukas' => [],
        'villages' => []
    ];
    $labels = [1 => 'states', 2 => 'districts', 3 => 'talukas', 4 => 'villages'];
    $locations = $conn->query(
        'SELECT depth, name FROM location_options ORDER BY depth, name'
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($locations as $location) {
        $key = $labels[(int) $location['depth']] ?? null;
        if ($key !== null) {
            $result[$key][] = $location['name'];
        }
    }
    foreach ($result as $key => $names) {
        $result[$key] = array_values(array_unique($names));
    }

    return $result;
}

function getStateForDistrict(mysqli $conn, string $district): ?string
{
    $statement = $conn->prepare(
        'SELECT DISTINCT state.name
         FROM location_options district
         INNER JOIN location_options state ON state.id = district.parent_id
         WHERE district.depth = 2 AND district.name = ?
         ORDER BY state.name'
    );
    $statement->bind_param('s', $district);
    $statement->execute();
    $states = array_column($statement->get_result()->fetch_all(MYSQLI_ASSOC), 'name');

    return count($states) === 1 ? $states[0] : null;
}

function addLocationPath(
    mysqli $conn,
    string $state,
    string $district = '',
    string $taluka = '',
    string $village = ''
): array {
    $parts = [$state, $district, $taluka, $village];
    $foundGap = false;
    foreach ($parts as $index => $part) {
        if (mb_strlen($part, 'UTF-8') > 100) {
            throw new InvalidArgumentException('Each location name must be 100 characters or fewer.');
        }
        if ($index === 0 && $part === '') {
            throw new InvalidArgumentException('Enter a state.');
        }
        if ($part === '') {
            $foundGap = true;
        } elseif ($foundGap) {
            throw new InvalidArgumentException('Add each location level in order, without skipping a level.');
        }
    }

    static $pathCache = [];
    $canonical = [];
    $parentId = 0;
    $saveStatement = $conn->prepare(
        'INSERT INTO location_options (parent_id, depth, name)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    $nameStatement = $conn->prepare('SELECT name FROM location_options WHERE id = ? LIMIT 1');
    foreach ($parts as $index => $part) {
        if ($part === '') {
            break;
        }
        $depth = $index + 1;
        $cacheKey = $parentId . ':' . mb_strtolower($part, 'UTF-8');
        if (isset($pathCache[$cacheKey])) {
            $parentId = $pathCache[$cacheKey]['id'];
            $canonical[] = $pathCache[$cacheKey]['name'];
            continue;
        }
        $saveStatement->bind_param('iis', $parentId, $depth, $part);
        $saveStatement->execute();
        $parentId = (int) $conn->insert_id;
        if ($saveStatement->affected_rows === 1) {
            $savedName = $part;
        } else {
            $nameStatement->bind_param('i', $parentId);
            $nameStatement->execute();
            $savedName = $nameStatement->get_result()->fetch_assoc()['name'] ?? null;
            if (!is_string($savedName)) {
                throw new RuntimeException('The location could not be confirmed after saving.');
            }
        }
        $pathCache[$cacheKey] = ['id' => $parentId, 'name' => $savedName];
        $canonical[] = $savedName;
    }

    return $canonical;
}

function locationCatalogDatalistId(string $field): string
{
    $ids = [
        'state' => 'location-states',
        'district' => 'location-districts',
        'taluka' => 'location-talukas',
        'village' => 'location-villages'
    ];

    if (!isset($ids[$field])) {
        throw new InvalidArgumentException('Unknown location field.');
    }

    return $ids[$field];
}

function renderLocationDatalists(array $locations): void
{
    foreach ([
        'state' => 'states',
        'district' => 'districts',
        'taluka' => 'talukas',
        'village' => 'villages'
    ] as $field => $key) {
        echo '<datalist id="' . escapeHtml(locationCatalogDatalistId($field)) . '">';
        foreach ($locations[$key] ?? [] as $name) {
            echo '<option value="' . escapeHtml($name) . '"></option>';
        }
        echo '</datalist>';
    }
}

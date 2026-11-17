<?php
require_once __DIR__ . '/farmer.php';

function applyComplaintFilters(array $scope, array $filters, array $excludedFilters = []): array
{
    $filterConditions = [
        'type' => 'c.complaint_type = ?',
        'product' => 'c.product = ?',
        'status' => 'c.status = ?',
        'district' => 'c.district = ?',
        'from' => 'c.created_at >= ?',
        'to' => 'c.created_at < DATE_ADD(?, INTERVAL 1 DAY)'
    ];
    $complaintTypePatterns = [
        'Material Fault' => '(^|,)[[:space:]]*material fault[[:space:]]*(,|$)',
        'Short Supply' => '(^|,)[[:space:]]*short supply[[:space:]]*(,|$)',
        'Wrong Dispatch' => '(^|,)[[:space:]]*wrong dispatch[[:space:]]*(,|$)',
        'Exceed Supply' => '(^|,)[[:space:]]*(exceed supply|excess material)[[:space:]]*(,|$)',
        'Other' => '(^|,)[[:space:]]*other[[:space:]]*(,|$)'
    ];
    $conditions = [];

    foreach ($filterConditions as $key => $condition) {
        if (($filters[$key] ?? '') === '' || in_array($key, $excludedFilters, true)) {
            continue;
        }

        if ($key === 'type' && isset($complaintTypePatterns[$filters[$key]])) {
            $conditions[] = 'LOWER(c.complaint_type) REGEXP ?';
            $scope['types'] .= 's';
            $scope['values'][] = $complaintTypePatterns[$filters[$key]];
            continue;
        }

        $conditions[] = $condition;
        $scope['types'] .= 's';
        $scope['values'][] = $filters[$key];
    }

    if ($conditions !== []) {
        $scope['where'] = '(' . $scope['where'] . ') AND ' . implode(' AND ', $conditions);
    }

    return $scope;
}

function getComplaintFilterState(array $scope, array $query): array
{
    $searchTerm = isset($query['search']) && is_string($query['search'])
        ? mb_substr(trim($query['search']), 0, 160, 'UTF-8')
        : '';
    $normalizedFarmerMobile = normalizeFarmerMobile($searchTerm);
    $searchTerms = $normalizedFarmerMobile !== null
        ? [$normalizedFarmerMobile]
        : ($searchTerm === ''
        ? []
        : (preg_split('/\s+/u', $searchTerm, -1, PREG_SPLIT_NO_EMPTY) ?: [$searchTerm]));
    $searchTerms = array_slice($searchTerms, 0, 8);
    $searchScope = $scope;

    if ($searchTerms !== []) {
        $searchFields = [
            "COALESCE(c.public_reference, CONCAT('CMP-', LPAD(c.id, 6, '0'))) LIKE ? ESCAPE '!'",
            "CAST(c.id AS CHAR) LIKE ? ESCAPE '!'",
            "c.title LIKE ? ESCAPE '!'",
            "c.farmer_name LIKE ? ESCAPE '!'",
            "c.farmer_mobile LIKE ? ESCAPE '!'",
            "c.village LIKE ? ESCAPE '!'",
            "c.taluka LIKE ? ESCAPE '!'",
            "c.complaint_type LIKE ? ESCAPE '!'",
            "c.product LIKE ? ESCAPE '!'",
            "c.actual_product LIKE ? ESCAPE '!'",
            "CAST(c.supply_date AS CHAR) LIKE ? ESCAPE '!'",
            "CAST(c.quantity_supplied AS CHAR) LIKE ? ESCAPE '!'",
            "c.quantity_unit LIKE ? ESCAPE '!'",
            "CAST(c.actual_quantity_supplied AS CHAR) LIKE ? ESCAPE '!'",
            "c.actual_quantity_unit LIKE ? ESCAPE '!'",
            "c.invoice_reference LIKE ? ESCAPE '!'",
            "c.wo_number LIKE ? ESCAPE '!'",
            "c.district LIKE ? ESCAPE '!'",
            "c.department LIKE ? ESCAPE '!'",
            "c.details LIKE ? ESCAPE '!'",
            "c.status LIKE ? ESCAPE '!'",
            "CAST(c.created_at AS CHAR) LIKE ? ESCAPE '!'",
            "EXISTS (SELECT 1 FROM users search_user WHERE search_user.id = c.created_by AND search_user.full_name LIKE ? ESCAPE '!')",
            "EXISTS (SELECT 1 FROM legacy_complaint_data search_legacy WHERE search_legacy.complaint_id = c.id AND search_legacy.source_id LIKE ? ESCAPE '!')",
            "EXISTS (SELECT 1 FROM legacy_complaint_data search_legacy WHERE search_legacy.complaint_id = c.id AND search_legacy.dealer_name LIKE ? ESCAPE '!')",
            "EXISTS (SELECT 1 FROM dealers search_dealer WHERE search_dealer.id = c.dealer_id AND search_dealer.name LIKE ? ESCAPE '!')",
            "EXISTS (SELECT 1 FROM dealers search_dealer WHERE search_dealer.id = c.dealer_id AND search_dealer.dealer_code LIKE ? ESCAPE '!')",
            "EXISTS (SELECT 1 FROM legacy_complaint_data search_legacy WHERE search_legacy.complaint_id = c.id AND search_legacy.area_manager_name LIKE ? ESCAPE '!')",
            "EXISTS (SELECT 1 FROM legacy_complaint_data search_legacy WHERE search_legacy.complaint_id = c.id AND search_legacy.created_by_name LIKE ? ESCAPE '!')",
            "EXISTS (SELECT 1 FROM legacy_complaint_data search_legacy WHERE search_legacy.complaint_id = c.id AND search_legacy.original_json LIKE ? ESCAPE '!')",
            "EXISTS (SELECT 1 FROM complaint_attachments search_attachment WHERE search_attachment.complaint_id = c.id AND search_attachment.original_name LIKE ? ESCAPE '!')",
            "EXISTS (SELECT 1 FROM complaint_attachments search_attachment WHERE search_attachment.complaint_id = c.id AND search_attachment.category LIKE ? ESCAPE '!')",
            "EXISTS (SELECT 1 FROM complaint_attachments search_attachment WHERE search_attachment.complaint_id = c.id AND search_attachment.purpose LIKE ? ESCAPE '!')"
        ];
        $tokenConditions = [];
        $searchValues = [];

        foreach ($searchTerms as $term) {
            $pattern = '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $tokenConditions[] = '(' . implode(' OR ', $searchFields) . ')';
            array_push($searchValues, ...array_fill(0, count($searchFields), $pattern));
        }

        $searchScope['where'] = '(' . $scope['where'] . ') AND (' . implode(' AND ', $tokenConditions) . ')';
        $searchScope['types'] .= str_repeat('s', count($searchValues));
        $searchScope['values'] = array_merge($scope['values'], $searchValues);
    }

    $typeFilter = isset($query['type']) && is_string($query['type'])
        ? mb_substr(trim($query['type']), 0, 150, 'UTF-8')
        : '';
    $productFilter = isset($query['product']) && is_string($query['product'])
        ? mb_substr(trim($query['product']), 0, 300, 'UTF-8')
        : '';
    $validStatuses = ['Pending', 'In Progress', 'Resolved', 'Closed', 'Rejected'];
    $statusFilter = isset($query['status']) && is_string($query['status']) && in_array($query['status'], $validStatuses, true)
        ? $query['status']
        : '';
    $districtFilter = isset($query['district']) && is_string($query['district'])
        ? mb_substr(trim($query['district']), 0, 50, 'UTF-8')
        : '';
    $districtOptions = (require __DIR__ . '/../config/complaints.php')['districts'];
    $filterErrors = [];
    if ($districtFilter !== '' && !in_array($districtFilter, $districtOptions, true)) {
        $filterErrors[] = 'Select a district from the list.';
        $districtFilter = '';
    }

    $normalizeDate = static function (mixed $value, string $label) use (&$filterErrors): string {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }
        $dateValue = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
        if (!$date || $date->format('Y-m-d') !== $dateValue) {
            $filterErrors[] = 'Enter a valid ' . $label . ' date.';
            return '';
        }

        return $dateValue;
    };
    $fromFilter = $normalizeDate($query['from'] ?? null, 'start');
    $toFilter = $normalizeDate($query['to'] ?? null, 'end');
    if ($fromFilter !== '' && $toFilter !== '' && $fromFilter > $toFilter) {
        $filterErrors[] = 'The start date must be on or before the end date.';
        $fromFilter = '';
        $toFilter = '';
    }
    $activeFilters = array_filter([
        'search' => $searchTerm,
        'type' => $typeFilter,
        'product' => $productFilter,
        'status' => $statusFilter,
        'district' => $districtFilter,
        'from' => $fromFilter,
        'to' => $toFilter
    ], static fn ($value) => $value !== '');

    return [
        'searchTerm' => $searchTerm,
        'searchTerms' => $searchTerms,
        'typeFilter' => $typeFilter,
        'productFilter' => $productFilter,
        'statusFilter' => $statusFilter,
        'districtFilter' => $districtFilter,
        'fromFilter' => $fromFilter,
        'toFilter' => $toFilter,
        'filterErrors' => $filterErrors,
        'activeFilters' => $activeFilters,
        'searchScope' => $searchScope,
        'dashboardScope' => applyComplaintFilters($searchScope, $activeFilters),
        'typeChartScope' => applyComplaintFilters($searchScope, $activeFilters, ['type']),
        'productChartScope' => applyComplaintFilters($searchScope, $activeFilters, ['product'])
    ];
}

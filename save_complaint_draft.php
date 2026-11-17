<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/complaint_drafts.php';

function sendComplaintDraftJson(int $status, array $response): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendComplaintDraftJson(405, ['error' => 'Use POST to save a complaint draft.']);
}
$requestBody = file_get_contents('php://input');
if (!is_string($requestBody) || strlen($requestBody) > 512 * 1024) {
    sendComplaintDraftJson(413, ['error' => 'The complaint draft is too large to save.']);
}
try {
    $request = json_decode($requestBody, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    sendComplaintDraftJson(400, ['error' => 'The complaint draft request is invalid.']);
}
if (!is_array($request) || !is_array($request['fields'] ?? null)) {
    sendComplaintDraftJson(400, ['error' => 'The complaint draft fields are missing.']);
}
if (!isValidCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $request['csrf_token'] ?? null)) {
    sendComplaintDraftJson(403, ['error' => 'Your form session expired. Refresh the page and try again.']);
}

$fieldLimits = [
    'dealer_id' => 12,
    'title' => 180,
    'farmer_name' => 100,
    'farmer_mobile' => 20,
    'state' => 100,
    'village' => 100,
    'taluka' => 100,
    'district' => 50,
    'supply_date' => 10,
    'wo_number' => 100,
    'invoice_reference' => 100
];
$fields = [];
foreach ($fieldLimits as $field => $limit) {
    if ($field === 'dealer_id' && !in_array($currentUser['role'], ['admin', 'area_manager'], true)) {
        continue;
    }
    $value = $request['fields'][$field] ?? '';
    if (!is_string($value) || mb_strlen($value, 'UTF-8') > $limit) {
        sendComplaintDraftJson(422, ['error' => 'One or more complaint draft values are invalid.']);
    }
    $fields[$field] = trim($value);
}
$itemLimits = [
    'complaint_type' => 150,
    'product' => 300,
    'quantity_supplied' => 20,
    'quantity_unit' => 30,
    'actual_product' => 300,
    'actual_quantity_supplied' => 20,
    'actual_quantity_unit' => 30,
    'details' => 10000
];
$requestedItems = $request['fields']['items'] ?? null;
if (!is_array($requestedItems) || $requestedItems === [] || count($requestedItems) > 10) {
    sendComplaintDraftJson(422, ['error' => 'Add between 1 and 10 complaint items before saving the draft.']);
}
$fields['items'] = [];
foreach ($requestedItems as $requestedItem) {
    if (!is_array($requestedItem)) {
        sendComplaintDraftJson(422, ['error' => 'One or more complaint draft items are invalid.']);
    }
    $item = [];
    foreach ($itemLimits as $field => $limit) {
        $value = $requestedItem[$field] ?? '';
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > $limit) {
            sendComplaintDraftJson(422, ['error' => 'One or more complaint draft items are invalid.']);
        }
        $item[$field] = trim($value);
    }
    $fields['items'][] = $item;
}

try {
    $draftId = saveComplaintDraftFields($conn, (int) $currentUser['id'], $fields);
    $statement = $conn->prepare('SELECT updated_at FROM complaint_drafts WHERE id = ? LIMIT 1');
    $statement->bind_param('i', $draftId);
    $statement->execute();
    $draft = $statement->get_result()->fetch_assoc();
    sendComplaintDraftJson(200, [
        'draftId' => $draftId,
        'updatedAt' => $draft['updated_at'] ?? date('Y-m-d H:i:s')
    ]);
} catch (mysqli_sql_exception $exception) {
    error_log('Complaint draft could not be saved: ' . $exception->getMessage());
    sendComplaintDraftJson(500, ['error' => 'The complaint draft could not be saved. Please try again.']);
}

<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/complaint_drafts.php';
require_once __DIR__ . '/includes/complaint_uploads.php';

function sendDraftAttachmentJson(int $status, array $response): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendDraftAttachmentJson(405, ['error' => 'Use POST to manage complaint draft attachments.']);
}
if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    sendDraftAttachmentJson(403, ['error' => 'Your form session expired. Refresh the page and try again.']);
}

$userId = (int) $currentUser['id'];
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
if ($action === 'discard') {
    try {
        $removed = deleteComplaintDraft($conn, $userId);
        sendDraftAttachmentJson(
            $removed ? 200 : 500,
            ['discarded' => true, 'message' => $removed ? 'Draft discarded.' : 'Draft was discarded, but some files could not be removed.']
        );
    } catch (Throwable $exception) {
        error_log('Complaint draft discard failed: ' . $exception->getMessage());
        sendDraftAttachmentJson(500, ['error' => 'The complaint draft could not be discarded.']);
    }
}

if ($action === 'remove') {
    $attachmentId = filter_var($_POST['attachment_id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($attachmentId) || $attachmentId < 1) {
        sendDraftAttachmentJson(422, ['error' => 'Select a valid draft attachment.']);
    }
    $statement = $conn->prepare(
        'SELECT a.stored_name
         FROM complaint_draft_attachments a
         INNER JOIN complaint_drafts d ON d.id = a.draft_id
         WHERE a.id = ? AND d.user_id = ?
         LIMIT 1'
    );
    $statement->bind_param('ii', $attachmentId, $userId);
    $statement->execute();
    $attachment = $statement->get_result()->fetch_assoc();
    if (!$attachment) {
        sendDraftAttachmentJson(404, ['error' => 'That draft attachment no longer exists.']);
    }
    $storedName = $attachment['stored_name'];
    if (!is_string($storedName) || !preg_match(complaintDraftAttachmentPattern(), $storedName)) {
        error_log('Invalid draft attachment filename encountered during removal.');
        sendDraftAttachmentJson(500, ['error' => 'The draft attachment could not be removed safely.']);
    }
    $deleteStatement = $conn->prepare('DELETE FROM complaint_draft_attachments WHERE id = ?');
    $deleteStatement->bind_param('i', $attachmentId);
    $deleteStatement->execute();
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'complaints' . DIRECTORY_SEPARATOR . $storedName;
    if (is_file($path) && !unlink($path)) {
        error_log('Unable to remove a private attachment from a complaint draft.');
        sendDraftAttachmentJson(500, ['error' => 'The attachment record was removed, but its file could not be deleted.']);
    }
    sendDraftAttachmentJson(200, ['removed' => true]);
}

if ($action !== 'upload') {
    sendDraftAttachmentJson(422, ['error' => 'Choose a valid draft attachment action.']);
}

$uploadResult = validateComplaintUploads($_FILES);
if ($uploadResult['error'] !== '') {
    sendDraftAttachmentJson(422, ['error' => $uploadResult['error']]);
}
if ($uploadResult['files'] === []) {
    sendDraftAttachmentJson(422, ['error' => 'Choose at least one attachment to save.']);
}

try {
    $storageDirectory = ensurePrivateComplaintStorage();
} catch (RuntimeException $exception) {
    sendDraftAttachmentJson(500, ['error' => $exception->getMessage()]);
}

$savedPaths = [];
$transactionStarted = false;
try {
    $conn->begin_transaction();
    $transactionStarted = true;
    $createDraft = $conn->prepare(
        "INSERT IGNORE INTO complaint_drafts (user_id, form_data) VALUES (?, '{}')"
    );
    $createDraft->bind_param('i', $userId);
    $createDraft->execute();
    $draftStatement = $conn->prepare('SELECT id FROM complaint_drafts WHERE user_id = ? FOR UPDATE');
    $draftStatement->bind_param('i', $userId);
    $draftStatement->execute();
    $draftId = (int) $draftStatement->get_result()->fetch_assoc()['id'];
    $totalsStatement = $conn->prepare(
        'SELECT COUNT(*) AS file_count, COALESCE(SUM(file_size), 0) AS total_size
         FROM complaint_draft_attachments WHERE draft_id = ?'
    );
    $totalsStatement->bind_param('i', $draftId);
    $totalsStatement->execute();
    $totals = $totalsStatement->get_result()->fetch_assoc();
    $newBytes = array_sum(array_column($uploadResult['files'], 'size'));
    if (
        (int) $totals['file_count'] + count($uploadResult['files']) > 5
        || (int) $totals['total_size'] + $newBytes > 50 * 1024 * 1024
    ) {
        throw new RuntimeException('A complaint draft can contain up to 5 files and 50 MB in total.');
    }

    $attachmentStatement = $conn->prepare(
        'INSERT INTO complaint_draft_attachments
            (draft_id, category, stored_name, original_name, mime_type, file_size)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $savedAttachments = [];
    foreach ($uploadResult['files'] as $file) {
        $storedName = bin2hex(random_bytes(16)) . '.' . $file['extension'];
        $destination = $storageDirectory . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($file['temporary_path'], $destination)) {
            throw new RuntimeException('The server could not save a draft attachment.');
        }
        $savedPaths[] = $destination;
        $attachmentStatement->bind_param(
            'issssi',
            $draftId,
            $file['category'],
            $storedName,
            $file['original_name'],
            $file['mime_type'],
            $file['size']
        );
        $attachmentStatement->execute();
        $savedAttachments[] = [
            'id' => (int) $conn->insert_id,
            'category' => $file['category'],
            'name' => $file['original_name'],
            'size' => $file['size']
        ];
    }
    $conn->commit();
    $transactionStarted = false;
    sendDraftAttachmentJson(200, ['attachments' => $savedAttachments]);
} catch (RuntimeException $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    foreach ($savedPaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    sendDraftAttachmentJson(422, ['error' => $exception->getMessage()]);
} catch (mysqli_sql_exception $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    foreach ($savedPaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    error_log('Complaint draft attachment upload failed: ' . $exception->getMessage());
    sendDraftAttachmentJson(500, ['error' => 'The draft attachments could not be saved. Please try again.']);
}

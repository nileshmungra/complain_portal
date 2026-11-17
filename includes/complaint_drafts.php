<?php

function complaintDraftAttachmentPattern(): string
{
    return '/\A[a-f0-9]{32}\.(jpg|jpeg|png|webp|pdf|docx|xlsx|mp4|webm|zip|ogg)\z/';
}

function loadComplaintDraft(mysqli $conn, int $userId): ?array
{
    $statement = $conn->prepare('SELECT id, form_data, updated_at FROM complaint_drafts WHERE user_id = ? LIMIT 1');
    $statement->bind_param('i', $userId);
    $statement->execute();
    $draft = $statement->get_result()->fetch_assoc();
    if (!$draft) {
        return null;
    }

    try {
        $formData = json_decode((string) $draft['form_data'], true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('The saved complaint draft could not be read.', 0, $exception);
    }
    if (!is_array($formData)) {
        throw new RuntimeException('The saved complaint draft has an invalid format.');
    }

    return [
        'id' => (int) $draft['id'],
        'form_data' => $formData,
        'updated_at' => (string) $draft['updated_at']
    ];
}

function loadComplaintDraftAttachments(mysqli $conn, int $draftId): array
{
    $statement = $conn->prepare(
        'SELECT id, category, stored_name, original_name, mime_type, file_size, created_at
         FROM complaint_draft_attachments WHERE draft_id = ? ORDER BY id'
    );
    $statement->bind_param('i', $draftId);
    $statement->execute();

    return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
}

function deleteComplaintDraft(mysqli $conn, int $userId): bool
{
    $draft = loadComplaintDraft($conn, $userId);
    if (!$draft) {
        return true;
    }
    $attachments = loadComplaintDraftAttachments($conn, $draft['id']);
    $conn->begin_transaction();
    try {
        $statement = $conn->prepare('DELETE FROM complaint_drafts WHERE id = ? AND user_id = ?');
        $statement->bind_param('ii', $draft['id'], $userId);
        $statement->execute();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    $storageDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'complaints';
    $cleanupSucceeded = true;
    foreach ($attachments as $attachment) {
        $storedName = $attachment['stored_name'];
        if (!is_string($storedName) || !preg_match(complaintDraftAttachmentPattern(), $storedName)) {
            error_log('Invalid stored filename encountered while deleting a complaint draft.');
            $cleanupSucceeded = false;
            continue;
        }
        $path = $storageDirectory . DIRECTORY_SEPARATOR . $storedName;
        if (is_file($path) && !unlink($path)) {
            error_log('Unable to remove a private attachment from a deleted complaint draft.');
            $cleanupSucceeded = false;
        }
    }

    return $cleanupSucceeded;
}

function saveComplaintDraftFields(mysqli $conn, int $userId, array $fields): int
{
    $payload = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    $statement = $conn->prepare(
        'INSERT INTO complaint_drafts (user_id, form_data)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE form_data = VALUES(form_data), updated_at = NOW()'
    );
    $statement->bind_param('is', $userId, $payload);
    $statement->execute();

    $draftStatement = $conn->prepare('SELECT id FROM complaint_drafts WHERE user_id = ? LIMIT 1');
    $draftStatement->bind_param('i', $userId);
    $draftStatement->execute();

    return (int) $draftStatement->get_result()->fetch_assoc()['id'];
}

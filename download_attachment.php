<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/complaint_uploads.php';

$attachmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($attachmentId === false || $attachmentId === null || $attachmentId < 1) {
    http_response_code(404);
    exit('Attachment not found.');
}

$scope = getComplaintScope($currentUser);
$attachments = fetchComplaintRows(
    $conn,
    "SELECT a.complaint_id, a.stored_name, a.original_name, a.mime_type, a.file_size
     FROM complaint_attachments a
     INNER JOIN complaints c ON c.id = a.complaint_id
     WHERE a.id = {$attachmentId} AND {$scope['where']}
     LIMIT 1",
    $scope
);

if ($attachments === []) {
    http_response_code(404);
    exit('Attachment not found.');
}

$attachment = $attachments[0];
if (!preg_match('/\A[a-f0-9]{32}\.(jpg|jpeg|png|webp|pdf|docx|xlsx|mp4|webm|zip|ogg)\z/', $attachment['stored_name'])) {
    http_response_code(404);
    exit('Attachment not found.');
}

$filePath = complaintAttachmentFilePath((int) $attachment['complaint_id'], $attachment['stored_name']);
if (!is_file($filePath)) {
    http_response_code(404);
    exit('Attachment not found.');
}

$originalName = preg_replace('/[\x00-\x1F\x7F]/', '', $attachment['original_name']) ?? 'attachment';
$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName) ?: 'attachment';
$inlineMimeTypes = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/pdf',
    'video/mp4',
    'video/webm'
];
$disposition = in_array($attachment['mime_type'], $inlineMimeTypes, true) ? 'inline' : 'attachment';
header('Content-Type: ' . $attachment['mime_type']);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: ' . $disposition . '; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;

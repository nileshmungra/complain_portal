<?php
function complaintStorageAccessRules(): string
{
    return "Options -Indexes\n\n"
        . "<IfModule mod_authz_core.c>\n"
        . "    Require all denied\n"
        . "</IfModule>\n\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "    Order allow,deny\n"
        . "    Deny from all\n"
        . "</IfModule>\n";
}

function ensurePrivateComplaintStorage(): string
{
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'complaints';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Secure file storage is unavailable. Please try again later.');
    }

    $rulesPath = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    $currentRules = is_file($rulesPath) ? file_get_contents($rulesPath) : false;
    if (
        !is_string($currentRules)
        || str_replace(["\r\n", "\r"], "\n", $currentRules) !== complaintStorageAccessRules()
    ) {
        if (file_put_contents($rulesPath, complaintStorageAccessRules(), LOCK_EX) === false) {
            throw new RuntimeException('Private attachment access rules could not be written.');
        }
    }

    return $directory;
}

function ensureComplaintAttachmentStorage(int $complaintId): string
{
    if ($complaintId < 1) {
        throw new InvalidArgumentException('A valid complaint is required for attachment storage.');
    }

    $directory = ensurePrivateComplaintStorage()
        . DIRECTORY_SEPARATOR
        . $complaintId;
    if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
        throw new RuntimeException('The complaint attachment folder could not be created.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('The complaint attachment folder is not writable.');
    }

    return $directory;
}

function complaintAttachmentFilePath(int $complaintId, string $storedName): string
{
    if (
        $complaintId < 1
        || !preg_match('/\A[a-f0-9]{32}\.(jpg|jpeg|png|webp|pdf|docx|xlsx|mp4|webm|zip|ogg)\z/', $storedName)
    ) {
        throw new InvalidArgumentException('The complaint attachment path is invalid.');
    }

    $storageDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'complaints';
    $complaintPath = $storageDirectory . DIRECTORY_SEPARATOR . $complaintId . DIRECTORY_SEPARATOR . $storedName;

    return is_file($complaintPath)
        ? $complaintPath
        : $storageDirectory . DIRECTORY_SEPARATOR . $storedName;
}

function validateComplaintUploads(array $uploadedFiles): array
{
    $result = ['files' => [], 'error' => ''];
    $categories = [
        'images' => [
            'label' => 'Image',
            'types' => [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp']
            ]
        ],
        'documents' => [
            'label' => 'Document',
            'types' => [
                'application/pdf' => ['pdf'],
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx']
            ]
        ],
        'videos' => [
            'label' => 'Video',
            'types' => [
                'video/mp4' => ['mp4'],
                'video/webm' => ['webm']
            ]
        ]
    ];
    $maxFiles = 5;
    $maxFileSize = 10 * 1024 * 1024;
    $maxTotalSize = $maxFiles * $maxFileSize;
    $totalSize = 0;
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);

    if (array_diff(array_keys($uploadedFiles), array_keys($categories)) !== []) {
        $result['error'] = 'The uploaded file category is invalid.';
        return $result;
    }

    foreach ($categories as $fieldName => $category) {
        $group = $uploadedFiles[$fieldName] ?? [];
        if ($group === []) {
            continue;
        }

        if (!is_array($group) || !isset($group['name'], $group['error']) || !is_array($group['name']) || !is_array($group['error'])) {
            $result['error'] = 'The uploaded file data is invalid.';
            return $result;
        }

        foreach ($group['name'] as $index => $submittedName) {
            $error = $group['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                $result['error'] = match ($error) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Each attachment must be 10 MB or smaller.',
                    UPLOAD_ERR_PARTIAL => 'An attachment was only partially uploaded. Please try again.',
                    UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not save an attachment.',
                    default => 'An attachment could not be uploaded. Please try again.'
                };
                return $result;
            }

            if (!is_string($submittedName) || count($result['files']) >= $maxFiles) {
                $result['error'] = 'You can attach up to 5 files in total to a complaint.';
                return $result;
            }

            $temporaryPath = $group['tmp_name'][$index] ?? '';
            if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
                $result['error'] = 'The uploaded file could not be verified.';
                return $result;
            }

            $size = filesize($temporaryPath);
            if ($size === false || $size < 1 || $size > $maxFileSize) {
                $result['error'] = 'Each attachment must be between 1 byte and 10 MB.';
                return $result;
            }

            $totalSize += $size;
            if ($totalSize > $maxTotalSize) {
                $result['error'] = 'The combined attachment size cannot exceed 50 MB.';
                return $result;
            }

            $mimeType = $fileInfo->file($temporaryPath);
            $extension = strtolower(pathinfo($submittedName, PATHINFO_EXTENSION));
            if (
                !is_string($mimeType)
                || !isset($category['types'][$mimeType])
                || !in_array($extension, $category['types'][$mimeType], true)
            ) {
                $result['error'] = 'Choose a file type that matches its Images, Documents, or Videos section.';
                return $result;
            }

            $originalName = basename(str_replace('\\', '/', $submittedName));
            $originalName = preg_replace('/[\x00-\x1F\x7F]/', '', $originalName) ?? '';
            if ($originalName === '') {
                $result['error'] = 'An attachment must have a valid file name.';
                return $result;
            }

            $result['files'][] = [
                'category' => $category['label'],
                'temporary_path' => $temporaryPath,
                'original_name' => substr($originalName, 0, 512),
                'mime_type' => $mimeType,
                'extension' => $extension,
                'size' => $size
            ];
        }
    }

    return $result;
}

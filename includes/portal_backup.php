<?php
require_once __DIR__ . '/complaint_uploads.php';

function portalBackupTables(): array
{
    return [
        'users',
        'dealers',
        'schema_migrations',
        'system_settings',
        'products',
        'complaints',
        'complaint_department_assignments',
        'complaint_workflow',
        'complaint_material_events',
        'complaint_attachments',
        'complaint_drafts',
        'complaint_draft_attachments',
        'legacy_complaint_data',
        'admin_audit_log',
        'notifications',
        'user_sessions',
        'login_attempts'
    ];
}

function portalBackupV1Tables(): array
{
    return [
        'users',
        'schema_migrations',
        'system_settings',
        'products',
        'complaints',
        'complaint_department_assignments',
        'complaint_attachments',
        'legacy_complaint_data',
        'admin_audit_log',
        'notifications',
        'user_sessions',
        'login_attempts'
    ];
}

function portalBackupAttachmentPattern(): string
{
    return '/\A[a-f0-9]{32}\.(jpg|jpeg|png|webp|pdf|docx|xlsx|mp4|webm|zip|ogg)\z/';
}

function portalBackupDirectory(): string
{
    $directory = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'farmer-portal-private' . DIRECTORY_SEPARATOR . 'backups';
    $projectRoot = realpath(dirname(__DIR__));
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The private backup directory could not be created.');
    }

    $resolvedDirectory = realpath($directory);
    if (
        $resolvedDirectory === false
        || !is_writable($resolvedDirectory)
        || ($projectRoot !== false && str_starts_with(
            strtolower($resolvedDirectory . DIRECTORY_SEPARATOR),
            strtolower($projectRoot . DIRECTORY_SEPARATOR)
        ))
    ) {
        throw new RuntimeException('Backups must be stored in a writable directory outside the web project.');
    }

    return $resolvedDirectory;
}

function createPortalBackup(mysqli $conn, string $purpose = 'backup'): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Portal backup and restore require PHP ZIP support.');
    }
    if (!in_array($purpose, ['backup', 'pre-restore'], true)) {
        throw new InvalidArgumentException('Choose a valid backup purpose.');
    }

    $directory = portalBackupDirectory();
    $suffix = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $filename = 'farmer-portal-' . $purpose . '-' . $suffix . '.zip';
    $temporaryPath = $directory . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(12));
    $finalPath = $directory . DIRECTORY_SEPARATOR . $filename;
    $archive = new ZipArchive();
    $archiveOpened = false;
    $snapshotStarted = false;

    try {
        $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
        $snapshotStarted = true;
        if ($archive->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('A backup archive could not be created.');
        }
        $archiveOpened = true;
        $tables = [];
        foreach (portalBackupTables() as $table) {
            $columns = $conn->query("SHOW COLUMNS FROM `{$table}`")->fetch_all(MYSQLI_ASSOC);
            $columnNames = array_column($columns, 'Field');
            $rows = $conn->query("SELECT * FROM `{$table}`")->fetch_all(MYSQLI_ASSOC);
            $tables[$table] = ['columns' => $columnNames, 'rows' => $rows];
        }

        $manifest = [
            'format' => 'farmer-portal-backup',
            'version' => 2,
            'created_at' => date(DATE_ATOM),
            'tables' => $tables
        ];
        $manifestJson = json_encode(
            $manifest,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        if (!$archive->addFromString('backup.json', $manifestJson)) {
            throw new RuntimeException('The database snapshot could not be added to the backup.');
        }

        $attachments = array_merge(
            $tables['complaint_attachments']['rows'],
            $tables['complaint_draft_attachments']['rows']
        );
        $attachmentBytes = 0;
        foreach ($attachments as $attachment) {
            $storedName = $attachment['stored_name'] ?? null;
            if (!is_string($storedName) || !preg_match(portalBackupAttachmentPattern(), $storedName)) {
                throw new RuntimeException('A complaint attachment has an invalid stored filename; backup stopped.');
            }
            $filePath = isset($attachment['complaint_id'])
                ? complaintAttachmentFilePath((int) $attachment['complaint_id'], $storedName)
                : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
                    . 'complaints' . DIRECTORY_SEPARATOR . $storedName;
            $fileSize = is_file($filePath) ? filesize($filePath) : false;
            if (
                $fileSize === false
                || $fileSize !== (int) $attachment['file_size']
                || $fileSize > 100 * 1024 * 1024
            ) {
                throw new RuntimeException('A complaint attachment is missing or does not match its saved size; backup stopped.');
            }
            $attachmentBytes += $fileSize;
            if ($attachmentBytes > 2 * 1024 * 1024 * 1024) {
                throw new RuntimeException('Complaint attachments exceed the 2 GB backup processing limit.');
            }
            if (!$archive->addFile($filePath, 'attachments/' . $storedName)) {
                throw new RuntimeException('A complaint attachment is missing or could not be added to the backup.');
            }
        }

        if (!$archive->close()) {
            $archiveOpened = false;
            throw new RuntimeException('The backup archive could not be finalized.');
        }
        $archiveOpened = false;
        $archiveSize = filesize($temporaryPath);
        if ($archiveSize === false || $archiveSize > 1024 * 1024 * 1024) {
            throw new RuntimeException('The completed backup exceeds the 1 GB restore upload limit.');
        }
        $conn->commit();
        $snapshotStarted = false;
        if (!rename($temporaryPath, $finalPath)) {
            throw new RuntimeException('The backup archive could not be saved in private storage.');
        }
    } finally {
        if ($archiveOpened) {
            $archive->close();
        }
        if ($snapshotStarted) {
            $conn->rollback();
        }
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }

    return [
        'filename' => $filename,
        'path' => $finalPath,
        'size' => (int) filesize($finalPath),
        'created_at' => date(DATE_ATOM)
    ];
}

function listPortalBackups(): array
{
    $directory = portalBackupDirectory();
    $files = scandir($directory);
    if ($files === false) {
        throw new RuntimeException('The private backup directory could not be listed.');
    }

    $backups = [];
    foreach ($files as $filename) {
        if (!preg_match('/\Afarmer-portal-(backup|pre-restore)-\d{8}-\d{6}-[a-f0-9]{8}\.zip\z/', $filename)) {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            continue;
        }
        $backups[] = [
            'filename' => $filename,
            'size' => (int) filesize($path),
            'modified_at' => date(DATE_ATOM, (int) filemtime($path))
        ];
    }
    usort($backups, static fn ($left, $right) => strcmp($right['modified_at'], $left['modified_at']));

    return $backups;
}

function readPortalBackupManifest(mysqli $conn, string $filePath, int $currentAdminId): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Portal backup and restore require PHP ZIP support.');
    }
    if (!is_file($filePath) || filesize($filePath) < 1 || filesize($filePath) > 1024 * 1024 * 1024) {
        throw new RuntimeException('Upload a valid portal backup up to 1 GB.');
    }

    $archive = new ZipArchive();
    if ($archive->open($filePath, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('The uploaded file is not a valid portal backup archive.');
    }
    try {
        if ($archive->numFiles < 1 || $archive->numFiles > 10000) {
            throw new RuntimeException('The backup archive has an invalid number of entries.');
        }
        $totalUncompressedSize = 0;
        $archiveAttachments = [];
        $manifestFound = false;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);
            $entryName = (string) ($stat['name'] ?? '');
            $totalUncompressedSize += (int) ($stat['size'] ?? 0);
            if ($totalUncompressedSize > 2 * 1024 * 1024 * 1024) {
                throw new RuntimeException('The uncompressed backup exceeds the 2 GB processing limit.');
            }
            if ($entryName === 'backup.json') {
                if ($manifestFound) {
                    throw new RuntimeException('The backup contains duplicate database snapshots.');
                }
                $manifestFound = true;
                continue;
            }
            if (preg_match('/\Aattachments\/([a-f0-9]{32}\.(?:jpg|jpeg|png|webp|pdf|docx|xlsx|mp4|webm|zip|ogg))\z/', $entryName, $matches)) {
                if (isset($archiveAttachments[$matches[1]])) {
                    throw new RuntimeException('The backup contains duplicate complaint attachment files.');
                }
                $archiveAttachments[$matches[1]] = true;
                continue;
            }
            throw new RuntimeException('The backup contains an unsupported or unsafe archive path.');
        }

        $manifestIndex = $archive->locateName('backup.json');
        if ($manifestIndex === false) {
            throw new RuntimeException('The backup is missing its database snapshot.');
        }
        $manifestStat = $archive->statIndex($manifestIndex);
        if ((int) ($manifestStat['size'] ?? 0) > 256 * 1024 * 1024) {
            throw new RuntimeException('The database snapshot is too large to restore.');
        }
        $manifestContents = $archive->getFromIndex($manifestIndex);
        if ($manifestContents === false) {
            throw new RuntimeException('The database snapshot could not be read.');
        }
        try {
            $manifest = json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The database snapshot contains invalid JSON.', 0, $exception);
        }

        if (
            !is_array($manifest)
            || ($manifest['format'] ?? '') !== 'farmer-portal-backup'
            || !in_array($manifest['version'] ?? null, [1, 2], true)
            || !is_array($manifest['tables'] ?? null)
        ) {
            throw new RuntimeException('This backup format or database table set is not supported by this portal version.');
        }

        $expectedTables = $manifest['version'] === 1 ? portalBackupV1Tables() : portalBackupTables();
        if (array_keys($manifest['tables']) !== $expectedTables) {
            throw new RuntimeException('This backup format or database table set is not supported by this portal version.');
        }

        foreach ($expectedTables as $table) {
            $tableData = $manifest['tables'][$table];
            if (!is_array($tableData) || !is_array($tableData['columns'] ?? null) || !is_array($tableData['rows'] ?? null)) {
                throw new RuntimeException('The backup database table data is incomplete.');
            }
            $columns = $conn->query("SHOW COLUMNS FROM `{$table}`")->fetch_all(MYSQLI_ASSOC);
            $expectedColumns = array_column($columns, 'Field');
            if ($table === 'complaints') {
                if ($manifest['version'] === 1) {
                    $expectedColumns = array_values(array_filter(
                        $expectedColumns,
                        static fn ($column) => $column !== 'dealer_id'
                    ));
                }
                if ($manifest['version'] <= 2) {
                    $expectedColumns = array_values(array_filter(
                        $expectedColumns,
                        static fn ($column) => $column !== 'farmer_mobile'
                    ));
                }
            }
            if ($tableData['columns'] !== $expectedColumns) {
                throw new RuntimeException('The backup schema does not match the current portal database.');
            }
            foreach ($tableData['rows'] as $row) {
                if (!is_array($row) || array_is_list($row) || array_keys($row) !== $tableData['columns']) {
                    throw new RuntimeException('The backup contains an invalid row for ' . $table . '.');
                }
                foreach ($row as $value) {
                    if (!is_null($value) && !is_scalar($value)) {
                        throw new RuntimeException('The backup contains an unsupported database value.');
                    }
                }
            }
        }

        $currentAdminFound = false;
        foreach ($manifest['tables']['users']['rows'] as $user) {
            if (
                (int) $user['id'] === $currentAdminId
                && $user['role'] === 'admin'
                && (int) $user['is_active'] === 1
            ) {
                $currentAdminFound = true;
                break;
            }
        }
        if (!$currentAdminFound) {
            throw new RuntimeException('The backup must contain the currently signed-in active administrator account.');
        }

        $databaseAttachments = [];
        $attachmentRows = array_merge(
            $manifest['tables']['complaint_attachments']['rows'],
            $manifest['tables']['complaint_draft_attachments']['rows'] ?? []
        );
        foreach ($attachmentRows as $attachment) {
            $storedName = $attachment['stored_name'];
            if (!is_string($storedName) || !preg_match(portalBackupAttachmentPattern(), $storedName)) {
                throw new RuntimeException('The backup references an invalid complaint attachment.');
            }
            if (isset($databaseAttachments[$storedName])) {
                throw new RuntimeException('The backup contains duplicate complaint attachment records.');
            }
            $databaseAttachments[$storedName] = true;
        }
        if (array_diff_key($databaseAttachments, $archiveAttachments) !== []
            || array_diff_key($archiveAttachments, $databaseAttachments) !== []) {
            throw new RuntimeException('The backup attachment files do not match its database records.');
        }

        return $manifest;
    } finally {
        $archive->close();
    }
}

function insertPortalBackupRows(mysqli $conn, string $table, array $columns, array $rows): void
{
    if ($rows === []) {
        return;
    }
    $quotedColumns = implode(', ', array_map(static fn ($column) => '`' . $column . '`', $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $statement = $conn->prepare("INSERT INTO `{$table}` ({$quotedColumns}) VALUES ({$placeholders})");
    $parameters = array_merge([str_repeat('s', count($columns))], array_fill(0, count($columns), null));
    $references = [];
    foreach ($parameters as $key => &$parameter) {
        $references[$key] = &$parameter;
    }
    unset($parameter);
    call_user_func_array([$statement, 'bind_param'], $references);

    foreach ($rows as $row) {
        foreach ($columns as $index => $column) {
            $parameters[$index + 1] = $row[$column];
        }
        $statement->execute();
    }
}

function removePortalAttachmentDirectory(string $directory): bool
{
    if (!is_dir($directory)) {
        return true;
    }
    $entries = scandir($directory);
    if ($entries === false) {
        return false;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if ($entry === '.htaccess') {
            $accessRules = is_file($path) ? file_get_contents($path) : false;
            if (
                !is_string($accessRules)
                || str_replace(["\r\n", "\r"], "\n", $accessRules) !== complaintStorageAccessRules()
            ) {
                return false;
            }
            if (!is_file($path) || !unlink($path)) {
                return false;
            }
        } elseif (preg_match(portalBackupAttachmentPattern(), $entry)) {
            if (!is_file($path) || is_link($path) || !unlink($path)) {
                return false;
            }
        } elseif (ctype_digit($entry) && is_dir($path) && !is_link($path)) {
            $complaintEntries = scandir($path);
            if ($complaintEntries === false) {
                return false;
            }
            foreach ($complaintEntries as $complaintEntry) {
                if ($complaintEntry === '.' || $complaintEntry === '..') {
                    continue;
                }
                $attachmentPath = $path . DIRECTORY_SEPARATOR . $complaintEntry;
                if (
                    !preg_match(portalBackupAttachmentPattern(), $complaintEntry)
                    || !is_file($attachmentPath)
                    || is_link($attachmentPath)
                    || !unlink($attachmentPath)
                ) {
                    return false;
                }
            }
            if (!rmdir($path)) {
                return false;
            }
        } else {
            return false;
        }
    }

    return rmdir($directory);
}

function restorePortalBackup(mysqli $conn, string $filePath, int $currentAdminId, string $sessionHash): array
{
    $manifest = readPortalBackupManifest($conn, $filePath, $currentAdminId);
    $safetyBackup = createPortalBackup($conn, 'pre-restore');
    $storageDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    $currentAttachmentDirectory = $storageDirectory . DIRECTORY_SEPARATOR . 'complaints';
    if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0700, true) && !is_dir($storageDirectory)) {
        throw new RuntimeException('The private attachment storage directory is unavailable.');
    }

    $restoreId = bin2hex(random_bytes(8));
    $stagingDirectory = $storageDirectory . DIRECTORY_SEPARATOR . '.restore-stage-' . $restoreId;
    $rollbackDirectory = $storageDirectory . DIRECTORY_SEPARATOR . '.restore-rollback-' . $restoreId;
    if (!mkdir($stagingDirectory, 0700)) {
        throw new RuntimeException('A temporary restore directory could not be created.');
    }
    $accessRulesPath = $stagingDirectory . DIRECTORY_SEPARATOR . '.htaccess';
    if (file_put_contents($accessRulesPath, complaintStorageAccessRules(), LOCK_EX) === false) {
        if (is_file($accessRulesPath)) {
            unlink($accessRulesPath);
        }
        rmdir($stagingDirectory);
        throw new RuntimeException('Private attachment access rules could not be staged for restore.');
    }
    chmod($accessRulesPath, 0600);

    $archive = new ZipArchive();
    $archiveOpened = false;
    $transactionStarted = false;
    $filesSwapped = false;
    $hadOldAttachments = false;
    try {
        if ($archive->open($filePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The backup archive could not be reopened for restore.');
        }
        $archiveOpened = true;
        $draftAttachmentRows = $manifest['tables']['complaint_draft_attachments']['rows'] ?? [];
        foreach (array_merge($manifest['tables']['complaint_attachments']['rows'], $draftAttachmentRows) as $attachment) {
            $storedName = $attachment['stored_name'];
            $source = $archive->getStream('attachments/' . $storedName);
            $targetDirectory = $stagingDirectory;
            if (isset($attachment['complaint_id'])) {
                $complaintId = filter_var($attachment['complaint_id'], FILTER_VALIDATE_INT);
                if (!is_int($complaintId) || $complaintId < 1) {
                    if (is_resource($source)) {
                        fclose($source);
                    }
                    throw new RuntimeException('The backup contains an invalid complaint attachment folder reference.');
                }
                $targetDirectory .= DIRECTORY_SEPARATOR . $complaintId;
                if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0700)) {
                    if (is_resource($source)) {
                        fclose($source);
                    }
                    throw new RuntimeException('A complaint attachment folder could not be staged for restore.');
                }
            }
            $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $storedName;
            $target = fopen($targetPath, 'xb');
            if ($source === false || $target === false) {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($target)) {
                    fclose($target);
                }
                throw new RuntimeException('A complaint attachment could not be staged for restore.');
            }
            $copiedBytes = stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);
            if (
                $copiedBytes === false
                || $copiedBytes !== filesize($targetPath)
                || $copiedBytes !== (int) $attachment['file_size']
                || $copiedBytes > 100 * 1024 * 1024
            ) {
                throw new RuntimeException('A complaint attachment could not be fully staged for restore.');
            }
        }
        $archive->close();
        $archiveOpened = false;

        $conn->begin_transaction();
        $transactionStarted = true;
        foreach ([
            'login_attempts',
            'user_sessions',
            'notifications',
            'admin_audit_log',
            'legacy_complaint_data',
            'complaint_draft_attachments',
            'complaint_drafts',
            'complaint_attachments',
            'complaint_material_events',
            'complaint_workflow',
            'complaint_department_assignments',
            'complaints',
            'system_settings',
            'products',
            'schema_migrations',
            'dealers',
            'users'
        ] as $table) {
            $conn->query("DELETE FROM `{$table}`");
        }

        foreach ([
            'users',
            'dealers',
            'schema_migrations',
            'products',
            'complaints',
            'complaint_department_assignments',
            'complaint_workflow',
            'complaint_material_events',
            'complaint_attachments',
            'complaint_drafts',
            'complaint_draft_attachments',
            'legacy_complaint_data',
            'system_settings',
            'admin_audit_log',
            'notifications'
        ] as $table) {
            if (!isset($manifest['tables'][$table])) {
                continue;
            }
            $tableData = $manifest['tables'][$table];
            insertPortalBackupRows($conn, $table, $tableData['columns'], $tableData['rows']);
        }

        if (is_dir($currentAttachmentDirectory)) {
            if (!rename($currentAttachmentDirectory, $rollbackDirectory)) {
                throw new RuntimeException('Existing attachments could not be moved aside safely.');
            }
            $hadOldAttachments = true;
        }
        if (!rename($stagingDirectory, $currentAttachmentDirectory)) {
            if ($hadOldAttachments) {
                if (rename($rollbackDirectory, $currentAttachmentDirectory)) {
                    $hadOldAttachments = false;
                }
            }
            throw new RuntimeException('Restored attachments could not be moved into private storage.');
        }
        $filesSwapped = true;

        $currentUserInBackup = null;
        foreach ($manifest['tables']['users']['rows'] as $user) {
            if ((int) $user['id'] === $currentAdminId) {
                $currentUserInBackup = $user;
                break;
            }
        }
        if (!$currentUserInBackup) {
            throw new RuntimeException('The administrator account disappeared from the validated backup.');
        }
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($ipAddress) || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            $ipAddress = null;
        }
        $userAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null)
            ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255, 'UTF-8')
            : null;
        $expiresAt = date('Y-m-d H:i:s', time() + 12 * 60 * 60);
        $sessionStatement = $conn->prepare(
            'INSERT INTO user_sessions (user_id, session_hash, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $sessionStatement->bind_param('issss', $currentAdminId, $sessionHash, $ipAddress, $userAgent, $expiresAt);
        $sessionStatement->execute();

        $conn->commit();
        $transactionStarted = false;
        if ($archiveOpened) {
            $archive->close();
            $archiveOpened = false;
        }
        if ($hadOldAttachments && !removePortalAttachmentDirectory($rollbackDirectory)) {
            error_log('Portal restore completed but the private rollback attachment folder could not be removed.');
        }

        return [
            'safety_backup' => $safetyBackup,
            'session_version' => (int) $currentUserInBackup['session_version']
        ];
    } catch (Throwable $exception) {
        if ($archiveOpened) {
            $archive->close();
        }
        if ($transactionStarted) {
            $conn->rollback();
        }
        if ($filesSwapped && is_dir($currentAttachmentDirectory)) {
            if (!rename($currentAttachmentDirectory, $stagingDirectory)) {
                error_log('Portal restore failed and the new attachment folder could not be moved aside.');
            }
        }
        if ($hadOldAttachments && is_dir($rollbackDirectory) && !is_dir($currentAttachmentDirectory)) {
            if (!rename($rollbackDirectory, $currentAttachmentDirectory)) {
                error_log('Portal restore failed and the previous attachment folder could not be restored.');
            }
        }
        if (is_dir($stagingDirectory) && !removePortalAttachmentDirectory($stagingDirectory)) {
            error_log('Portal restore failed and its temporary attachment folder could not be removed.');
        }
        throw $exception;
    }
}

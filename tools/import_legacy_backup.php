<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

if (!in_array($argc, [4, 5], true) || ($argc === 5 && $argv[4] !== '--dry-run')) {
    fwrite(STDERR, "Usage: php import_legacy_backup.php <backup.zip> <admin-name> <admin-mobile> [--dry-run]\n");
    exit(2);
}

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "PHP ZipArchive is unavailable. Enable the PHP zip extension and retry.\n");
    exit(2);
}

set_time_limit(0);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/complaint_uploads.php';

function legacyValue(array $record, string $key): ?string
{
    $value = $record[$key] ?? null;
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return null;
    }

    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function legacyLimit(?string $value, int $maxCharacters): ?string
{
    if ($value === null) {
        return null;
    }

    return mb_substr($value, 0, $maxCharacters, 'UTF-8');
}

function legacyDate(?string $value, string $format): ?string
{
    if ($value === null) {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->format($format);
    } catch (Throwable) {
        return null;
    }
}

function legacyQuantity(array $record): ?string
{
    $value = legacyValue($record, 'qty');
    if ($value === null || !preg_match('/\A\d{1,9}(?:\.\d{1,3})?\z/', $value)) {
        return null;
    }

    return $value;
}

function bindValues(mysqli_stmt $statement, array $values): void
{
    $parameters = array_merge(['i' . str_repeat('s', count($values) - 1)], $values);
    $references = [];
    foreach ($parameters as $key => &$parameter) {
        $references[$key] = &$parameter;
    }
    unset($parameter);
    call_user_func_array([$statement, 'bind_param'], $references);
}

function archiveText(ZipArchive $archive, string $entryName): string
{
    $stream = $archive->getStream($entryName);
    if ($stream === false) {
        throw new RuntimeException("Unable to read {$entryName} from the backup.");
    }

    try {
        $contents = stream_get_contents($stream);
        if ($contents === false) {
            throw new RuntimeException("Unable to read {$entryName} from the backup.");
        }
        return $contents;
    } finally {
        fclose($stream);
    }
}

$backupPath = realpath($argv[1]);
$adminName = trim($argv[2]);
$adminMobile = trim($argv[3]);
$dryRun = $argc === 5;
if ($backupPath === false || !is_file($backupPath) || $adminName === '' || strlen($adminName) > 100) {
    fwrite(STDERR, "Provide an existing ZIP file and a valid Admin name.\n");
    exit(2);
}
if (!preg_match('/\A[0-9+() -]{6,15}\z/', $adminMobile)) {
    fwrite(STDERR, "The Admin mobile must contain 6 to 15 digits, spaces, or phone punctuation.\n");
    exit(2);
}

$archive = new ZipArchive();
$openResult = $archive->open($backupPath, ZipArchive::RDONLY);
if ($openResult !== true) {
    throw new RuntimeException('The backup ZIP could not be opened.');
}

$savedPaths = [];
$transactionStarted = false;
try {
    $complaintsJson = json_decode(archiveText($archive, 'complaints_db.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($complaintsJson) || $complaintsJson === []) {
        throw new RuntimeException('The backup does not contain a valid complaints list.');
    }

    $recordsBySourceId = [];
    foreach ($complaintsJson as $record) {
        if (!is_array($record)) {
            throw new RuntimeException('A legacy complaint record has an invalid shape.');
        }
        $sourceId = legacyValue($record, 'id');
        if ($sourceId === null || strlen($sourceId) > 100 || isset($recordsBySourceId[$sourceId])) {
            throw new RuntimeException('Legacy complaint IDs must be present and unique.');
        }
        $recordsBySourceId[$sourceId] = $record;
    }

    $uploadEntries = [];
    for ($index = 0; $index < $archive->numFiles; $index++) {
        $entryName = $archive->getNameIndex($index);
        if (!is_string($entryName) || !str_starts_with($entryName, 'uploads/') || str_ends_with($entryName, '/')) {
            continue;
        }

        if (!preg_match('#\Auploads/([^/]+)/([^/]+)\z#', $entryName, $matches)) {
            throw new RuntimeException('The backup contains an unexpected upload path.');
        }
        $sourceId = $matches[1];
        $originalName = $matches[2];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (
            !isset($recordsBySourceId[$sourceId])
            || !in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'docx', 'xlsx', 'mp4', 'webm', 'zip', 'ogg'], true)
        ) {
            throw new RuntimeException("An upload does not match a complaint or supported file type: {$entryName}");
        }
        $uploadEntries[] = [
            'entry_name' => $entryName,
            'source_id' => $sourceId,
            'original_name' => $originalName,
            'extension' => $extension,
            'size' => (int) $archive->statName($entryName)['size']
        ];
    }

    $existingImport = $conn->query('SELECT COUNT(*) AS total FROM legacy_complaint_data')->fetch_assoc();
    if ((int) $existingImport['total'] > 0) {
        throw new RuntimeException('Legacy complaint data already exists; refusing to import duplicate records.');
    }

    $mobileStatement = $conn->prepare('SELECT id FROM users WHERE mobile = ? LIMIT 1');
    $mobileStatement->bind_param('s', $adminMobile);
    $mobileStatement->execute();
    if ($mobileStatement->get_result()->num_rows > 0) {
        throw new RuntimeException('That mobile number already belongs to a portal account; no data was imported.');
    }

    if ($dryRun) {
        fwrite(STDOUT, "Preflight passed; no database or file changes were made.\n");
        fwrite(STDOUT, 'Admin mobile is available: ' . $adminMobile . "\n");
        fwrite(STDOUT, 'Complaints ready: ' . count($recordsBySourceId) . "\n");
        fwrite(STDOUT, 'Attachments ready: ' . count($uploadEntries) . "\n");
        $archive->close();
        exit(0);
    }

    $storageDirectory = ensurePrivateComplaintStorage();

    $temporaryPassword = bin2hex(random_bytes(16));
    $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    $conn->begin_transaction();
    $transactionStarted = true;

    $adminStatement = $conn->prepare(
        "INSERT INTO users (role, full_name, mobile, password)
         VALUES ('admin', ?, ?, ?)"
    );
    $adminStatement->bind_param('sss', $adminName, $adminMobile, $passwordHash);
    $adminStatement->execute();
    $adminId = (int) $conn->insert_id;

    $complaintStatement = $conn->prepare(
        "INSERT INTO complaints
            (created_by, title, farmer_name, village, taluka, complaint_type,
             product, supply_date, quantity_supplied, quantity_unit,
             invoice_reference, district, department, details, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $legacyStatement = $conn->prepare(
        "INSERT INTO legacy_complaint_data
            (complaint_id, source_id, dealer_name, area_manager_name, created_by_name, original_json)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $complaintIdBySourceId = [];

    foreach ($recordsBySourceId as $sourceId => $record) {
        $title = legacyLimit('Legacy complaint ' . $sourceId, 180);
        $farmerName = legacyLimit(legacyValue($record, 'farmerName'), 100);
        $village = legacyLimit(legacyValue($record, 'village'), 100);
        $taluka = legacyLimit(legacyValue($record, 'taluka'), 100);
        $complaintType = legacyLimit(legacyValue($record, 'complainType') ?? 'Other', 150);
        $product = legacyLimit(
            legacyValue($record, 'materialName')
                ?? legacyValue($record, 'wrongMaterialName')
                ?? 'Legacy product',
            300
        );
        $supplyDate = legacyDate(legacyValue($record, 'supplyDate'), 'Y-m-d');
        $quantity = legacyLimit(legacyQuantity($record), 20);
        $quantityUnit = legacyLimit(legacyValue($record, 'qtyUnit'), 30);
        $invoiceReference = legacyLimit(
            legacyValue($record, 'originalInvoiceNo') ?? legacyValue($record, 'soNumber'),
            100
        );
        $district = legacyLimit(legacyValue($record, 'district') ?? 'Unknown', 50);
        $department = legacyLimit(legacyValue($record, 'forwardedDept') ?? 'Legacy', 100);
        $details = legacyLimit(
            legacyValue($record, 'detail')
                ?? "Imported from legacy complaint {$sourceId}. Refer to the original record for its full history.",
            60000
        );
        $sourceStatus = legacyValue($record, 'status') ?? 'Pending';
        $status = match (strtolower($sourceStatus)) {
            'open', 'pending' => 'Pending',
            'close', 'closed' => 'Closed',
            default => legacyLimit($sourceStatus, 30)
        };
        $createdAt = legacyDate(legacyValue($record, 'createdAt'), 'Y-m-d H:i:s');

        $complaintValues = [
            $adminId,
            $title,
            $farmerName,
            $village,
            $taluka,
            $complaintType,
            $product,
            $supplyDate,
            $quantity,
            $quantityUnit,
            $invoiceReference,
            $district,
            $department,
            $details,
            $status,
            $createdAt
        ];
        bindValues($complaintStatement, $complaintValues);
        $complaintStatement->execute();
        $complaintId = (int) $conn->insert_id;
        $complaintIdBySourceId[$sourceId] = $complaintId;

        $originalJson = json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($originalJson)) {
            throw new RuntimeException("Unable to preserve original complaint {$sourceId}.");
        }
        $dealerName = legacyValue($record, 'dealerName');
        $areaManagerName = legacyValue($record, 'areaManagerName');
        $createdByName = legacyValue($record, 'createdByName');
        $legacyStatement->bind_param(
            'isssss',
            $complaintId,
            $sourceId,
            $dealerName,
            $areaManagerName,
            $createdByName,
            $originalJson
        );
        $legacyStatement->execute();

        if (count($complaintIdBySourceId) % 100 === 0) {
            fwrite(STDOUT, 'Imported ' . count($complaintIdBySourceId) . '/' . count($recordsBySourceId) . " complaints\n");
        }
    }

    $attachmentStatement = $conn->prepare(
        "INSERT INTO complaint_attachments
            (complaint_id, category, stored_name, original_name, mime_type, file_size)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $importedFileCount = 0;
    $importedFileBytes = 0;

    foreach ($uploadEntries as $entry) {
        $storedName = bin2hex(random_bytes(16)) . '.' . $entry['extension'];
        $destination = $storageDirectory . DIRECTORY_SEPARATOR . $storedName;
        $destinationStream = fopen($destination, 'xb');
        if ($destinationStream === false) {
            throw new RuntimeException('Unable to create a private attachment file.');
        }
        $savedPaths[] = $destination;

        $sourceStream = $archive->getStream($entry['entry_name']);
        if ($sourceStream === false) {
            fclose($destinationStream);
            throw new RuntimeException("Unable to read upload {$entry['entry_name']}.");
        }
        try {
            $copiedBytes = stream_copy_to_stream($sourceStream, $destinationStream);
        } finally {
            fclose($sourceStream);
            fclose($destinationStream);
        }
        if ($copiedBytes === false || $copiedBytes !== $entry['size']) {
            throw new RuntimeException("An upload was incomplete: {$entry['entry_name']}");
        }

        $mimeType = $fileInfo->file($destination);
        if (!is_string($mimeType)) {
            throw new RuntimeException("Unable to identify upload type: {$entry['entry_name']}");
        }
        $category = str_starts_with($mimeType, 'image/')
            ? 'Image'
            : (str_starts_with($mimeType, 'video/')
                ? 'Video'
                : (in_array($entry['extension'], ['pdf', 'docx', 'xlsx', 'zip'], true) ? 'Document' : 'Other'));
        $originalName = preg_replace('/[\x00-\x1F\x7F]/', '', basename($entry['original_name'])) ?? '';
        $originalName = mb_substr($originalName, 0, 512, 'UTF-8');
        if ($originalName === '') {
            throw new RuntimeException('An uploaded file has an invalid original name.');
        }

        $complaintId = $complaintIdBySourceId[$entry['source_id']];
        $complaintDirectory = ensureComplaintAttachmentStorage($complaintId);
        $destination = $complaintDirectory . DIRECTORY_SEPARATOR . $storedName;
        if (!rename($storageDirectory . DIRECTORY_SEPARATOR . $storedName, $destination)) {
            throw new RuntimeException('Unable to place an imported file in its complaint folder.');
        }
        $savedPaths[count($savedPaths) - 1] = $destination;
        $fileSize = (int) $copiedBytes;
        $attachmentStatement->bind_param(
            'issssi',
            $complaintId,
            $category,
            $storedName,
            $originalName,
            $mimeType,
            $fileSize
        );
        $attachmentStatement->execute();
        $importedFileCount++;
        $importedFileBytes += $fileSize;
    }

    $conn->commit();
    $transactionStarted = false;
    $archive->close();

    fwrite(STDOUT, "Import complete.\n");
    fwrite(STDOUT, 'Admin mobile: ' . $adminMobile . "\n");
    fwrite(STDOUT, 'Initial Admin password: ' . $temporaryPassword . "\n");
    fwrite(STDOUT, 'Complaints imported: ' . count($recordsBySourceId) . "\n");
    fwrite(STDOUT, 'Attachments imported: ' . $importedFileCount . "\n");
    fwrite(STDOUT, 'Attachment bytes copied: ' . $importedFileBytes . "\n");
} catch (Throwable $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    foreach ($savedPaths as $savedPath) {
        if (is_file($savedPath)) {
            unlink($savedPath);
        }
    }
    $archive->close();
    throw $exception;
}

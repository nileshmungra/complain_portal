<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = new mysqli("localhost", "root", "");
$conn->query("CREATE DATABASE IF NOT EXISTS farmer_complaint_db");
$conn->select_db("farmer_complaint_db");
$conn->set_charset("utf8mb4");

$conn->query(
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role VARCHAR(50) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        mobile VARCHAR(15) NOT NULL,
        firm_name VARCHAR(100) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        assigned_area VARCHAR(255) DEFAULT NULL,
        department VARCHAR(100) DEFAULT NULL,
        password VARCHAR(255) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        must_change_password TINYINT(1) NOT NULL DEFAULT 0,
        session_version INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS dealers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dealer_code VARCHAR(60) NOT NULL,
        name VARCHAR(200) NOT NULL,
        address TEXT DEFAULT NULL,
        village VARCHAR(100) DEFAULT NULL,
        pin_code VARCHAR(20) DEFAULT NULL,
        taluka VARCHAR(100) DEFAULT NULL,
        district VARCHAR(100) DEFAULT NULL,
        state VARCHAR(100) DEFAULT NULL,
        country VARCHAR(100) DEFAULT NULL,
        mobile VARCHAR(30) DEFAULT NULL,
        email VARCHAR(255) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT DEFAULT NULL,
        updated_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dealers_code (dealer_code),
        INDEX idx_dealers_name (name),
        INDEX idx_dealers_location (district, taluka),
        CONSTRAINT fk_dealers_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_dealers_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$passwordColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'password'");
if ($passwordColumn->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN password VARCHAR(255) DEFAULT NULL");
}

$userColumns = [
    'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'must_change_password' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'session_version' => 'INT UNSIGNED NOT NULL DEFAULT 0'
];
foreach ($userColumns as $column => $definition) {
    if ($conn->query("SHOW COLUMNS FROM users LIKE '{$column}'")->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN {$column} {$definition}");
    }
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS complaints (
        id INT AUTO_INCREMENT PRIMARY KEY,
        public_reference VARCHAR(32) DEFAULT NULL,
        created_by INT NOT NULL,
        dealer_id INT DEFAULT NULL,
        title VARCHAR(180) NOT NULL,
        farmer_name VARCHAR(100) DEFAULT NULL,
        farmer_mobile VARCHAR(15) DEFAULT NULL,
        state VARCHAR(100) DEFAULT NULL,
        village VARCHAR(100) DEFAULT NULL,
        taluka VARCHAR(100) DEFAULT NULL,
        complaint_type VARCHAR(150) NOT NULL,
        product VARCHAR(300) NOT NULL,
        supply_date DATE DEFAULT NULL,
        quantity_supplied DECIMAL(12,3) DEFAULT NULL,
        actual_product VARCHAR(300) DEFAULT NULL,
        actual_quantity_supplied DECIMAL(12,3) DEFAULT NULL,
        actual_quantity_unit VARCHAR(30) DEFAULT NULL,
        invoice_reference VARCHAR(100) DEFAULT NULL,
        wo_number VARCHAR(100) DEFAULT NULL,
        district VARCHAR(50) NOT NULL,
        department VARCHAR(100) NOT NULL,
        details TEXT NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'Pending',
        due_at DATETIME DEFAULT NULL,
        resolved_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_complaints_creator (created_by, created_at),
        UNIQUE KEY uq_complaints_public_reference (public_reference),
        INDEX idx_complaints_dealer (dealer_id),
        INDEX idx_complaints_farmer_mobile (farmer_mobile, created_at),
        INDEX idx_complaints_district (district, created_at),
        INDEX idx_complaints_department (department, created_at),
        INDEX idx_complaints_type (complaint_type),
        INDEX idx_complaints_product (product),
        CONSTRAINT fk_complaints_user
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_complaints_dealer
            FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS complaint_approvals (
        complaint_id INT PRIMARY KEY,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        decided_by INT DEFAULT NULL,
        decided_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_complaint_approvals_status_created (status, created_at),
        CONSTRAINT fk_complaint_approval_complaint
            FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
        CONSTRAINT fk_complaint_approval_decider
            FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$statusColumn = $conn->query("SHOW COLUMNS FROM complaints LIKE 'status'")->fetch_assoc();
if ($statusColumn && $statusColumn['Default'] !== 'Pending') {
    $conn->query("ALTER TABLE complaints MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'Pending'");
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(100) PRIMARY KEY,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$pendingStatusMigration = 'open-complaints-to-pending-v1';
$migrationStatement = $conn->prepare('SELECT migration_key FROM schema_migrations WHERE migration_key = ?');
$migrationStatement->bind_param('s', $pendingStatusMigration);
$migrationStatement->execute();
if ($migrationStatement->get_result()->num_rows === 0) {
    $conn->query("UPDATE complaints SET status = 'Pending' WHERE status = 'Open'");
    $migrationStatement = $conn->prepare('INSERT INTO schema_migrations (migration_key) VALUES (?)');
    $migrationStatement->bind_param('s', $pendingStatusMigration);
    $migrationStatement->execute();
}

$complaintColumns = [
    'dealer_id' => 'INT DEFAULT NULL',
    'farmer_name' => 'VARCHAR(100) DEFAULT NULL',
    'farmer_mobile' => 'VARCHAR(15) DEFAULT NULL',
    'state' => 'VARCHAR(100) DEFAULT NULL',
    'village' => 'VARCHAR(100) DEFAULT NULL',
    'taluka' => 'VARCHAR(100) DEFAULT NULL',
    'supply_date' => 'DATE DEFAULT NULL',
    'quantity_supplied' => 'DECIMAL(12,3) DEFAULT NULL',
    'quantity_unit' => 'VARCHAR(30) DEFAULT NULL',
    'actual_product' => 'VARCHAR(300) DEFAULT NULL',
    'actual_quantity_supplied' => 'DECIMAL(12,3) DEFAULT NULL',
    'actual_quantity_unit' => 'VARCHAR(30) DEFAULT NULL',
    'invoice_reference' => 'VARCHAR(100) DEFAULT NULL',
    'wo_number' => 'VARCHAR(100) DEFAULT NULL',
    'public_reference' => 'VARCHAR(32) DEFAULT NULL'
];

foreach ($complaintColumns as $column => $definition) {
    $columnResult = $conn->query("SHOW COLUMNS FROM complaints LIKE '{$column}'");
    if ($columnResult->num_rows === 0) {
        $conn->query("ALTER TABLE complaints ADD COLUMN {$column} {$definition}");
    }
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS location_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parent_id INT UNSIGNED NOT NULL DEFAULT 0,
        depth TINYINT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        UNIQUE KEY uq_location_parent_name (parent_id, name),
        INDEX idx_location_depth_name (depth, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$locationSeedMigration = 'seed-location-options-from-dealers-and-complaints-v2';
$locationSeedStatement = $conn->prepare('SELECT migration_key FROM schema_migrations WHERE migration_key = ?');
$locationSeedStatement->bind_param('s', $locationSeedMigration);
$locationSeedStatement->execute();
if ($locationSeedStatement->get_result()->num_rows === 0) {
    $conn->query(
        'UPDATE complaints c
         INNER JOIN dealers d ON d.id = c.dealer_id
         SET c.state = d.state
         WHERE (c.state IS NULL OR TRIM(c.state) = \'\')
           AND d.state IS NOT NULL AND TRIM(d.state) <> \'\''
    );
    $locationRows = $conn->query(
        "SELECT state, district, taluka, village FROM dealers
         WHERE state IS NOT NULL AND TRIM(state) <> ''
           AND district IS NOT NULL AND TRIM(district) <> ''
         UNION
         SELECT state, district, taluka, village FROM complaints
         WHERE state IS NOT NULL AND TRIM(state) <> ''
           AND district IS NOT NULL AND TRIM(district) <> ''"
    )->fetch_all(MYSQLI_ASSOC);
    $insertLocation = $conn->prepare(
        'INSERT INTO location_options (parent_id, depth, name) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    foreach ($locationRows as $locationRow) {
        $parentId = 0;
        foreach (['state', 'district', 'taluka', 'village'] as $depthIndex => $field) {
            $name = trim((string) ($locationRow[$field] ?? ''));
            if ($name === '') {
                break;
            }
            $depth = $depthIndex + 1;
            $insertLocation->bind_param('iis', $parentId, $depth, $name);
            $insertLocation->execute();
            $parentId = (int) $conn->insert_id;
        }
    }
    $locationSeedStatement = $conn->prepare('INSERT INTO schema_migrations (migration_key) VALUES (?)');
    $locationSeedStatement->bind_param('s', $locationSeedMigration);
    $locationSeedStatement->execute();
}

$publicReferenceIndex = $conn->query(
    "SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'complaints'
       AND INDEX_NAME = 'uq_complaints_public_reference'
     LIMIT 1"
);
if ($publicReferenceIndex->num_rows === 0) {
    $conn->query('ALTER TABLE complaints ADD UNIQUE INDEX uq_complaints_public_reference (public_reference)');
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS complaint_id_counters (
        state_code CHAR(3) PRIMARY KEY,
        last_sequence INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$farmerMobileIndex = $conn->query(
    "SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'complaints'
       AND INDEX_NAME = 'idx_complaints_farmer_mobile'
     LIMIT 1"
);
if ($farmerMobileIndex->num_rows === 0) {
    $conn->query('ALTER TABLE complaints ADD INDEX idx_complaints_farmer_mobile (farmer_mobile, created_at)');
}

$dealerConstraint = $conn->query(
    "SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_complaints_dealer'"
);
if ($dealerConstraint->num_rows === 0) {
    $conn->query(
        'ALTER TABLE complaints
         ADD CONSTRAINT fk_complaints_dealer
         FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE SET NULL'
    );
}

$workflowColumns = [
    'due_at' => 'DATETIME DEFAULT NULL',
    'resolved_at' => 'DATETIME DEFAULT NULL'
];
foreach ($workflowColumns as $column => $definition) {
    if ($conn->query("SHOW COLUMNS FROM complaints LIKE '{$column}'")->num_rows === 0) {
        $conn->query("ALTER TABLE complaints ADD COLUMN {$column} {$definition}");
    }
}

$complaintColumnSizes = [
    'complaint_type' => 150,
    'product' => 300
];
foreach ($complaintColumnSizes as $column => $size) {
    $columnResult = $conn->query("SHOW COLUMNS FROM complaints LIKE '{$column}'");
    $columnInfo = $columnResult->fetch_assoc();
    if ($columnInfo && preg_match('/\Avarchar\((\d+)\)/i', $columnInfo['Type'], $matches) && (int) $matches[1] < $size) {
        $conn->query("ALTER TABLE complaints MODIFY COLUMN {$column} VARCHAR({$size}) NOT NULL");
    }
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS complaint_material_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        complaint_id INT NOT NULL,
        event_type VARCHAR(30) NOT NULL,
        material VARCHAR(300) NOT NULL,
        quantity DECIMAL(12,3) NOT NULL,
        unit VARCHAR(30) NOT NULL,
        received_on DATE NOT NULL,
        received_from VARCHAR(150) NOT NULL,
        received_by VARCHAR(150) NOT NULL,
        remarks TEXT DEFAULT NULL,
        recorded_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_material_events_complaint (complaint_id, received_on, id),
        CONSTRAINT fk_material_events_complaint
            FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
        CONSTRAINT fk_material_events_user
            FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS complaint_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        complaint_id INT NOT NULL,
        category VARCHAR(20) NOT NULL DEFAULT 'Other',
        purpose VARCHAR(40) NOT NULL DEFAULT 'complaint',
        material_event_id INT DEFAULT NULL,
        uploaded_by INT DEFAULT NULL,
        stored_name VARCHAR(64) NOT NULL UNIQUE,
        original_name VARCHAR(512) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_attachments_complaint (complaint_id),
        INDEX idx_attachments_material_event (material_event_id),
        CONSTRAINT fk_attachments_complaint
            FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
        CONSTRAINT fk_attachments_uploader
            FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$attachmentPurposeColumn = $conn->query("SHOW COLUMNS FROM complaint_attachments LIKE 'purpose'");
if ($attachmentPurposeColumn->num_rows === 0) {
    $conn->query(
        "ALTER TABLE complaint_attachments
         ADD COLUMN purpose VARCHAR(40) NOT NULL DEFAULT 'complaint' AFTER category"
    );
} else {
    $purposeColumn = $attachmentPurposeColumn->fetch_assoc();
    if (
        is_array($purposeColumn)
        && preg_match('/\Avarchar\((\d+)\)/i', (string) $purposeColumn['Type'], $purposeTypeMatch)
        && (int) $purposeTypeMatch[1] < 40
    ) {
        $conn->query(
            "ALTER TABLE complaint_attachments
             MODIFY COLUMN purpose VARCHAR(40) NOT NULL DEFAULT 'complaint'"
        );
    }
}

$attachmentMaterialEventColumn = $conn->query("SHOW COLUMNS FROM complaint_attachments LIKE 'material_event_id'");
if ($attachmentMaterialEventColumn->num_rows === 0) {
    $conn->query(
        'ALTER TABLE complaint_attachments
         ADD COLUMN material_event_id INT DEFAULT NULL AFTER purpose'
    );
}

$attachmentUploaderColumn = $conn->query("SHOW COLUMNS FROM complaint_attachments LIKE 'uploaded_by'");
if ($attachmentUploaderColumn->num_rows === 0) {
    $conn->query(
        'ALTER TABLE complaint_attachments
         ADD COLUMN uploaded_by INT DEFAULT NULL AFTER material_event_id'
    );
}

$attachmentUploaderConstraint = $conn->query(
    "SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND CONSTRAINT_NAME = 'fk_attachments_uploader'
     LIMIT 1"
);
if ($attachmentUploaderConstraint->num_rows === 0) {
    $conn->query(
        'ALTER TABLE complaint_attachments
         ADD CONSTRAINT fk_attachments_uploader
         FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL'
    );
}

$attachmentMaterialEventIndex = $conn->query(
    "SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'complaint_attachments'
       AND INDEX_NAME = 'idx_attachments_material_event'
     LIMIT 1"
);
if ($attachmentMaterialEventIndex->num_rows === 0) {
    $conn->query('ALTER TABLE complaint_attachments ADD INDEX idx_attachments_material_event (material_event_id)');
}

$attachmentMaterialEventConstraint = $conn->query(
    "SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND CONSTRAINT_NAME = 'fk_attachments_material_event'
     LIMIT 1"
);
if ($attachmentMaterialEventConstraint->num_rows === 0) {
    $conn->query(
        'ALTER TABLE complaint_attachments
         ADD CONSTRAINT fk_attachments_material_event
         FOREIGN KEY (material_event_id) REFERENCES complaint_material_events(id) ON DELETE SET NULL'
    );
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS complaint_drafts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        form_data LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_complaint_drafts_user (user_id),
        CONSTRAINT fk_complaint_drafts_user
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS complaint_draft_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        draft_id INT NOT NULL,
        category VARCHAR(20) NOT NULL,
        stored_name VARCHAR(64) NOT NULL UNIQUE,
        original_name VARCHAR(512) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_draft_attachments_draft (draft_id),
        CONSTRAINT fk_draft_attachments_draft
            FOREIGN KEY (draft_id) REFERENCES complaint_drafts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS legacy_complaint_data (
        complaint_id INT PRIMARY KEY,
        source_id VARCHAR(100) NOT NULL UNIQUE,
        dealer_name TEXT DEFAULT NULL,
        area_manager_name TEXT DEFAULT NULL,
        created_by_name TEXT DEFAULT NULL,
        original_json LONGTEXT NOT NULL,
        imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_legacy_data_complaint
            FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$attachmentCategoryColumn = $conn->query("SHOW COLUMNS FROM complaint_attachments LIKE 'category'");
if ($attachmentCategoryColumn->num_rows === 0) {
    $conn->query(
        "ALTER TABLE complaint_attachments
         ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'Other' AFTER complaint_id"
    );
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS complaint_department_assignments (
        complaint_id INT NOT NULL,
        department VARCHAR(100) NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (complaint_id, department),
        INDEX idx_assignments_department (department, complaint_id),
        CONSTRAINT fk_assignments_complaint
            FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "INSERT IGNORE INTO complaint_department_assignments (complaint_id, department)
     SELECT id, department
     FROM complaints
     WHERE department IN ('Dispatch', 'Store', 'QC', 'Production')
       AND NOT EXISTS (
           SELECT 1
           FROM complaint_approvals ca
           WHERE ca.complaint_id = complaints.id AND ca.status <> 'Approved'
       )"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(300) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_products_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$productColumns = [
    'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
];
foreach ($productColumns as $column => $definition) {
    if ($conn->query("SHOW COLUMNS FROM products LIKE '{$column}'")->num_rows === 0) {
        $conn->query("ALTER TABLE products ADD COLUMN {$column} {$definition}");
    }
}

$productCatalogMigration = 'initial-product-catalog-v1';
$productMigrationStatement = $conn->prepare('SELECT migration_key FROM schema_migrations WHERE migration_key = ?');
$productMigrationStatement->bind_param('s', $productCatalogMigration);
$productMigrationStatement->execute();
if ($productMigrationStatement->get_result()->num_rows === 0) {
    $complaintOptions = require __DIR__ . '/complaints.php';
    $productInsertStatement = $conn->prepare('INSERT IGNORE INTO products (name) VALUES (?)');
    foreach ($complaintOptions['products'] as $productName) {
        $productInsertStatement->bind_param('s', $productName);
        $productInsertStatement->execute();
    }
    $productMigrationStatement = $conn->prepare('INSERT INTO schema_migrations (migration_key) VALUES (?)');
    $productMigrationStatement->bind_param('s', $productCatalogMigration);
    $productMigrationStatement->execute();
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_by INT DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_settings_user
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$conn->query(
    "INSERT IGNORE INTO system_settings (setting_key, setting_value)
     VALUES ('sla_business_days', '5')"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS admin_audit_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        actor_id INT DEFAULT NULL,
        action VARCHAR(80) NOT NULL,
        entity_type VARCHAR(60) NOT NULL,
        entity_id VARCHAR(100) DEFAULT NULL,
        summary VARCHAR(500) NOT NULL,
        metadata_json LONGTEXT DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_created (created_at),
        INDEX idx_audit_entity (entity_type, entity_id, created_at),
        INDEX idx_audit_actor (actor_id, created_at),
        CONSTRAINT fk_audit_actor
            FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        notification_type VARCHAR(40) NOT NULL,
        title VARCHAR(180) NOT NULL,
        message VARCHAR(500) NOT NULL,
        entity_type VARCHAR(60) DEFAULT NULL,
        entity_id VARCHAR(100) DEFAULT NULL,
        dedupe_key VARCHAR(191) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME DEFAULT NULL,
        UNIQUE KEY uq_notification_dedupe (dedupe_key),
        INDEX idx_notifications_user (user_id, read_at, created_at),
        CONSTRAINT fk_notification_user
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS user_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        session_hash CHAR(64) NOT NULL UNIQUE,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME DEFAULT NULL,
        revoked_by INT DEFAULT NULL,
        INDEX idx_user_sessions_user (user_id, revoked_at, expires_at),
        CONSTRAINT fk_session_user
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_session_revoker
            FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        identifier_hash CHAR(64) NOT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        succeeded TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_login_attempts_identifier (identifier_hash, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$workflowMigration = 'initial-complaint-sla-v1';
$workflowMigrationStatement = $conn->prepare('SELECT migration_key FROM schema_migrations WHERE migration_key = ?');
$workflowMigrationStatement->bind_param('s', $workflowMigration);
$workflowMigrationStatement->execute();
if ($workflowMigrationStatement->get_result()->num_rows === 0) {
    $complaintsToSchedule = $conn->query('SELECT id, created_at FROM complaints WHERE due_at IS NULL');
    $dueDateStatement = $conn->prepare('UPDATE complaints SET due_at = ? WHERE id = ?');
    while ($complaint = $complaintsToSchedule->fetch_assoc()) {
        $createdAt = $complaint['created_at'] ?? null;
        if (!is_string($createdAt) || $createdAt === '') {
            continue;
        }

        $dueAt = new DateTimeImmutable($createdAt);
        $businessDays = 5;
        while ($businessDays > 0) {
            $dueAt = $dueAt->modify('+1 day');
            if ((int) $dueAt->format('N') < 6) {
                $businessDays--;
            }
        }
        $dueAtValue = $dueAt->format('Y-m-d H:i:s');
        $complaintId = (int) $complaint['id'];
        $dueDateStatement->bind_param('si', $dueAtValue, $complaintId);
        $dueDateStatement->execute();
    }
    $workflowMigrationStatement = $conn->prepare('INSERT INTO schema_migrations (migration_key) VALUES (?)');
    $workflowMigrationStatement->bind_param('s', $workflowMigration);
    $workflowMigrationStatement->execute();
}

$unknownCreatedAtMigration = 'exclude-unknown-created-at-from-sla-v1';
$unknownCreatedAtStatement = $conn->prepare('SELECT migration_key FROM schema_migrations WHERE migration_key = ?');
$unknownCreatedAtStatement->bind_param('s', $unknownCreatedAtMigration);
$unknownCreatedAtStatement->execute();
if ($unknownCreatedAtStatement->get_result()->num_rows === 0) {
    $conn->query('UPDATE complaints SET due_at = NULL WHERE created_at IS NULL');
    $unknownCreatedAtStatement = $conn->prepare('INSERT INTO schema_migrations (migration_key) VALUES (?)');
    $unknownCreatedAtStatement->bind_param('s', $unknownCreatedAtMigration);
    $unknownCreatedAtStatement->execute();
}

<?php

function complaintWorkflowTargetDefinitions(): array
{
    return [
        'area_manager_approval' => [
            'setting' => 'workflow_target_area_manager_days',
            'label' => 'Complaint registration to Area Manager approval',
            'default' => 3
        ],
        'department_approval' => [
            'setting' => 'workflow_target_department_days',
            'label' => 'Department assignment to approval',
            'default' => 2
        ],
        'so_creation' => [
            'setting' => 'workflow_target_so_creation_days',
            'label' => 'Department approval to SO creation',
            'default' => 2
        ],
        'dispatch_handoff' => [
            'setting' => 'workflow_target_dispatch_handoff_days',
            'label' => 'SO creation to Dispatch handoff',
            'default' => 0
        ],
        'material_out' => [
            'setting' => 'workflow_target_material_out_days',
            'label' => 'Dispatch handoff to material out',
            'default' => 7
        ]
    ];
}

function getComplaintWorkflowTargets(mysqli $conn): array
{
    $targets = [];
    foreach (complaintWorkflowTargetDefinitions() as $stage => $definition) {
        $value = getPortalSetting($conn, $definition['setting'], (string) $definition['default']);
        if (!preg_match('/\A\d{1,3}\z/', $value) || (int) $value > 365) {
            throw new UnexpectedValueException('A complaint workflow target setting is invalid.');
        }
        $targets[$stage] = (int) $value;
    }

    return $targets;
}

function parseComplaintWorkflowDateTime(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$dateTime
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $dateTime->format('Y-m-d\TH:i') !== $value
    ) {
        return null;
    }

    return $dateTime->format('Y-m-d H:i:s');
}

function complaintWorkflowDateTimeInput(?string $value): string
{
    if ($value === null || $value === '') {
        return date('Y-m-d\TH:i');
    }

    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    if (!$dateTime) {
        throw new UnexpectedValueException('A stored complaint workflow date is invalid.');
    }

    return $dateTime->format('Y-m-d\TH:i');
}

function complaintWorkflowCalendarDays(string $start, string $end): int
{
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', substr($start, 0, 10));
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', substr($end, 0, 10));
    if (!$startDate || !$endDate) {
        throw new UnexpectedValueException('A complaint workflow date is invalid.');
    }

    return (int) $startDate->diff($endDate)->format('%r%a');
}

function complaintWorkflowDelayIsOverdue(string $start, string $end, int $targetDays): bool
{
    return $targetDays > 0 && complaintWorkflowCalendarDays($start, $end) > $targetDays;
}

function complaintWorkflowStageReport(
    ?string $start,
    ?string $end,
    int $targetDays,
    ?string $reason,
    string $now
): array {
    if ($start === null || $start === '') {
        return [
            'days' => null,
            'start' => null,
            'end' => null,
            'complete' => false,
            'overdue' => false,
            'target' => $targetDays,
            'reason' => $reason,
            'status' => 'Not started'
        ];
    }

    $elapsedDays = complaintWorkflowCalendarDays($start, $end ?? $now);
    $overdue = $targetDays > 0 && $elapsedDays > $targetDays;

    return [
        'days' => $elapsedDays,
        'start' => $start,
        'end' => $end,
        'complete' => $end !== null,
        'overdue' => $overdue,
        'target' => $targetDays,
        'reason' => $reason,
        'status' => $overdue ? 'Over target' : ($end === null ? 'In progress' : 'Completed')
    ];
}

function complaintWorkflowStageLabel(array $complaint, array $assignments): string
{
    $pendingDepartmentNames = array_values(array_map(
        static fn ($assignment) => (string) $assignment['department'],
        array_filter(
            $assignments,
            static fn ($assignment) => (int) $assignment['approval_required'] === 1
                && $assignment['approved_at'] === null
        )
    ));
    $isRejected = $complaint['status'] === 'Rejected' || $complaint['approval_status'] === 'Rejected';

    return match (true) {
        $isRejected => 'Rejected',
        $complaint['approval_status'] === 'Pending' => 'Awaiting Area Manager approval',
        $pendingDepartmentNames !== [] => 'Awaiting department approval: ' . implode(', ', $pendingDepartmentNames),
        $complaint['so_created_at'] === null => 'Awaiting ERP SO',
        $complaint['dispatch_sent_at'] === null => 'SO created - awaiting Dispatch handoff',
        $complaint['material_out_at'] === null => 'Sent to Dispatch - awaiting material out',
        default => 'Material out recorded'
    };
}

function getComplaintWorkflowStageLabels(mysqli $conn, array $complaints): array
{
    $complaintIds = array_values(array_unique(array_filter(
        array_map(static fn ($complaint) => (int) ($complaint['id'] ?? 0), $complaints),
        static fn (int $complaintId) => $complaintId > 0
    )));
    if ($complaintIds === []) {
        return [];
    }

    $complaintIdList = implode(',', $complaintIds);
    $complaintRows = $conn->query(
        "SELECT c.id, c.status, ca.status AS approval_status,
                w.so_created_at, w.dispatch_sent_at, w.material_out_at
         FROM complaints c
         LEFT JOIN complaint_approvals ca ON ca.complaint_id = c.id
         LEFT JOIN complaint_workflow w ON w.complaint_id = c.id
         WHERE c.id IN ({$complaintIdList})"
    )->fetch_all(MYSQLI_ASSOC);
    $assignmentsByComplaintId = [];
    $assignmentRows = $conn->query(
        "SELECT complaint_id, department, approval_required, approved_at
         FROM complaint_department_assignments
         WHERE complaint_id IN ({$complaintIdList})"
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($assignmentRows as $assignment) {
        $assignmentsByComplaintId[(int) $assignment['complaint_id']][] = $assignment;
    }

    $stageLabels = [];
    foreach ($complaintRows as $complaint) {
        $complaintId = (int) $complaint['id'];
        $stageLabels[$complaintId] = complaintWorkflowStageLabel(
            $complaint,
            $assignmentsByComplaintId[$complaintId] ?? []
        );
    }

    return $stageLabels;
}

function lockComplaintWorkflowStatus(mysqli $conn, int $complaintId): string
{
    $statement = $conn->prepare('SELECT status FROM complaints WHERE id = ? FOR UPDATE');
    $statement->bind_param('i', $complaintId);
    $statement->execute();
    $complaint = $statement->get_result()->fetch_assoc();
    if (!$complaint) {
        throw new RuntimeException('The complaint is no longer available.');
    }

    return (string) $complaint['status'];
}

function insertComplaintWorkflowAttachments(
    mysqli $conn,
    int $complaintId,
    array $files,
    string $purpose,
    int $uploadedBy,
    string $uploadDirectory
): array {
    $savedPaths = [];
    $statement = $conn->prepare(
        'INSERT INTO complaint_attachments
            (complaint_id, category, purpose, stored_name, original_name,
             mime_type, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    try {
        foreach ($files as $file) {
            $storedName = bin2hex(random_bytes(16)) . '.' . $file['extension'];
            $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file($file['temporary_path'], $destination)) {
                throw new RuntimeException('An uploaded workflow file could not be saved securely.');
            }
            $savedPaths[] = $destination;
            $category = $file['category'];
            $fileSize = (int) $file['size'];
            $statement->bind_param(
                'isssssii',
                $complaintId,
                $category,
                $purpose,
                $storedName,
                $file['original_name'],
                $file['mime_type'],
                $fileSize,
                $uploadedBy
            );
            $statement->execute();
        }
    } catch (Throwable $exception) {
        removeComplaintWorkflowAttachments($savedPaths);
        throw $exception;
    }

    return $savedPaths;
}

function removeComplaintWorkflowAttachments(array $paths): void
{
    foreach ($paths as $path) {
        if (is_file($path) && !unlink($path)) {
            error_log('Unable to remove a workflow attachment after a failed save.');
        }
    }
}

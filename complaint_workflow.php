<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/complaint_uploads.php';
require_once __DIR__ . '/includes/complaint_workflow.php';

if (!in_array($currentUser['role'], ['admin', 'department'], true)) {
    http_response_code(403);
    exit('Administrator or assigned department access required.');
}

$complaintId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($complaintId === false || $complaintId === null || $complaintId < 1) {
    http_response_code(404);
    exit('Complaint not found.');
}

$scope = getComplaintScope($currentUser);
$complaints = fetchComplaintRows(
    $conn,
    "SELECT c.id, c.public_reference, c.title, c.product, c.department, c.status, c.created_at
     FROM complaints c
     WHERE c.id = {$complaintId} AND {$scope['where']}
     LIMIT 1",
    $scope
);
if ($complaints === []) {
    http_response_code(404);
    exit('Complaint not found.');
}
$complaint = $complaints[0];
$workflowTargets = getComplaintWorkflowTargets($conn);
$currentDatabaseTime = $conn->query('SELECT NOW() AS db_now')->fetch_assoc()['db_now'];
$assignments = fetchComplaintRows(
    $conn,
    "SELECT a.department, a.assigned_at, a.approval_required, a.approved_at, a.approval_reason,
            u.full_name AS approved_by_name
     FROM complaint_department_assignments a
     INNER JOIN complaints c ON c.id = a.complaint_id
     LEFT JOIN users u ON u.id = a.approved_by
     WHERE c.id = {$complaintId} AND {$scope['where']}
     ORDER BY a.department",
    $scope
);
$approvalRows = fetchComplaintRows(
    $conn,
    "SELECT a.status, a.decided_at, a.decision_reason, u.full_name AS decided_by_name
     FROM complaint_approvals a
     INNER JOIN complaints c ON c.id = a.complaint_id
     LEFT JOIN users u ON u.id = a.decided_by
     WHERE c.id = {$complaintId} AND {$scope['where']}
     LIMIT 1",
    $scope
);
$approval = $approvalRows[0] ?? null;
$workflowRows = fetchComplaintRows(
    $conn,
    "SELECT w.*, so_user.full_name AS so_created_by_name,
            dispatch_user.full_name AS dispatch_sent_by_name,
            out_user.full_name AS material_out_by_name
     FROM complaint_workflow w
     INNER JOIN complaints c ON c.id = w.complaint_id
     LEFT JOIN users so_user ON so_user.id = w.so_created_by
     LEFT JOIN users dispatch_user ON dispatch_user.id = w.dispatch_sent_by
     LEFT JOIN users out_user ON out_user.id = w.material_out_by
     WHERE c.id = {$complaintId} AND {$scope['where']}
     LIMIT 1",
    $scope
);
$workflow = $workflowRows[0] ?? [
    'so_number' => null,
    'so_created_at' => null,
    'so_creation_delay_reason' => null,
    'so_created_by_name' => null,
    'dispatch_sent_at' => null,
    'dispatch_delay_reason' => null,
    'dispatch_sent_by_name' => null,
    'material_out_at' => null,
    'material_out_delay_reason' => null,
    'material_out_by_name' => null
];
$currentDepartmentAssignment = null;
foreach ($assignments as $assignment) {
    if ($assignment['department'] === ($currentUser['department'] ?? null)) {
        $currentDepartmentAssignment = $assignment;
        break;
    }
}
$isAssignedDispatch = $currentUser['role'] === 'department'
    && mb_strtolower((string) $currentUser['department'], 'UTF-8') === 'dispatch'
    && $currentDepartmentAssignment !== null;
$approvalTrackedAssignments = array_values(array_filter(
    $assignments,
    static fn ($assignment) => (int) $assignment['approval_required'] === 1
));
$allDepartmentsApproved = count(array_filter(
    $approvalTrackedAssignments,
    static fn ($assignment) => $assignment['approved_at'] === null
)) === 0;
$lastDepartmentApprovalAt = null;
foreach ($approvalTrackedAssignments as $assignment) {
    if ($assignment['approved_at'] !== null && (
        $lastDepartmentApprovalAt === null
        || $assignment['approved_at'] > $lastDepartmentApprovalAt
    )) {
        $lastDepartmentApprovalAt = $assignment['approved_at'];
    }
}
$isRejected = $complaint['status'] === 'Rejected' || ($approval['status'] ?? '') === 'Rejected';
$canApproveDepartment = $currentUser['role'] === 'department'
    && $currentDepartmentAssignment !== null
    && (int) $currentDepartmentAssignment['approval_required'] === 1
    && $currentDepartmentAssignment['approved_at'] === null
    && !$isRejected;
$canRecordSo = $currentUser['role'] === 'admin'
    && $allDepartmentsApproved
    && !$isRejected
    && ($approval['status'] ?? 'Approved') !== 'Pending'
    && $workflow['so_created_at'] === null;
$canSendToDispatch = $currentUser['role'] === 'admin'
    && $workflow['so_created_at'] !== null
    && $workflow['dispatch_sent_at'] === null
    && !$isRejected;
$canRecordMaterialOut = ($currentUser['role'] === 'admin' || $isAssignedDispatch)
    && $workflow['dispatch_sent_at'] !== null
    && $workflow['material_out_at'] === null
    && !$isRejected;

$errorMessage = '';
$successMessage = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$csrfToken = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentDatabaseTime = $conn->query('SELECT NOW() AS db_now')->fetch_assoc()['db_now'];
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Your form session expired. Please try again.';
    } elseif ($action === 'approve_department') {
        $reason = is_string($_POST['delay_reason'] ?? null) ? trim($_POST['delay_reason']) : '';
        if (!$canApproveDepartment) {
            $errorMessage = 'Only the assigned department can approve this complaint.';
        } elseif (mb_strlen($reason, 'UTF-8') > 2000) {
            $errorMessage = 'The delay reason must be 2,000 characters or fewer.';
        } else {
            $conn->begin_transaction();
            try {
                if (lockComplaintWorkflowStatus($conn, $complaintId) === 'Rejected') {
                    throw new RuntimeException('Rejected complaints cannot be advanced in the workflow.');
                }
                $assignmentStatement = $conn->prepare(
                    'SELECT assigned_at, approved_at
                     FROM complaint_department_assignments
                     WHERE complaint_id = ? AND department = ?
                     FOR UPDATE'
                );
                $department = (string) $currentUser['department'];
                $assignmentStatement->bind_param('is', $complaintId, $department);
                $assignmentStatement->execute();
                $lockedAssignment = $assignmentStatement->get_result()->fetch_assoc();
                if (!$lockedAssignment || $lockedAssignment['approved_at'] !== null) {
                    throw new RuntimeException('This department has already approved the complaint or is no longer assigned.');
                }

                $databaseNow = $conn->query('SELECT NOW() AS db_now')->fetch_assoc()['db_now'];
                if (
                    complaintWorkflowDelayIsOverdue(
                        $lockedAssignment['assigned_at'],
                        $databaseNow,
                        $workflowTargets['department_approval']
                    )
                    && $reason === ''
                ) {
                    throw new RuntimeException('This department approval is over its configured target. Enter the delay reason.');
                }

                $storedReason = $reason !== '' ? $reason : null;
                $approverId = (int) $currentUser['id'];
                $updateStatement = $conn->prepare(
                    'UPDATE complaint_department_assignments
                     SET approved_at = NOW(), approved_by = ?, approval_reason = ?
                     WHERE complaint_id = ? AND department = ? AND approved_at IS NULL'
                );
                $updateStatement->bind_param('isis', $approverId, $storedReason, $complaintId, $department);
                $updateStatement->execute();
                if ($updateStatement->affected_rows !== 1) {
                    throw new RuntimeException('This department approval was already recorded.');
                }
                recordAuditEvent(
                    $conn,
                    $approverId,
                    'complaint.department_approved',
                    'complaint',
                    $complaintId,
                    $department . ' approved the complaint for ERP order processing.',
                    ['reason' => $storedReason]
                );
                $conn->commit();
                $_SESSION['flash_success'] = $department . ' approval recorded.';
                header('Location: complaint_workflow.php?id=' . $complaintId . '#workflow');
                exit;
            } catch (Throwable $exception) {
                $conn->rollback();
                if ($exception instanceof mysqli_sql_exception) {
                    error_log('Department approval save failed: ' . $exception->getMessage());
                    $errorMessage = 'The department approval could not be saved because of a database error.';
                } elseif ($exception instanceof RuntimeException) {
                    $errorMessage = $exception->getMessage();
                } else {
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'record_so') {
        $soNumber = is_string($_POST['so_number'] ?? null) ? trim($_POST['so_number']) : '';
        $soCreatedAt = parseComplaintWorkflowDateTime($_POST['so_created_at'] ?? null);
        $reason = is_string($_POST['delay_reason'] ?? null) ? trim($_POST['delay_reason']) : '';
        $uploadResult = validateComplaintUploads($_FILES);
        if (!$canRecordSo || $currentUser['role'] !== 'admin') {
            $errorMessage = 'The SO can be recorded only by an administrator after all assigned departments approve.';
        } elseif ($soNumber === '' || strlen($soNumber) > 100) {
            $errorMessage = 'Enter an ERP SO number of up to 100 characters.';
        } elseif ($soCreatedAt === null || $soCreatedAt > $currentDatabaseTime) {
            $errorMessage = 'Enter a valid SO creation date and time that is not in the future.';
        } elseif (mb_strlen($reason, 'UTF-8') > 2000) {
            $errorMessage = 'The delay reason must be 2,000 characters or fewer.';
        } elseif ($uploadResult['error'] !== '') {
            $errorMessage = $uploadResult['error'];
        } elseif ($uploadResult['files'] === []) {
            $errorMessage = 'Attach the ERP SO copy or supporting document.';
        } else {
            try {
                $uploadDirectory = ensureComplaintAttachmentStorage($complaintId);
                $savedPaths = [];
                $conn->begin_transaction();
                try {
                    if (lockComplaintWorkflowStatus($conn, $complaintId) === 'Rejected') {
                        throw new RuntimeException('Rejected complaints cannot receive an ERP SO.');
                    }
                    $approvalLock = $conn->prepare(
                        'SELECT status, decided_at FROM complaint_approvals WHERE complaint_id = ? FOR UPDATE'
                    );
                    $approvalLock->bind_param('i', $complaintId);
                    $approvalLock->execute();
                    $lockedApproval = $approvalLock->get_result()->fetch_assoc();
                    if ($lockedApproval && in_array($lockedApproval['status'], ['Pending', 'Rejected'], true)) {
                        throw new RuntimeException('The complaint must be approved by the Area Manager before an SO is recorded.');
                    }

                    $assignmentLock = $conn->prepare(
                        'SELECT department, approval_required, approved_at
                         FROM complaint_department_assignments
                         WHERE complaint_id = ?
                         ORDER BY department
                         FOR UPDATE'
                    );
                    $assignmentLock->bind_param('i', $complaintId);
                    $assignmentLock->execute();
                    $lockedAssignments = $assignmentLock->get_result()->fetch_all(MYSQLI_ASSOC);
                    foreach ($lockedAssignments as $lockedAssignment) {
                        if (
                            (int) $lockedAssignment['approval_required'] === 1
                            && $lockedAssignment['approved_at'] === null
                        ) {
                            throw new RuntimeException('Wait for every assigned department to approve before recording the SO.');
                        }
                    }
                    $workflowLock = $conn->prepare(
                        'SELECT so_created_at FROM complaint_workflow WHERE complaint_id = ? FOR UPDATE'
                    );
                    $workflowLock->bind_param('i', $complaintId);
                    $workflowLock->execute();
                    $lockedWorkflow = $workflowLock->get_result()->fetch_assoc();
                    if ($lockedWorkflow && $lockedWorkflow['so_created_at'] !== null) {
                        throw new RuntimeException('An ERP SO is already recorded for this complaint.');
                    }

                    $creationStart = null;
                    foreach ($lockedAssignments as $lockedAssignment) {
                        if ((int) $lockedAssignment['approval_required'] !== 1) {
                            continue;
                        }
                        if ($creationStart === null || $lockedAssignment['approved_at'] > $creationStart) {
                            $creationStart = $lockedAssignment['approved_at'];
                        }
                    }
                    $creationStart ??= $lockedApproval['decided_at'] ?? $complaint['created_at'];
                    if ($soCreatedAt < $creationStart) {
                        throw new RuntimeException('The SO creation time cannot be earlier than the final department approval.');
                    }
                    if (
                        complaintWorkflowDelayIsOverdue(
                            $creationStart,
                            $soCreatedAt,
                            $workflowTargets['so_creation']
                        )
                        && $reason === ''
                    ) {
                        throw new RuntimeException('SO creation exceeded its configured target. Enter the delay reason.');
                    }

                    $storedReason = $reason !== '' ? $reason : null;
                    $recorderId = (int) $currentUser['id'];
                    $saveStatement = $conn->prepare(
                        'INSERT INTO complaint_workflow
                            (complaint_id, so_number, so_created_at, so_created_by, so_creation_delay_reason)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            so_number = VALUES(so_number),
                            so_created_at = VALUES(so_created_at),
                            so_created_by = VALUES(so_created_by),
                            so_creation_delay_reason = VALUES(so_creation_delay_reason)'
                    );
                    $saveStatement->bind_param('issis', $complaintId, $soNumber, $soCreatedAt, $recorderId, $storedReason);
                    $saveStatement->execute();
                    $savedPaths = insertComplaintWorkflowAttachments(
                        $conn,
                        $complaintId,
                        $uploadResult['files'],
                        'erp_so',
                        $recorderId,
                        $uploadDirectory
                    );
                    recordAuditEvent(
                        $conn,
                        $recorderId,
                        'complaint.erp_so_recorded',
                        'complaint',
                        $complaintId,
                        'ERP SO ' . $soNumber . ' was recorded.',
                        ['so_created_at' => $soCreatedAt]
                    );
                    $conn->commit();
                } catch (Throwable $exception) {
                    $conn->rollback();
                    removeComplaintWorkflowAttachments($savedPaths);
                    throw $exception;
                }
                $_SESSION['flash_success'] = 'ERP SO recorded against this complaint.';
                header('Location: complaint_workflow.php?id=' . $complaintId . '#workflow');
                exit;
            } catch (mysqli_sql_exception $exception) {
                error_log('Complaint ERP SO save failed: ' . $exception->getMessage());
                $errorMessage = 'The ERP SO could not be saved because of a database error.';
            } catch (RuntimeException $exception) {
                $errorMessage = $exception->getMessage();
            }
        }
    } elseif (in_array($action, ['send_to_dispatch', 'material_out'], true)) {
        $eventAt = parseComplaintWorkflowDateTime($_POST['event_at'] ?? null);
        $reason = is_string($_POST['delay_reason'] ?? null) ? trim($_POST['delay_reason']) : '';
        $uploadResult = $action === 'material_out'
            ? validateComplaintUploads($_FILES)
            : ['files' => [], 'error' => ''];
        if (mb_strlen($reason, 'UTF-8') > 2000) {
            $errorMessage = 'The delay reason must be 2,000 characters or fewer.';
        } elseif ($eventAt === null || $eventAt > $currentDatabaseTime) {
            $errorMessage = 'Enter a valid event date and time that is not in the future.';
        } elseif ($uploadResult['error'] !== '') {
            $errorMessage = $uploadResult['error'];
        } elseif ($action === 'material_out' && $uploadResult['files'] === []) {
            $errorMessage = 'Attach the dispatch challan or material-out proof.';
        } elseif (
            ($action === 'send_to_dispatch' && (!$canSendToDispatch || $currentUser['role'] !== 'admin'))
            || ($action === 'material_out' && !$canRecordMaterialOut)
        ) {
            $errorMessage = 'You are not allowed to record this dispatch stage.';
        } else {
            try {
                $uploadDirectory = $action === 'material_out'
                    ? ensureComplaintAttachmentStorage($complaintId)
                    : '';
                $savedPaths = [];
                $conn->begin_transaction();
                try {
                    if (lockComplaintWorkflowStatus($conn, $complaintId) === 'Rejected') {
                        throw new RuntimeException('Rejected complaints cannot be advanced in the dispatch workflow.');
                    }
                    $workflowLock = $conn->prepare(
                        'SELECT so_created_at, dispatch_sent_at, material_out_at
                         FROM complaint_workflow WHERE complaint_id = ? FOR UPDATE'
                    );
                    $workflowLock->bind_param('i', $complaintId);
                    $workflowLock->execute();
                    $lockedWorkflow = $workflowLock->get_result()->fetch_assoc();
                    if (!$lockedWorkflow || $lockedWorkflow['so_created_at'] === null) {
                        throw new RuntimeException('Record the ERP SO before updating dispatch.');
                    }

                    if ($action === 'send_to_dispatch') {
                        if ($lockedWorkflow['dispatch_sent_at'] !== null) {
                            throw new RuntimeException('The SO has already been sent to Dispatch.');
                        }
                        if ($eventAt < $lockedWorkflow['so_created_at']) {
                            throw new RuntimeException('Dispatch handoff cannot be earlier than SO creation.');
                        }
                        if (
                            complaintWorkflowDelayIsOverdue(
                                $lockedWorkflow['so_created_at'],
                                $eventAt,
                                $workflowTargets['dispatch_handoff']
                            )
                            && $reason === ''
                        ) {
                            throw new RuntimeException('SO-to-Dispatch exceeded its configured target. Enter the delay reason.');
                        }
                        $storedReason = $reason !== '' ? $reason : null;
                        $actorId = (int) $currentUser['id'];
                        $updateStatement = $conn->prepare(
                            'UPDATE complaint_workflow
                             SET dispatch_sent_at = ?, dispatch_sent_by = ?, dispatch_delay_reason = ?
                             WHERE complaint_id = ? AND dispatch_sent_at IS NULL'
                        );
                        $updateStatement->bind_param('sisi', $eventAt, $actorId, $storedReason, $complaintId);
                        $updateStatement->execute();
                        if ($updateStatement->affected_rows !== 1) {
                            throw new RuntimeException('The Dispatch handoff was already recorded.');
                        }
                        $auditAction = 'complaint.dispatch_handoff_recorded';
                        $auditSummary = 'The ERP SO was handed off to Dispatch.';
                    } else {
                        if ($currentUser['role'] === 'department') {
                            $dispatchAssignmentLock = $conn->prepare(
                                "SELECT 1 FROM complaint_department_assignments
                                 WHERE complaint_id = ? AND department = 'Dispatch' FOR UPDATE"
                            );
                            $dispatchAssignmentLock->bind_param('i', $complaintId);
                            $dispatchAssignmentLock->execute();
                            if ($dispatchAssignmentLock->get_result()->num_rows !== 1) {
                                throw new RuntimeException('Only the assigned Dispatch department can record material out.');
                            }
                        }
                        if ($lockedWorkflow['dispatch_sent_at'] === null || $lockedWorkflow['material_out_at'] !== null) {
                            throw new RuntimeException('Dispatch handoff must be recorded before material out, and material out can be recorded only once.');
                        }
                        if ($eventAt < $lockedWorkflow['dispatch_sent_at']) {
                            throw new RuntimeException('Material-out time cannot be earlier than the Dispatch handoff.');
                        }
                        if (
                            complaintWorkflowDelayIsOverdue(
                                $lockedWorkflow['dispatch_sent_at'],
                                $eventAt,
                                $workflowTargets['material_out']
                            )
                            && $reason === ''
                        ) {
                            throw new RuntimeException('Material out exceeded its configured target. Enter the delay reason.');
                        }
                        $storedReason = $reason !== '' ? $reason : null;
                        $actorId = (int) $currentUser['id'];
                        $updateStatement = $conn->prepare(
                            'UPDATE complaint_workflow
                             SET material_out_at = ?, material_out_by = ?, material_out_delay_reason = ?
                             WHERE complaint_id = ? AND material_out_at IS NULL'
                        );
                        $updateStatement->bind_param('sisi', $eventAt, $actorId, $storedReason, $complaintId);
                        $updateStatement->execute();
                        if ($updateStatement->affected_rows !== 1) {
                            throw new RuntimeException('Material out was already recorded.');
                        }
                        $savedPaths = insertComplaintWorkflowAttachments(
                            $conn,
                            $complaintId,
                            $uploadResult['files'],
                            'material_out_proof',
                            $actorId,
                            $uploadDirectory
                        );
                        $auditAction = 'complaint.material_out_recorded';
                        $auditSummary = 'Dispatch recorded that material was out.';
                    }

                    recordAuditEvent(
                        $conn,
                        $actorId,
                        $auditAction,
                        'complaint',
                        $complaintId,
                        $auditSummary,
                        ['event_at' => $eventAt, 'reason' => $storedReason]
                    );
                    $conn->commit();
                } catch (Throwable $exception) {
                    $conn->rollback();
                    removeComplaintWorkflowAttachments($savedPaths);
                    throw $exception;
                }
                $_SESSION['flash_success'] = $action === 'send_to_dispatch'
                    ? 'SO-to-Dispatch handoff recorded.'
                    : 'Material-out date and proof recorded.';
                header('Location: complaint_workflow.php?id=' . $complaintId . '#workflow');
                exit;
            } catch (mysqli_sql_exception $exception) {
                error_log('Complaint dispatch workflow save failed: ' . $exception->getMessage());
                $errorMessage = 'The dispatch milestone could not be saved because of a database error.';
            } catch (RuntimeException $exception) {
                $errorMessage = $exception->getMessage();
            }
        }
    } else {
        $errorMessage = 'Choose a valid workflow action.';
    }
}

$assignments = fetchComplaintRows(
    $conn,
    "SELECT a.department, a.assigned_at, a.approval_required, a.approved_at, a.approval_reason,
            u.full_name AS approved_by_name
     FROM complaint_department_assignments a
     INNER JOIN complaints c ON c.id = a.complaint_id
     LEFT JOIN users u ON u.id = a.approved_by
     WHERE c.id = {$complaintId} AND {$scope['where']}
     ORDER BY a.department",
    $scope
);
$approvalRows = fetchComplaintRows(
    $conn,
    "SELECT a.status, a.decided_at, a.decision_reason, u.full_name AS decided_by_name
     FROM complaint_approvals a
     INNER JOIN complaints c ON c.id = a.complaint_id
     LEFT JOIN users u ON u.id = a.decided_by
     WHERE c.id = {$complaintId} AND {$scope['where']}
     LIMIT 1",
    $scope
);
$approval = $approvalRows[0] ?? null;
$workflowRows = fetchComplaintRows(
    $conn,
    "SELECT w.*, so_user.full_name AS so_created_by_name,
            dispatch_user.full_name AS dispatch_sent_by_name,
            out_user.full_name AS material_out_by_name
     FROM complaint_workflow w
     INNER JOIN complaints c ON c.id = w.complaint_id
     LEFT JOIN users so_user ON so_user.id = w.so_created_by
     LEFT JOIN users dispatch_user ON dispatch_user.id = w.dispatch_sent_by
     LEFT JOIN users out_user ON out_user.id = w.material_out_by
     WHERE c.id = {$complaintId} AND {$scope['where']}
     LIMIT 1",
    $scope
);
$workflow = $workflowRows[0] ?? $workflow;
$currentDepartmentAssignment = null;
foreach ($assignments as $assignment) {
    if ($assignment['department'] === ($currentUser['department'] ?? null)) {
        $currentDepartmentAssignment = $assignment;
        break;
    }
}
$isAssignedDispatch = $currentUser['role'] === 'department'
    && mb_strtolower((string) $currentUser['department'], 'UTF-8') === 'dispatch'
    && $currentDepartmentAssignment !== null;
$approvalTrackedAssignments = array_values(array_filter(
    $assignments,
    static fn ($assignment) => (int) $assignment['approval_required'] === 1
));
$allDepartmentsApproved = count(array_filter(
    $approvalTrackedAssignments,
    static fn ($assignment) => $assignment['approved_at'] === null
)) === 0;
$lastDepartmentApprovalAt = null;
foreach ($approvalTrackedAssignments as $assignment) {
    if ($assignment['approved_at'] !== null && (
        $lastDepartmentApprovalAt === null
        || $assignment['approved_at'] > $lastDepartmentApprovalAt
    )) {
        $lastDepartmentApprovalAt = $assignment['approved_at'];
    }
}
$isRejected = $complaint['status'] === 'Rejected' || ($approval['status'] ?? '') === 'Rejected';
$canApproveDepartment = $currentUser['role'] === 'department'
    && $currentDepartmentAssignment !== null
    && (int) $currentDepartmentAssignment['approval_required'] === 1
    && $currentDepartmentAssignment['approved_at'] === null
    && !$isRejected;
$canRecordSo = $currentUser['role'] === 'admin'
    && $allDepartmentsApproved
    && !$isRejected
    && ($approval['status'] ?? 'Approved') !== 'Pending'
    && $workflow['so_created_at'] === null;
$canSendToDispatch = $currentUser['role'] === 'admin'
    && $workflow['so_created_at'] !== null
    && $workflow['dispatch_sent_at'] === null
    && !$isRejected;
$canRecordMaterialOut = ($currentUser['role'] === 'admin' || $isAssignedDispatch)
    && $workflow['dispatch_sent_at'] !== null
    && $workflow['material_out_at'] === null
    && !$isRejected;
$currentStage = complaintWorkflowStageLabel(
    [
        'status' => $complaint['status'],
        'approval_status' => $approval['status'] ?? null,
        'so_created_at' => $workflow['so_created_at'],
        'dispatch_sent_at' => $workflow['dispatch_sent_at'],
        'material_out_at' => $workflow['material_out_at']
    ],
    $assignments
);
$evidenceRows = fetchComplaintRows(
    $conn,
    "SELECT a.id, a.purpose, a.original_name, a.mime_type, a.created_at,
            u.full_name AS uploaded_by_name
     FROM complaint_attachments a
     INNER JOIN complaints c ON c.id = a.complaint_id
     LEFT JOIN users u ON u.id = a.uploaded_by
     WHERE c.id = {$complaintId}
       AND a.purpose IN ('erp_so', 'material_out_proof')
       AND {$scope['where']}
     ORDER BY a.created_at, a.id",
    $scope
);
$evidenceLabels = ['erp_so' => 'ERP SO copy', 'material_out_proof' => 'Material-out proof'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173a28">
    <title>Complaint workflow | Farmer Portal</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="dashboard-shell">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="brand-mark" aria-hidden="true">✳</span> Farmer Portal</a>
        <div class="topbar-actions">
            <?php renderNotificationLink($conn, (int) $currentUser['id']); ?>
            <a href="logout.php" class="logout-button">Sign out <span aria-hidden="true">↗</span></a>
        </div>
    </header>
    <section id="workflow" class="page-panel">
        <div class="page-panel-heading">
            <div>
                <p class="eyebrow">Complaint <?php echo escapeHtml(formatComplaintReference((int) $complaint['id'], $complaint['public_reference'])); ?></p>
                <h1 class="page-heading">Approval, ERP SO &amp; dispatch workflow</h1>
                <p class="page-subtitle"><?php echo escapeHtml($complaint['title'] . ' · ' . $complaint['product']); ?></p>
            </div>
            <div class="complaint-list-actions"><a class="secondary-button" href="dashboard.php#complaint-list">Back to complaints</a></div>
        </div>
        <?php if ($errorMessage !== ''): ?><div class="notice notice--error" role="alert"><?php echo escapeHtml($errorMessage); ?></div><?php endif; ?>
        <?php if ($successMessage !== ''): ?><div class="notice notice--success" role="status"><?php echo escapeHtml($successMessage); ?></div><?php endif; ?>

        <section class="dashboard-card material-history">
            <div class="admin-section-heading"><div><h2>Current stage: <?php echo escapeHtml($currentStage); ?></h2><p>All elapsed times are calendar days. A reason is required only when the configured stage target is exceeded.</p></div></div>
            <dl class="material-history-details">
                <div><dt>Complaint registered</dt><dd><?php echo escapeHtml(date('d-m-Y H:i', strtotime($complaint['created_at']))); ?></dd></div>
                <div><dt>Area Manager</dt><dd><?php echo escapeHtml($approval['status'] ?? 'Direct department route'); ?><?php if (!empty($approval['decided_at'])): ?> · <?php echo escapeHtml(date('d-m-Y H:i', strtotime($approval['decided_at']))); ?><?php endif; ?></dd></div>
                <?php if ($workflow['so_number'] !== null): ?>
                    <div><dt>ERP SO</dt><dd><?php echo escapeHtml($workflow['so_number']); ?> · <?php echo escapeHtml(date('d-m-Y H:i', strtotime($workflow['so_created_at']))); ?></dd></div>
                <?php endif; ?>
                <?php if ($workflow['dispatch_sent_at'] !== null): ?>
                    <div><dt>Sent to Dispatch</dt><dd><?php echo escapeHtml(date('d-m-Y H:i', strtotime($workflow['dispatch_sent_at']))); ?></dd></div>
                <?php endif; ?>
                <?php if ($workflow['material_out_at'] !== null): ?>
                    <div><dt>Material out</dt><dd><?php echo escapeHtml(date('d-m-Y H:i', strtotime($workflow['material_out_at']))); ?></dd></div>
                <?php endif; ?>
            </dl>
        </section>

        <section class="dashboard-card material-history">
            <div class="admin-section-heading"><div><h2>Assigned department approvals</h2><p>Every newly tracked assigned department must approve before the administrator can record the ERP SO.</p></div></div>
            <?php if ($assignments === []): ?>
                <p class="field-hint">No department approval is assigned to this complaint.</p>
            <?php else: ?>
                <div class="material-history-list">
                    <?php foreach ($assignments as $assignment): ?>
                        <article class="material-history-entry">
                            <div class="material-history-heading">
                                <div><span class="eyebrow"><?php echo escapeHtml($assignment['department']); ?></span><h3><?php echo (int) $assignment['approval_required'] === 0 ? 'Legacy approval not tracked' : ($assignment['approved_at'] !== null ? 'Approved' : 'Approval pending'); ?></h3></div>
                                <?php if ($assignment['approved_at'] !== null): ?><time datetime="<?php echo escapeHtml($assignment['approved_at']); ?>"><?php echo escapeHtml(date('d-m-Y H:i', strtotime($assignment['approved_at']))); ?></time><?php endif; ?>
                            </div>
                            <?php if ($assignment['approved_by_name'] !== null): ?><p class="field-hint">Approved by <?php echo escapeHtml($assignment['approved_by_name']); ?></p><?php endif; ?>
                            <?php if ($assignment['approval_reason'] !== null && $assignment['approval_reason'] !== ''): ?><p><?php echo nl2br(escapeHtml($assignment['approval_reason'])); ?></p><?php endif; ?>
                            <?php if ($canApproveDepartment && $assignment['department'] === $currentDepartmentAssignment['department']): ?>
                                <form method="POST" class="form-stack">
                                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                                    <input type="hidden" name="action" value="approve_department">
                                    <div class="field">
                                        <label for="department-delay-reason">Delay reason (<?php echo $workflowTargets['department_approval'] > 0 ? 'required if over ' . (int) $workflowTargets['department_approval'] . ' days' : 'optional; no target configured'; ?>)</label>
                                        <textarea id="department-delay-reason" name="delay_reason" maxlength="2000"></textarea>
                                    </div>
                                    <button class="primary-button" type="submit">Approve for ERP order processing</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($canRecordSo): ?>
            <section class="dashboard-card material-record-card">
                <div class="admin-section-heading"><div><h2>Record ERP Sales Order</h2><p>Create the SO in ERP first, then enter its number/date here and attach the ERP SO copy.</p></div></div>
                <form method="POST" enctype="multipart/form-data" class="form-stack">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="record_so">
                    <div class="form-grid">
                        <div class="field"><label for="so-number">ERP SO number</label><input id="so-number" name="so_number" maxlength="100" required></div>
                        <div class="field"><label for="so-created-at">SO created date/time</label><input id="so-created-at" type="datetime-local" name="so_created_at" value="<?php echo escapeHtml(complaintWorkflowDateTimeInput($currentDatabaseTime)); ?>" required></div>
                        <div class="field field--full">
                            <label for="so-delay-reason">Delay reason (<?php echo $workflowTargets['so_creation'] > 0 ? 'required if over ' . (int) $workflowTargets['so_creation'] . ' days' : 'optional; no target configured'; ?>)</label>
                            <textarea id="so-delay-reason" name="delay_reason" maxlength="2000"></textarea>
                        </div>
                        <div class="field field--full">
                            <label for="so-proof-images">ERP SO copy / proof</label>
                            <input id="so-proof-images" type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
                            <input type="file" name="documents[]" accept=".pdf,.docx,.xlsx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple aria-label="ERP SO documents">
                            <input type="file" name="videos[]" accept=".mp4,.webm,video/mp4,video/webm" multiple aria-label="ERP SO videos">
                            <p class="field-hint">Attach at least one file. Up to 5 files per upload, 10 MB per file.</p>
                        </div>
                    </div>
                    <button class="primary-button" type="submit">Save ERP SO</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($canSendToDispatch): ?>
            <section class="dashboard-card material-record-card">
                <div class="admin-section-heading"><div><h2>Send SO to Dispatch</h2><p>Record the date/time the SO was handed to Dispatch. A target of 0 means no overdue target is set for this stage.</p></div></div>
                <form method="POST" class="form-stack">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="send_to_dispatch">
                    <div class="field"><label for="dispatch-sent-at">Dispatch handoff date/time</label><input id="dispatch-sent-at" type="datetime-local" name="event_at" value="<?php echo escapeHtml(complaintWorkflowDateTimeInput($currentDatabaseTime)); ?>" required></div>
                    <div class="field"><label for="dispatch-delay-reason">Delay reason (<?php echo $workflowTargets['dispatch_handoff'] > 0 ? 'required if over ' . (int) $workflowTargets['dispatch_handoff'] . ' days' : 'optional; no target configured'; ?>)</label><textarea id="dispatch-delay-reason" name="delay_reason" maxlength="2000"></textarea></div>
                    <button class="primary-button" type="submit">Record Dispatch handoff</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($canRecordMaterialOut): ?>
            <section class="dashboard-card material-record-card">
                <div class="admin-section-heading"><div><h2>Record material out</h2><p>Dispatch records when the material physically leaves and attaches the challan or other proof.</p></div></div>
                <form method="POST" enctype="multipart/form-data" class="form-stack">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="material_out">
                    <div class="field"><label for="material-out-at">Material-out date/time</label><input id="material-out-at" type="datetime-local" name="event_at" value="<?php echo escapeHtml(complaintWorkflowDateTimeInput($currentDatabaseTime)); ?>" required></div>
                    <div class="field"><label for="material-out-delay-reason">Delay reason (<?php echo $workflowTargets['material_out'] > 0 ? 'required if over ' . (int) $workflowTargets['material_out'] . ' days' : 'optional; no target configured'; ?>)</label><textarea id="material-out-delay-reason" name="delay_reason" maxlength="2000"></textarea></div>
                    <div class="field field--full">
                        <label for="material-out-proof-images">Dispatch challan / material-out proof</label>
                        <input id="material-out-proof-images" type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
                        <input type="file" name="documents[]" accept=".pdf,.docx,.xlsx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple aria-label="Dispatch proof documents">
                        <input type="file" name="videos[]" accept=".mp4,.webm,video/mp4,video/webm" multiple aria-label="Dispatch proof videos">
                        <p class="field-hint">Attach at least one file. Up to 5 files per upload, 10 MB per file.</p>
                    </div>
                    <button class="primary-button" type="submit">Record material out</button>
                </form>
            </section>
        <?php endif; ?>

        <section class="dashboard-card material-history">
            <div class="admin-section-heading"><div><h2>SO and dispatch evidence</h2><p>SO copies and material-out proof saved against this complaint.</p></div></div>
            <?php if ($evidenceRows === []): ?>
                <p class="field-hint">No SO or material-out proof has been uploaded.</p>
            <?php else: ?>
                <div class="material-history-list">
                    <?php foreach ($evidenceRows as $evidence): ?>
                        <article class="material-history-entry">
                            <span class="eyebrow"><?php echo escapeHtml($evidenceLabels[$evidence['purpose']] ?? 'Workflow proof'); ?></span>
                            <h3><?php echo escapeHtml($evidence['original_name']); ?></h3>
                            <p class="field-hint">Uploaded by <?php echo escapeHtml($evidence['uploaded_by_name'] ?? 'Not recorded'); ?> · <?php echo escapeHtml(date('d-m-Y H:i', strtotime($evidence['created_at']))); ?></p>
                            <a href="download_attachment.php?id=<?php echo (int) $evidence['id']; ?>" target="_blank" rel="noopener noreferrer">Open / download</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

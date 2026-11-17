<?php

function renderAdminNavigation(string $activeSection): void
{
    $sections = [
        'overview' => ['Settings', 'admin_settings.php'],
        'users' => ['Users', 'admin_users.php'],
        'locations' => ['Locations', 'admin_locations.php'],
        'dealers' => ['Dealers', 'admin_dealers.php'],
        'bulk' => ['Bulk actions', 'admin_bulk_complaints.php'],
        'workflow' => ['Workflow & SLA', 'admin_workflow.php'],
        'workflow_report' => ['Stage time report', 'admin_workflow_report.php'],
        'products' => ['Products', 'admin_products.php'],
        'reports' => ['Reports', 'admin_reports.php'],
        'audit' => ['Audit log', 'admin_audit_log.php'],
        'backup' => ['Backup & restore', 'admin_backup.php'],
        'security' => ['Security', 'admin_security.php']
    ];

    echo '<nav class="admin-navigation" aria-label="Administrator sections">';
    foreach ($sections as $section => [$label, $href]) {
        $isActive = $section === $activeSection;
        echo '<a href="' . escapeHtml($href) . '"'
            . ($isActive ? ' class="is-active" aria-current="page"' : '')
            . '>' . escapeHtml($label) . '</a>';
    }
    echo '</nav>';
}

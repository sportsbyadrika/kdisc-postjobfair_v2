<?php
require_once __DIR__ . '/auth.php';

function role_label(string $role): string
{
    return match ($role) {
        'administrator' => 'Administrator',
        'crm_member' => 'CRM Member',
        'district_user' => 'District User',
        'state_dsm' => 'State DSM',
        default => ucwords(str_replace('_', ' ', $role)),
    };
}

function user_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts));
    if ($parts === []) {
        return 'U';
    }
    if (count($parts) === 1) {
        return strtoupper(mb_substr($parts[0], 0, 2));
    }
    return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
}

function render_header(string $title, array $options = []): void
{
    $showNavigation = $options['show_navigation'] ?? true;
    $mainContainerClass = $options['main_container_class'] ?? 'container';
    $user = current_user();
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $isActive = static function (array $scripts) use ($currentScript): string {
        return in_array($currentScript, $scripts, true) ? ' active' : '';
    };
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title) ?> · Job Fair CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="d-flex flex-column min-vh-100">
<?php if ($showNavigation): ?>
    <nav class="navbar navbar-expand-lg app-navbar sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="/dashboard.php">
                <span class="app-brand-mark"><i class="bi bi-briefcase-fill"></i></span>
                <span class="app-brand-text">
                    Job Fair CRM
                    <small>Post Job Fair Tracker</small>
                </span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <?php if ($user): ?>
                    <?php $isDistrictUser = is_district_user($user); ?>
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        <li class="nav-item"><a class="nav-link<?= $isActive(['dashboard.php']) ?>" href="/dashboard.php"><i class="bi bi-grid-1x2 me-1"></i>Dashboard</a></li>
                        <?php if ($isDistrictUser): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle<?= $isActive(['phone_directory.php']) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-sliders me-1"></i>Masters</a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="/phone_directory.php"><i class="bi bi-person-rolodex me-2"></i>Phone Directory</a></li>
                                </ul>
                            </li>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle<?= $isActive(['district_candidate_data.php', 'manage_candidate.php']) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-clipboard2-data me-1"></i>Job Fair</a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="/district_candidate_data.php"><i class="bi bi-people me-2"></i>Candidate Data</a></li>
                                </ul>
                            </li>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle<?= $isActive(['district_data.php', 'district_consolidated_report.php', 'job_station_consolidated_report.php', 'joined_candidates_report.php', 'district_discrepancy_report.php', 'district_candidate_joined_status_report.php']) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-graph-up me-1"></i>Reports</a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="/district_data.php"><i class="bi bi-bar-chart-line me-2"></i>District Overview</a></li>
                                    <li><a class="dropdown-item" href="/district_consolidated_report.php"><i class="bi bi-clipboard-data me-2"></i>Consolidated Report</a></li>
                                    <li><a class="dropdown-item" href="/job_station_consolidated_report.php"><i class="bi bi-buildings me-2"></i>Job Station Report</a></li>
                                    <li><a class="dropdown-item" href="/joined_candidates_report.php"><i class="bi bi-door-open-fill me-2"></i>Joined Candidates</a></li>
                                    <li><a class="dropdown-item" href="/district_candidate_joined_status_report.php"><i class="bi bi-geo-alt me-2"></i>District wise Candidate joined status</a></li>
                                    <li><a class="dropdown-item" href="/district_discrepancy_report.php"><i class="bi bi-exclamation-diamond me-2"></i>Discrepancy Report</a></li>
                                </ul>
                            </li>
                        <?php else: ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle<?= $isActive(['job_fair_results.php', 'notifications.php', 'job_fair_result_upload.php', 'job_fair_result_full_upload.php', 'aggregator_offer_letter_upload.php', 'job_fair_results_export.php', 'manage_candidate.php', 'crm_process.php']) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-clipboard2-data me-1"></i>Job Fair</a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="/job_fair_results.php"><i class="bi bi-table me-2"></i>Job Fair Result Data</a></li>
                                    <?php if (is_admin($user)): ?>
                                        <li><a class="dropdown-item" href="/crm_process.php"><i class="bi bi-kanban me-2"></i>CRM Process (Employer)</a></li>
                                    <?php endif; ?>
                                    <li><a class="dropdown-item" href="/notifications.php"><i class="bi bi-bell me-2"></i>Notifications</a></li>
                                    <?php if (is_admin($user)): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><h6 class="dropdown-header">Data Management</h6></li>
                                        <li><a class="dropdown-item" href="/job_fair_result_upload.php"><i class="bi bi-upload me-2"></i>Upload Job Fair Result CSV</a></li>
                                        <li><a class="dropdown-item" href="/aggregator_offer_letter_upload.php"><i class="bi bi-upload me-2"></i>Upload Aggregator Data CSV</a></li>
                                        <li><a class="dropdown-item" href="/job_fair_results_export.php"><i class="bi bi-download me-2"></i>Download Job Fair Result CSV</a></li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle<?= $isActive(['phone_directory.php', 'job_fair_masters.php', 'job_fair_job_titles.php', 'job_fair_sdpk_centers.php', 'job_fair_job_stations.php', 'candidates_master.php']) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-sliders me-1"></i>Masters</a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="/phone_directory.php"><i class="bi bi-person-rolodex me-2"></i>Phone Directory</a></li>
                                    <li><a class="dropdown-item" href="/job_fair_masters.php"><i class="bi bi-diagram-3 me-2"></i>Employer and SPOC Mapping</a></li>
                                    <?php if (is_admin($user)): ?>
                                        <li><a class="dropdown-item" href="/job_fair_job_titles.php"><i class="bi bi-diagram-2 me-2"></i>Job Titles</a></li>
                                        <li><a class="dropdown-item" href="/job_fair_sdpk_centers.php"><i class="bi bi-buildings me-2"></i>SDPK Centers</a></li>
                                        <li><a class="dropdown-item" href="/job_fair_job_stations.php"><i class="bi bi-geo-alt-fill me-2"></i>Job Stations</a></li>
                                        <li><a class="dropdown-item" href="/candidates_master.php"><i class="bi bi-people me-2"></i>Candidates Master</a></li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle<?= $isActive(['job_fair_reports.php', 'call_history_report.php', 'consolidated_report.php', 'consolidated_report_candidates.php', 'job_fair_exception_report.php', 'job_fair_exception_candidates.php', 'job_station_consolidated_report.php', 'joined_candidates_report.php', 'district_discrepancy_report.php', 'district_candidate_joined_status_report.php']) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-graph-up me-1"></i>Reports</a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="/job_fair_reports.php"><i class="bi bi-clipboard2-pulse me-2"></i>Over all Report</a></li>
                                    <li><a class="dropdown-item" href="/call_history_report.php"><i class="bi bi-telephone me-2"></i>Call History Report</a></li>
                                    <li><a class="dropdown-item" href="/consolidated_report.php"><i class="bi bi-clipboard-data me-2"></i>Consolidated Report</a></li>
                                    <li><a class="dropdown-item" href="/job_fair_exception_report.php"><i class="bi bi-exclamation-triangle me-2"></i>Exception Report</a></li>
                                    <li><a class="dropdown-item" href="/job_station_consolidated_report.php"><i class="bi bi-buildings me-2"></i>Job Station Consolidated Report</a></li>
                                    <li><a class="dropdown-item" href="/joined_candidates_report.php"><i class="bi bi-door-open-fill me-2"></i>Joined Candidates</a></li>
                                    <li><a class="dropdown-item" href="/district_candidate_joined_status_report.php"><i class="bi bi-geo-alt me-2"></i>District wise Candidate joined status</a></li>
                                    <li><a class="dropdown-item" href="/district_discrepancy_report.php"><i class="bi bi-exclamation-diamond me-2"></i>Discrepancy Report</a></li>
                                </ul>
                            </li>
                            <?php if (is_admin($user)): ?>
                                <li class="nav-item dropdown">
                                    <a class="nav-link dropdown-toggle<?= $isActive(['users.php', 'reports.php']) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-shield-lock me-1"></i>Administration</a>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="/users.php"><i class="bi bi-people me-2"></i>Users</a></li>
                                        <li><a class="dropdown-item" href="/reports.php"><i class="bi bi-clock-history me-2"></i>Login Reports</a></li>
                                    </ul>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>
                    </ul>
                    <div class="dropdown">
                        <button class="app-user-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="app-avatar"><?= esc(user_initials($user['name'])) ?></span>
                            <span class="app-user-meta d-none d-sm-flex">
                                <span class="name"><?= esc($user['name']) ?></span>
                                <span class="role"><?= esc(role_label($user['role'])) ?></span>
                            </span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li class="px-2 py-1">
                                <div class="fw-semibold"><?= esc($user['name']) ?></div>
                                <div class="small text-muted"><?= esc($user['mobile_number']) ?></div>
                                <?php if (!empty($user['email'])): ?>
                                    <div class="small text-muted"><?= esc($user['email']) ?></div>
                                <?php endif; ?>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="/change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                            <li><a class="dropdown-item text-danger" href="/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>
<?php endif; ?>
<main class="<?= esc($mainContainerClass) ?> py-4 flex-grow-1">
<?php
}

/**
 * Renders a consistent page header.
 *
 * @param array $options icon (bootstrap icon name), subtitle (string),
 *                        actions (raw HTML string for the right side)
 */
function render_page_header(string $title, array $options = []): void
{
    $icon = $options['icon'] ?? 'bi-grid-1x2';
    $subtitle = $options['subtitle'] ?? null;
    $actions = $options['actions'] ?? null;
    ?>
<div class="page-header">
    <div class="page-title-group">
        <span class="page-title-icon"><i class="bi <?= esc($icon) ?>"></i></span>
        <div>
            <h1><?= esc($title) ?></h1>
            <?php if ($subtitle !== null && $subtitle !== ''): ?>
                <p class="page-subtitle"><?= esc($subtitle) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($actions !== null && $actions !== ''): ?>
        <div class="page-actions"><?= $actions ?></div>
    <?php endif; ?>
</div>
<?php
}

/**
 * Standard pagination block used across listing pages. Renders a left-aligned
 * "Showing X-Y of Z records" status and a right-aligned pagination control
 * with First, Previous, a windowed page number list (current ± 2 with
 * ellipses), Next and Last buttons. Preserves the supplied $baseParams (e.g.
 * filters) in every generated URL.
 */
function render_pagination(
    int $currentPage,
    int $totalPages,
    int $totalRecords,
    int $perPage,
    string $baseUrl,
    array $baseParams = [],
    string $ariaLabel = 'Pagination'
): void {
    if ($totalRecords <= 0) {
        return;
    }
    $totalPages = max(1, $totalPages);
    $currentPage = max(1, min($currentPage, $totalPages));
    $startRecord = ($currentPage - 1) * $perPage + 1;
    $endRecord = min($startRecord + $perPage - 1, $totalRecords);

    $build = static function (int $p) use ($baseUrl, $baseParams): string {
        unset($baseParams['page']);
        return $baseUrl . '?' . http_build_query(array_merge($baseParams, ['page' => $p]));
    };

    $pages = [];
    if ($totalPages <= 7) {
        for ($p = 1; $p <= $totalPages; $p++) {
            $pages[] = $p;
        }
    } else {
        $pages[] = 1;
        $windowStart = max(2, $currentPage - 2);
        $windowEnd = min($totalPages - 1, $currentPage + 2);
        if ($windowStart > 2) {
            $pages[] = '...';
        }
        for ($p = $windowStart; $p <= $windowEnd; $p++) {
            $pages[] = $p;
        }
        if ($windowEnd < $totalPages - 1) {
            $pages[] = '...';
        }
        $pages[] = $totalPages;
    }
    ?>
<nav aria-label="<?= esc($ariaLabel) ?>" class="d-flex flex-wrap justify-content-between align-items-center gap-2 my-3">
    <div class="data-meta">
        Showing <strong><?= number_format($startRecord) ?></strong>&ndash;<strong><?= number_format($endRecord) ?></strong> of <strong><?= number_format($totalRecords) ?></strong> records
        &middot; page <strong><?= number_format($currentPage) ?></strong> of <strong><?= number_format($totalPages) ?></strong>
    </div>
    <ul class="pagination mb-0">
        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $currentPage <= 1 ? '#' : esc($build(1)) ?>" aria-label="First"><i class="bi bi-chevron-double-left"></i></a>
        </li>
        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $currentPage <= 1 ? '#' : esc($build($currentPage - 1)) ?>" aria-label="Previous"><i class="bi bi-chevron-left"></i></a>
        </li>
        <?php foreach ($pages as $p): ?>
            <?php if ($p === '...'): ?>
                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
            <?php else: ?>
                <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                    <a class="page-link" href="<?= esc($build((int) $p)) ?>"><?= (int) $p ?></a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $currentPage >= $totalPages ? '#' : esc($build($currentPage + 1)) ?>" aria-label="Next"><i class="bi bi-chevron-right"></i></a>
        </li>
        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $currentPage >= $totalPages ? '#' : esc($build($totalPages)) ?>" aria-label="Last"><i class="bi bi-chevron-double-right"></i></a>
        </li>
    </ul>
</nav>
<?php
}

/**
 * Maps a free-form status string (Yes / No / Pending / Selected / Shortlisted
 * / Onhold / etc.) to one of the status-chip colour classes defined in
 * assets/css/app.css. Unknown values get the neutral grey chip.
 */
function status_chip_class(?string $value): string
{
    $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $value) ?? '');
    return match ($key) {
        'selected' => 'status-selected',
        'shortlisted' => 'status-shortlisted',
        'onhold' => 'status-onhold',
        'rejected', 'candidatenotinterested', 'candidatenotwilling' => 'status-rejected',
        'yes' => 'status-yes',
        'no' => 'status-no',
        'pending', 'yettobecontacted' => 'status-pending',
        'futuredate', 'reviewinprogress', 'selectedfornextround', 'ongoing', 'completed' => 'status-info',
        default => 'status-neutral',
    };
}

/**
 * Renders a status chip, or a muted dash if the value is empty.
 */
function render_status_chip(?string $value, ?string $prefix = null): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '<span class="text-muted">&mdash;</span>';
    }
    $prefixHtml = $prefix === null || $prefix === '' ? '' : '<span class="small text-muted me-1">' . esc($prefix) . '</span>';
    return $prefixHtml . '<span class="status-chip ' . esc(status_chip_class($value)) . '">' . esc($value) . '</span>';
}

/**
 * Renders a fixed bottom-right toast on every page when the current user has
 * unseen notifications or new replies on notifications they have already
 * viewed. Suppresses itself on the notifications page (where the user is
 * already looking at the same data) and for unauthenticated visitors.
 */
function fetch_and_render_notification_toast(): void
{
    $user = current_user();
    if (!$user) {
        return;
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === 'notifications.php' || $script === 'index.php' || $script === 'logout.php') {
        return;
    }

    $uid = (int) ($user['id'] ?? 0);
    $role = (string) ($user['role'] ?? '');
    $rolesByToWhom = ['State CRM' => 'crm_member', 'State DSM' => 'state_dsm', 'District User' => 'district_user'];
    $roleToWhom = array_search($role, $rolesByToWhom, true) ?: '';

    $unseen = 0;
    $newReplies = 0;
    try {
        // Strict audience filter: only notifications the user actually receives
        // (not counting ones they own) AND has not yet seen.
        $sql = "SELECT COUNT(*)
            FROM activities a
            LEFT JOIN activity_seen s ON s.activity_id = a.id AND s.user_id = ?
            WHERE a.active_status = 1 AND a.status <> 'closed'
              AND a.owner_user_id <> ?
              AND s.activity_id IS NULL
              AND a.to_whom = ?
              AND (a.read_by = 'any' OR a.target_user_id = ?)";
        $st = db()->prepare($sql);
        $st->execute([$uid, $uid, $roleToWhom, $uid]);
        $unseen = (int) $st->fetchColumn();

        // Replies posted by someone else after the current user's last view of
        // that notification.
        $rstmt = db()->prepare(
            "SELECT COUNT(*)
             FROM activity_replies r
             JOIN activity_seen s ON s.activity_id = r.activity_id AND s.user_id = ?
             WHERE r.user_id <> ?
               AND r.created_at > s.seen_at"
        );
        $rstmt->execute([$uid, $uid]);
        $newReplies = (int) $rstmt->fetchColumn();
    } catch (Throwable $e) {
        // Tables may not exist yet (first request before notifications.php is
        // touched). Stay quiet rather than throw.
        return;
    }

    if ($unseen + $newReplies <= 0) {
        return;
    }

    $lines = [];
    if ($unseen > 0) {
        $lines[] = '<div><i class="bi bi-envelope me-1"></i><strong>' . (int) $unseen . '</strong> unseen notification' . ($unseen === 1 ? '' : 's') . '</div>';
    }
    if ($newReplies > 0) {
        $lines[] = '<div><i class="bi bi-chat-left-text me-1"></i><strong>' . (int) $newReplies . '</strong> new repl' . ($newReplies === 1 ? 'y' : 'ies') . '</div>';
    }
    ?>
    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
        <div id="notificationAlertToast" class="toast" role="alert" aria-live="polite" aria-atomic="true">
            <div class="toast-header">
                <i class="bi bi-bell-fill text-warning me-2"></i>
                <strong class="me-auto">You have new activity</strong>
                <small class="text-muted">just now</small>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                <?= implode('', $lines) ?>
                <a href="/notifications.php" class="btn btn-sm btn-primary mt-2"><i class="bi bi-arrow-right me-1"></i>Open Notifications</a>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const el = document.getElementById('notificationAlertToast');
        if (el && typeof bootstrap !== 'undefined' && bootstrap?.Toast) {
            bootstrap.Toast.getOrCreateInstance(el, { autohide: false }).show();
        }
    });
    </script>
    <?php
}

function render_footer(bool $showFooter = true): void
{
    fetch_and_render_notification_toast();
    ?>
</main>
<?php if ($showFooter): ?>
<footer class="app-footer mt-auto">
    <div class="container d-flex flex-column flex-sm-row justify-content-between align-items-center gap-1">
        <span class="footer-text">&copy; <?= date('Y') ?> Job Fair CRM · KDISC Post Job Fair Tracker</span>
        <span class="footer-text">Internal use only</span>
    </div>
</footer>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const clearStaleBackdrop = () => {
        if (document.querySelector('.modal.show')) {
            return;
        }

        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove());
    };

    clearStaleBackdrop();
    document.addEventListener('hidden.bs.modal', clearStaleBackdrop);
});
</script>
</body>
</html>
<?php
}

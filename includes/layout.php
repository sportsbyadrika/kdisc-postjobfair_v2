<?php
require_once __DIR__ . '/auth.php';

function role_label(string $role): string
{
    return match ($role) {
        'administrator' => 'Administrator',
        'crm_member' => 'CRM Member',
        'district_user' => 'District User',
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
                            <li class="nav-item"><a class="nav-link<?= $isActive(['phone_directory.php']) ?>" href="/phone_directory.php"><i class="bi bi-person-rolodex me-1"></i>Phone Directory</a></li>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle<?= $isActive(['district_data.php', 'district_consolidated_report.php', 'job_station_consolidated_report.php', 'district_candidate_data.php']) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-geo-alt me-1"></i>District Data</a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="/district_data.php"><i class="bi bi-bar-chart-line me-2"></i>District Overview</a></li>
                                    <li><a class="dropdown-item" href="/district_consolidated_report.php"><i class="bi bi-clipboard-data me-2"></i>Consolidated Report</a></li>
                                    <li><a class="dropdown-item" href="/job_station_consolidated_report.php"><i class="bi bi-buildings me-2"></i>Job Station Report</a></li>
                                    <li><a class="dropdown-item" href="/district_candidate_data.php"><i class="bi bi-people me-2"></i>Candidate Data</a></li>
                                </ul>
                            </li>
                        <?php else: ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle<?= $isActive(['job_fair_results.php', 'activities.php', 'job_fair_result_upload.php', 'job_fair_result_full_upload.php', 'aggregator_offer_letter_upload.php', 'job_fair_results_export.php', 'manage_candidate.php']) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-clipboard2-data me-1"></i>Job Fair</a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="/job_fair_results.php"><i class="bi bi-table me-2"></i>Job Fair Result Data</a></li>
                                    <li><a class="dropdown-item" href="/activities.php"><i class="bi bi-list-task me-2"></i>Activities</a></li>
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
                                <a class="nav-link dropdown-toggle<?= $isActive(['phone_directory.php', 'job_fair_masters.php']) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-sliders me-1"></i>Masters</a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="/phone_directory.php"><i class="bi bi-person-rolodex me-2"></i>Phone Directory</a></li>
                                    <li><a class="dropdown-item" href="/job_fair_masters.php"><i class="bi bi-diagram-3 me-2"></i>Employer and SPOC Mapping</a></li>
                                </ul>
                            </li>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle<?= $isActive(['job_fair_reports.php', 'call_history_report.php', 'consolidated_report.php', 'consolidated_report_candidates.php', 'job_fair_exception_report.php', 'job_fair_exception_candidates.php', 'job_station_consolidated_report.php']) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-graph-up me-1"></i>Reports</a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="/job_fair_reports.php"><i class="bi bi-clipboard2-pulse me-2"></i>Over all Report</a></li>
                                    <li><a class="dropdown-item" href="/call_history_report.php"><i class="bi bi-telephone me-2"></i>Call History Report</a></li>
                                    <li><a class="dropdown-item" href="/consolidated_report.php"><i class="bi bi-clipboard-data me-2"></i>Consolidated Report</a></li>
                                    <li><a class="dropdown-item" href="/job_fair_exception_report.php"><i class="bi bi-exclamation-triangle me-2"></i>Exception Report</a></li>
                                    <li><a class="dropdown-item" href="/job_station_consolidated_report.php"><i class="bi bi-buildings me-2"></i>Job Station Consolidated Report</a></li>
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

function render_footer(bool $showFooter = true): void
{
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

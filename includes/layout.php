<?php
require_once __DIR__ . '/auth.php';

function render_header(string $title, array $options = []): void
{
    $showNavigation = $options['show_navigation'] ?? true;
    $mainContainerClass = $options['main_container_class'] ?? 'container';
    $user = current_user();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="d-flex flex-column min-vh-100">
<?php if ($showNavigation): ?>
    <nav class="navbar navbar-expand-lg navbar-light navbar-silver shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center gap-2" href="/dashboard.php">
                <span class="brand-icon">🚀</span>
                <span>Job Fair Status Tracker</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <?php if ($user): ?>
                    <?php $isDistrictUser = is_district_user($user); ?>
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        <li class="nav-item"><a class="nav-link" href="/dashboard.php">Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="/phone_directory.php">Phone Directory</a></li>
                        <?php if ($isDistrictUser): ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">District Data</a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="/district_data.php">District Data</a></li>
                                    <li><a class="dropdown-item" href="/district_consolidated_report.php">District Consolidated Reports</a></li>
                                    <li><a class="dropdown-item" href="/job_station_consolidated_report.php">Job Station Consolidated Report</a></li>
                                    <li><a class="dropdown-item" href="/district_candidate_data.php">Candidate Data</a></li>
                                </ul>
                            </li>
                        <?php else: ?>
                            <li class="nav-item"><a class="nav-link" href="/activities.php">Activities</a></li>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Job fair</a>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="/job_fair_results.php">Job fair result data</a></li>
                                    <li><a class="dropdown-item" href="/call_history_report.php">Call history report</a></li>
                                    <li><a class="dropdown-item" href="/job_fair_reports.php">Reports</a></li>
                                    <li><a class="dropdown-item" href="/consolidated_report.php">Consolidated Report</a></li>
                                    <li><a class="dropdown-item" href="/job_fair_exception_report.php">Exception report</a></li>
                                    <li><a class="dropdown-item" href="/job_station_consolidated_report.php">Job Station Consolidated Report</a></li>
                                    <li><a class="dropdown-item" href="/job_fair_masters.php">Masters</a></li>
                                    <?php if (is_admin($user)): ?>
                                        <li><a class="dropdown-item" href="/job_fair_result_upload.php">Upload job fair result CSV</a></li>
                                        <li><a class="dropdown-item" href="/aggregator_offer_letter_upload.php">Upload aggregator selected data CSV</a></li>
                                        <li><a class="dropdown-item" href="/job_fair_results_export.php">Download job fair result CSV</a></li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                            <?php if (is_admin($user)): ?>
                                <li class="nav-item dropdown">
                                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">User Management</a>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="/users.php">Users</a></li>
                                        <li><a class="dropdown-item" href="/reports.php">Login Reports</a></li>
                                    </ul>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>
                    </ul>
                    <div class="dropdown">
                        <button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <?= esc($user['name']) ?> (<?= esc($user['mobile_number']) ?>)
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-item-text small text-muted"><?= esc($user['email']) ?></span></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="/change_password.php">Change Password</a></li>
                            <li><a class="dropdown-item" href="/logout.php">Logout</a></li>
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

function render_footer(bool $showFooter = true): void
{
    ?>
</main>
<?php if ($showFooter): ?>
<footer class="bg-light border-top py-3 mt-auto">
    <div class="container text-center text-muted small">© <?= date('Y') ?> Job Fair Status Tracker</div>
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

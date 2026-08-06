<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/demand_side_helpers.php';
require_admin();
demand_side_bootstrap();

$employerIdFilter = trim((string) ($_GET['employer_id'] ?? ''));
$employerNameFilter = trim((string) ($_GET['employer_name'] ?? ''));
$activeStatusFilter = trim((string) ($_GET['active_status'] ?? ''));
$finalStatusFilter = trim((string) ($_GET['final_status'] ?? ''));
$page = max((int) ($_GET['page'] ?? 1), 1);
$perPage = 25;

$conds = ['1=1'];
$params = [];
if ($employerIdFilter !== '' && ctype_digit($employerIdFilter)) {
    $conds[] = 'employer_id = ?';
    $params[] = (int) $employerIdFilter;
}
if ($employerNameFilter !== '') {
    $conds[] = 'employer_name LIKE ?';
    $params[] = '%' . $employerNameFilter . '%';
}
if ($activeStatusFilter !== '') {
    $conds[] = 'active_status = ?';
    $params[] = $activeStatusFilter;
}
if ($finalStatusFilter !== '') {
    $conds[] = 'final_status = ?';
    $params[] = $finalStatusFilter;
}
$whereSql = 'WHERE ' . implode(' AND ', $conds);

$countStmt = db()->prepare("SELECT COUNT(*) FROM demand_employers $whereSql");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max((int) ceil($totalRecords / $perPage), 1);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = db()->prepare("SELECT e.*, (SELECT COUNT(*) FROM demand_employer_jobs j WHERE j.emp_id = e.employer_id) AS jobs_count
    FROM demand_employers e
    $whereSql
    ORDER BY employer_id ASC
    LIMIT ? OFFSET ?");
$listStmt->execute([...$params, $perPage, $offset]);
$rows = $listStmt->fetchAll();

$activeStatusOptions = demand_employer_active_status_options();
$finalStatusOptions = array_map(
    static fn(array $r): string => (string) $r['value'],
    db()->query("SELECT DISTINCT final_status AS value FROM demand_employers WHERE final_status IS NOT NULL AND TRIM(final_status) <> '' ORDER BY value ASC")->fetchAll()
);

$baseParams = $_GET;
unset($baseParams['page']);

render_header('Employer', ['main_container_class' => 'container-fluid']);
render_page_header('Demand Side · Employer', [
    'icon' => 'bi-building',
    'subtitle' => 'Master list of employers and their jobs. Employer Jobs link to Employers via emp_id → employer_id. Admin / State DSM can review, edit and upload.',
    'actions' => '<a class="btn btn-light me-1" href="/demand_side_upload.php"><i class="bi bi-upload me-1"></i>Upload Data</a>'
        . '<a class="btn btn-light" href="/demand_side_stats.php"><i class="bi bi-bar-chart-line me-1"></i>Statistics</a>',
]);
?>

<form method="get" class="card mb-3">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-funnel text-primary me-1"></i>Filters</h2>
        <div class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Employer ID</label>
                <input type="text" class="form-control" name="employer_id" value="<?= esc($employerIdFilter) ?>" placeholder="numeric id">
            </div>
            <div class="col-md-4">
                <label class="form-label">Employer Name</label>
                <input type="text" class="form-control" name="employer_name" value="<?= esc($employerNameFilter) ?>" placeholder="Search name">
            </div>
            <div class="col-md-2">
                <label class="form-label">Active Status</label>
                <select class="form-select" name="active_status">
                    <option value="">All</option>
                    <?php foreach ($activeStatusOptions as $opt): ?>
                        <option value="<?= esc($opt) ?>" <?= $activeStatusFilter === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Final Status</label>
                <select class="form-select" name="final_status">
                    <option value="">All</option>
                    <?php foreach ($finalStatusOptions as $opt): ?>
                        <option value="<?= esc($opt) ?>" <?= $finalStatusFilter === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
                <a class="btn btn-light" href="/demand_side_employers.php">Reset</a>
            </div>
        </div>
    </div>
</form>

<?php render_pagination($page, $totalPages, $totalRecords, $perPage, '/demand_side_employers.php', $baseParams, 'Employers pagination'); ?>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-building text-primary me-1"></i>Employers</span>
        <span class="status-chip status-info"><?= number_format($totalRecords) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Employer ID</th>
                    <th>Employer Name</th>
                    <th>Job Agency</th>
                    <th>Type</th>
                    <th class="text-end">Jobs</th>
                    <th>Active Status</th>
                    <th>Final Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9"><div class="empty-state"><i class="bi bi-inbox"></i>No employers match the filters.</div></td></tr>
                <?php endif; ?>
                <?php $idx = $offset + 1; foreach ($rows as $row): ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td><?= esc((string) ($row['employer_id'] ?? '')) ?></td>
                        <td class="fw-semibold"><?= esc((string) ($row['employer_name'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['jobagency'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['type_of_company'] ?? '')) ?></td>
                        <td class="text-end"><?= number_format((int) ($row['jobs_count'] ?? 0)) ?></td>
                        <td><?= render_status_chip((string) ($row['active_status'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['final_status'] ?? '')) ?></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a class="btn btn-sm btn-outline-secondary" href="/demand_side_employer_edit.php?id=<?= (int) $row['id'] ?>&mode=view"><i class="bi bi-eye"></i> View</a>
                                <a class="btn btn-sm btn-outline-primary" href="/demand_side_employer_edit.php?id=<?= (int) $row['id'] ?>&mode=edit"><i class="bi bi-pencil-square"></i> Edit</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_pagination($page, $totalPages, $totalRecords, $perPage, '/demand_side_employers.php', $baseParams, 'Employers pagination'); ?>

<?php render_footer(); ?>

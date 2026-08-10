<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/demand_side_helpers.php';
require_admin();
demand_side_bootstrap();

$employerIdFilter   = trim((string) ($_GET['employer_id']   ?? ''));
$employerNameFilter = trim((string) ($_GET['employer_name'] ?? ''));
$activeStatusFilter = trim((string) ($_GET['active_status'] ?? ''));
$finalStatusFilter  = trim((string) ($_GET['final_status']  ?? ''));
$jobIdFilter        = trim((string) ($_GET['job_id']        ?? ''));
$jobAgencyFilter    = trim((string) ($_GET['jobagency']     ?? ''));
$openMinFilter      = trim((string) ($_GET['open_min']      ?? ''));
$openMaxFilter      = trim((string) ($_GET['open_max']      ?? ''));
$categoryFilter     = trim((string) ($_GET['category']      ?? ''));
$categories         = demand_get_categories();
$categoryByName     = [];
foreach ($categories as $c) { $categoryByName[(string) $c['name']] = $c; }
$page = max((int) ($_GET['page'] ?? 1), 1);
$perPage = 25;

$conds = ['1=1'];
$params = [];

// Per-user employer scope: any user (administrator included) with one or
// more rows in demand_user_employer_assignments has their Employer listing
// restricted to that set of employer_ids. Users with no assignment rows
// see the full list — so an admin who hasn't been given a scope keeps
// seeing everything, matching backwards-compatible behaviour.
$viewerRow = current_user();
$viewerId = (int) ($viewerRow['id'] ?? 0);
$viewerIsAdministrator = (($viewerRow['role'] ?? '') === 'administrator');
$scopedEmployerIds = demand_get_assigned_employer_ids($viewerId);
$scopeActive = false;
if ($scopedEmployerIds !== []) {
    $scopeActive = true;
    $ph = implode(',', array_fill(0, count($scopedEmployerIds), '?'));
    $conds[] = "e.employer_id IN ($ph)";
    foreach ($scopedEmployerIds as $sid) { $params[] = $sid; }
}
if ($employerIdFilter !== '' && ctype_digit($employerIdFilter)) {
    $conds[] = 'e.employer_id = ?';
    $params[] = (int) $employerIdFilter;
}
if ($employerNameFilter !== '') {
    $conds[] = 'e.employer_name LIKE ?';
    $params[] = '%' . $employerNameFilter . '%';
}
if ($activeStatusFilter !== '') {
    $conds[] = 'e.active_status = ?';
    $params[] = $activeStatusFilter;
}
if ($finalStatusFilter !== '') {
    $conds[] = 'e.final_status = ?';
    $params[] = $finalStatusFilter;
}
if ($jobAgencyFilter !== '') {
    $conds[] = 'e.jobagency = ?';
    $params[] = $jobAgencyFilter;
}
if ($jobIdFilter !== '' && ctype_digit($jobIdFilter)) {
    // Employers that have at least one Job with this job_id.
    $conds[] = 'EXISTS (SELECT 1 FROM demand_employer_jobs j2 WHERE j2.emp_id = e.employer_id AND j2.job_id = ?)';
    $params[] = (int) $jobIdFilter;
}
// Open positions range filter runs against the aggregated
// total_open_positions produced by the LEFT JOIN subquery. Employers with
// no jobs (aggregate is NULL -> 0 via COALESCE) get filtered out whenever
// any bound is set, which matches the user's intent of "between X and Y".
if ($openMinFilter !== '' && ctype_digit($openMinFilter)) {
    $conds[] = 'COALESCE(j.total_open_positions, 0) >= ?';
    $params[] = (int) $openMinFilter;
}
if ($openMaxFilter !== '' && ctype_digit($openMaxFilter)) {
    $conds[] = 'COALESCE(j.total_open_positions, 0) <= ?';
    $params[] = (int) $openMaxFilter;
}
// Category filter — derived from the same total_open_positions aggregate,
// so an employer belongs to whichever category range contains its total.
if ($categoryFilter !== '' && isset($categoryByName[$categoryFilter])) {
    $c = $categoryByName[$categoryFilter];
    $conds[] = 'COALESCE(j.total_open_positions, 0) BETWEEN ? AND ?';
    $params[] = (int) $c['min_positions'];
    $params[] = (int) $c['max_positions'];
}
$whereSql = 'WHERE ' . implode(' AND ', $conds);

// Shared FROM clause — used by both the count query and the listing query so
// filters that reference the aggregated jobs subquery (Open Positions range)
// work uniformly.
$fromSql = "FROM demand_employers e
    LEFT JOIN (
        SELECT emp_id, COUNT(*) AS jobs_count, SUM(open_positions) AS total_open_positions
        FROM demand_employer_jobs
        GROUP BY emp_id
    ) j ON j.emp_id = e.employer_id
    $whereSql";

/* ------------------------------------------------------------------------- *
 * CSV download — respects every active filter (and the per-user scope
 * applied above), streams the full filtered set (no pagination) and
 * includes the emp_id-joined Jobs + Open Positions aggregates so the
 * export lines up with what the header chips show.
 * ------------------------------------------------------------------------- */
if (($_GET['download'] ?? '') === 'csv') {
    $exportSql = "SELECT
            e.employer_id,
            e.clustered_employer_id,
            e.employer_name,
            e.clusteremployername,
            e.website,
            e.company_address,
            e.job_agency_id,
            e.jobagency,
            e.jobfair_flag,
            e.vk_flag,
            e.type_of_company,
            e.nic_section_code, e.nic_section_name,
            e.nic_division_code, e.nic_division_name,
            e.nic_group_code, e.nic_group_name,
            e.nic_class_code, e.nic_class_name,
            e.nic_sub_class_code, e.nic_sub_class_name,
            e.reason_for_classification,
            e.active_status,
            e.final_status,
            e.remarks,
            e.created_datetime,
            COALESCE(j.jobs_count, 0) AS jobs_count,
            COALESCE(j.total_open_positions, 0) AS total_open_positions
        $fromSql
        ORDER BY e.employer_id ASC";
    $exportStmt = db()->prepare($exportSql);
    $exportStmt->execute($params);

    $filename = 'demand_employers_' . date('Ymd_His') . '.csv';
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens Indian-language names correctly.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'employer_id', 'clustered_employer_id', 'employer_name', 'clusteremployername',
        'website', 'company_address', 'job_agency_id', 'jobagency', 'jobfair_flag', 'vk_flag',
        'type_of_company',
        'nic_section_code', 'nic_section_name',
        'nic_division_code', 'nic_division_name',
        'nic_group_code', 'nic_group_name',
        'nic_class_code', 'nic_class_name',
        'nic_sub_class_code', 'nic_sub_class_name',
        'reason_for_classification',
        'active_status', 'final_status', 'remarks',
        'created_datetime',
        'jobs_count', 'total_open_positions', 'category',
    ]);
    while ($er = $exportStmt->fetch()) {
        $er['__cat'] = demand_category_for_positions((int) ($er['total_open_positions'] ?? 0), $categories);
        fputcsv($out, [
            (string) ($er['employer_id'] ?? ''),
            (string) ($er['clustered_employer_id'] ?? ''),
            (string) ($er['employer_name'] ?? ''),
            (string) ($er['clusteremployername'] ?? ''),
            (string) ($er['website'] ?? ''),
            (string) ($er['company_address'] ?? ''),
            (string) ($er['job_agency_id'] ?? ''),
            (string) ($er['jobagency'] ?? ''),
            (string) ($er['jobfair_flag'] ?? ''),
            (string) ($er['vk_flag'] ?? ''),
            (string) ($er['type_of_company'] ?? ''),
            (string) ($er['nic_section_code'] ?? ''),
            (string) ($er['nic_section_name'] ?? ''),
            (string) ($er['nic_division_code'] ?? ''),
            (string) ($er['nic_division_name'] ?? ''),
            (string) ($er['nic_group_code'] ?? ''),
            (string) ($er['nic_group_name'] ?? ''),
            (string) ($er['nic_class_code'] ?? ''),
            (string) ($er['nic_class_name'] ?? ''),
            (string) ($er['nic_sub_class_code'] ?? ''),
            (string) ($er['nic_sub_class_name'] ?? ''),
            (string) ($er['reason_for_classification'] ?? ''),
            (string) ($er['active_status'] ?? ''),
            (string) ($er['final_status'] ?? ''),
            (string) ($er['remarks'] ?? ''),
            (string) ($er['created_datetime'] ?? ''),
            (string) ($er['jobs_count'] ?? 0),
            (string) ($er['total_open_positions'] ?? 0),
            (string) ($er['__cat'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

$countStmt = db()->prepare("SELECT COUNT(*) $fromSql");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();

// Total open positions across the filtered employer set, using the same
// FROM/WHERE the listing does so the header chip stays consistent with
// pagination.
$vacTotalStmt = db()->prepare("SELECT COALESCE(SUM(COALESCE(j.total_open_positions, 0)), 0) $fromSql");
$vacTotalStmt->execute($params);
$totalVacancies = (int) $vacTotalStmt->fetchColumn();
$totalPages = max((int) ceil($totalRecords / $perPage), 1);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = db()->prepare("SELECT e.*, COALESCE(j.jobs_count, 0) AS jobs_count, COALESCE(j.total_open_positions, 0) AS total_open_positions
    $fromSql
    ORDER BY e.employer_id ASC
    LIMIT ? OFFSET ?");
$listStmt->execute([...$params, $perPage, $offset]);
$rows = $listStmt->fetchAll();

// Diagnostic: Employer Jobs whose emp_id doesn't match any Employer.
// Shown only to unscoped administrators — the orphan count is a global
// data-quality signal that would misleadingly compare against a filtered
// listing for anyone with an assignment scope in effect.
$orphanCount = 0;
if ($viewerIsAdministrator && !$scopeActive) {
    $orphanCount = (int) db()->query('SELECT COUNT(*) FROM demand_employer_jobs j
        LEFT JOIN demand_employers e ON e.employer_id = j.emp_id
        WHERE e.employer_id IS NULL')->fetchColumn();
}

$activeStatusOptions = demand_employer_active_status_options();
$finalStatusOptions = array_map(
    static fn(array $r): string => (string) $r['value'],
    db()->query("SELECT DISTINCT final_status AS value FROM demand_employers WHERE final_status IS NOT NULL AND TRIM(final_status) <> '' ORDER BY value ASC")->fetchAll()
);
$jobAgencyOptions = array_map(
    static fn(array $r): string => (string) $r['value'],
    db()->query("SELECT DISTINCT jobagency AS value FROM demand_employers WHERE jobagency IS NOT NULL AND TRIM(jobagency) <> '' ORDER BY value ASC")->fetchAll()
);

$baseParams = $_GET;
unset($baseParams['page']);

$currentUserForActions = current_user();
$isAdministrator = (($currentUserForActions['role'] ?? '') === 'administrator');

// Download URL preserves every active filter so the CSV reflects exactly
// what the user is looking at.
$downloadParams = $_GET;
unset($downloadParams['page']);
$downloadParams['download'] = 'csv';
$downloadUrl = '/demand_side_employers.php?' . http_build_query(array_filter(
    $downloadParams,
    static fn($v): bool => $v !== '' && $v !== null
));

$actionsHtml = '';
if ($isAdministrator) {
    $actionsHtml .= '<a class="btn btn-light me-1" href="/demand_side_upload.php"><i class="bi bi-upload me-1"></i>Upload Data</a>';
    $actionsHtml .= '<a class="btn btn-light me-1" href="/demand_side_assignments.php"><i class="bi bi-people-arrows me-1"></i>Assign Employers</a>';
}
$actionsHtml .= '<a class="btn btn-light me-1" href="' . esc($downloadUrl) . '"><i class="bi bi-download me-1"></i>Download CSV</a>';
$actionsHtml .= '<a class="btn btn-light" href="/demand_side_stats.php"><i class="bi bi-bar-chart-line me-1"></i>Statistics</a>';

render_header('Employer', ['main_container_class' => 'container-fluid']);
render_page_header('Demand Side · Employer', [
    'icon' => 'bi-building',
    'subtitle' => 'Master list of employers and their jobs. Employer Jobs link to Employers via emp_id → employer_id. Admin / State DSM can review and edit; only Administrator can upload.',
    'actions' => $actionsHtml,
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
            <div class="col-md-3">
                <label class="form-label">Employer Name</label>
                <input type="text" class="form-control" name="employer_name" value="<?= esc($employerNameFilter) ?>" placeholder="Search name">
            </div>
            <div class="col-md-2">
                <label class="form-label">Job Agency</label>
                <select class="form-select" name="jobagency">
                    <option value="">All</option>
                    <?php foreach ($jobAgencyOptions as $opt): ?>
                        <option value="<?= esc($opt) ?>" <?= $jobAgencyFilter === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Job ID <span class="small text-muted">(in jobs)</span></label>
                <input type="text" class="form-control" name="job_id" value="<?= esc($jobIdFilter) ?>" placeholder="numeric id">
            </div>
            <div class="col-md-3">
                <label class="form-label">Open Positions <span class="small text-muted">(per employer total)</span></label>
                <div class="input-group">
                    <input type="number" min="0" class="form-control" name="open_min" value="<?= esc($openMinFilter) ?>" placeholder="min">
                    <span class="input-group-text">to</span>
                    <input type="number" min="0" class="form-control" name="open_max" value="<?= esc($openMaxFilter) ?>" placeholder="max">
                </div>
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
            <div class="col-md-2">
                <label class="form-label">Category</label>
                <select class="form-select" name="category">
                    <option value="">All</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= esc((string) $c['name']) ?>" <?= $categoryFilter === (string) $c['name'] ? 'selected' : '' ?>><?= esc((string) $c['name']) ?> (<?= number_format((int) $c['min_positions']) ?>–<?= number_format((int) $c['max_positions']) ?>)</option>
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

<?php if ($scopeActive): ?>
    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="bi bi-info-circle-fill mt-1"></i>
        <div>
            You are viewing an assigned subset: <strong><?= number_format(count($scopedEmployerIds)) ?> employer(s)</strong> out of the full master list. Contact the Administrator to change your assignment.
        </div>
    </div>
<?php endif; ?>

<?php if ($orphanCount > 0): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>
            <strong><?= number_format($orphanCount) ?> Employer Jobs</strong> have an <code>emp_id</code> that doesn't match any <code>employer_id</code> in the Employer table. These orphan rows won't appear under any employer here.
            Re-upload the missing Employers first, or update the Jobs CSV so the <code>emp_id</code> values match.
        </div>
    </div>
<?php endif; ?>

<?php render_pagination($page, $totalPages, $totalRecords, $perPage, '/demand_side_employers.php', $baseParams, 'Employers pagination'); ?>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-building text-primary me-1"></i>Employers</span>
        <div class="d-flex gap-2">
            <span class="status-chip status-info"><?= number_format($totalRecords) ?> records</span>
            <span class="status-chip status-success"><?= number_format($totalVacancies) ?> vacancies</span>
        </div>
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
                    <th class="text-end">Open Positions</th>
                    <th>Category</th>
                    <th>Active Status</th>
                    <th>Final Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="11"><div class="empty-state"><i class="bi bi-inbox"></i>No employers match the filters.</div></td></tr>
                <?php endif; ?>
                <?php $idx = $offset + 1; foreach ($rows as $row): ?>
                    <?php $jobsCount = (int) ($row['jobs_count'] ?? 0); ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td><?= esc((string) ($row['employer_id'] ?? '')) ?></td>
                        <td class="fw-semibold"><?= esc((string) ($row['employer_name'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['jobagency'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['type_of_company'] ?? '')) ?></td>
                        <td class="text-end">
                            <?php if ($jobsCount > 0): ?>
                                <a href="/demand_side_employer_edit.php?id=<?= (int) $row['id'] ?>&mode=view#jobs" title="View jobs"><?= number_format($jobsCount) ?></a>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= number_format((int) ($row['total_open_positions'] ?? 0)) ?></td>
                        <?php $rowCat = demand_category_for_positions((int) ($row['total_open_positions'] ?? 0), $categories); ?>
                        <td><?= $rowCat !== '' ? '<span class="status-chip status-info">' . esc($rowCat) . '</span>' : '<span class="text-muted">&mdash;</span>' ?></td>
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

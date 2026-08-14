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

// Per-user scope: only Administrator sees every employer. Every other role
// (State DSM, DSM Admin, CRM, District User, …) is scoped to the union of
// their employer-scope assignments and employer_ids reached via job-scope
// assignments. No assignments = empty result (previously "see everything").
$viewerRow = current_user();
$viewerId = (int) ($viewerRow['id'] ?? 0);
$viewerIsAdministrator = (($viewerRow['role'] ?? '') === 'administrator');
$scopedFromEmployers = demand_get_assigned_employer_ids($viewerId);
$scopedFromJobs      = demand_get_employer_ids_from_assigned_jobs($viewerId);
$scopedEmployerIds   = array_values(array_unique(array_merge($scopedFromEmployers, $scopedFromJobs)));
$scopeActive = false;
if (!$viewerIsAdministrator) {
    $scopeActive = true;
    if ($scopedEmployerIds === []) {
        // Non-admin without any assignments — hide everything until an
        // administrator (or DSM Admin) gives them a scope.
        $conds[] = '1=0';
    } else {
        $ph = implode(',', array_fill(0, count($scopedEmployerIds), '?'));
        $conds[] = "e.employer_id IN ($ph)";
        foreach ($scopedEmployerIds as $sid) { $params[] = $sid; }
    }
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

// Reusable scope filter fragments. The listing narrows the per-employer
// aggregates (jobs count, open positions, edited count) to what the viewer
// can actually see, so the numbers on the row match the drill-down:
//   - employer where the user has ANY specific assigned jobs → only those
//     specific jobs count (specific-job assignment always narrows, even
//     when the employer is also in the user's employer scope);
//   - employer in employer-scope with NO overlapping job assignments
//     → all jobs of that employer count;
//   - employer reached only via job-scope → only the assigned jobs of
//     that employer count (this is the same rule as the first bullet).
$viewerAssignedJobs = ($scopeActive && $scopedEmployerIds !== []) ? demand_get_assigned_job_ids($viewerId) : [];
$scopeFilterUnq    = null;   // WHERE fragment for scans of demand_employer_jobs (unqualified columns)
$scopeFilterQual   = null;   // same, but with `j.` alias for subqueries that JOIN
$scopeFilterParams = [];     // params for either fragment — SAME set of values in the SAME order
if ($viewerAssignedJobs !== []) {
    $phj = implode(',', array_fill(0, count($viewerAssignedJobs), '?'));
    $unqParts = ["job_id IN ($phj)"];
    $qualParts = ["j.job_id IN ($phj)"];
    foreach ($viewerAssignedJobs as $jid) { $scopeFilterParams[] = $jid; }
    if ($scopedFromEmployers !== []) {
        $phe = implode(',', array_fill(0, count($scopedFromEmployers), '?'));
        $unqParts[]  = "(emp_id   IN ($phe) AND emp_id   NOT IN (SELECT DISTINCT emp_id FROM demand_employer_jobs WHERE job_id IN ($phj)))";
        $qualParts[] = "(j.emp_id IN ($phe) AND j.emp_id NOT IN (SELECT DISTINCT emp_id FROM demand_employer_jobs WHERE job_id IN ($phj)))";
        foreach ($scopedFromEmployers as $sid) { $scopeFilterParams[] = $sid; }
        foreach ($viewerAssignedJobs as $jid) { $scopeFilterParams[] = $jid; }
    }
    $scopeFilterUnq  = '(' . implode(' OR ', $unqParts)  . ')';
    $scopeFilterQual = '(' . implode(' OR ', $qualParts) . ')';
}

$jobsAggInner = "SELECT emp_id, COUNT(*) AS jobs_count, SUM(open_positions) AS total_open_positions
        FROM demand_employer_jobs
        " . ($scopeFilterUnq !== null ? "WHERE $scopeFilterUnq" : "") . "
        GROUP BY emp_id";

// Edits by anyone — one row per employer with count of DISTINCT scoped
// jobs that have at least one `status` change in the edit log.
$editsAnyInner = "SELECT j.emp_id, COUNT(DISTINCT j.job_id) AS edited_any
        FROM demand_employer_jobs j
        INNER JOIN demand_employer_job_edit_log l ON l.job_row_id = j.id AND l.field_name = 'status'
        " . ($scopeFilterQual !== null ? "WHERE $scopeFilterQual" : "") . "
        GROUP BY j.emp_id";

// Edits by ME (the current viewer) — same set narrowed by edited_by.
// Only meaningful for scoped users; for admins the number is naturally 0
// unless the admin has personally made status edits, and the "You" line
// below is hidden anyway.
$editsMineInner = "SELECT j.emp_id, COUNT(DISTINCT j.job_id) AS edited_mine
        FROM demand_employer_jobs j
        INNER JOIN demand_employer_job_edit_log l ON l.job_row_id = j.id AND l.field_name = 'status' AND l.edited_by = ?
        " . ($scopeFilterQual !== null ? "WHERE $scopeFilterQual" : "") . "
        GROUP BY j.emp_id";

$fromSql = "FROM demand_employers e
    LEFT JOIN ( $jobsAggInner   ) j  ON j.emp_id  = e.employer_id
    LEFT JOIN ( $editsAnyInner  ) je ON je.emp_id = e.employer_id
    LEFT JOIN ( $editsMineInner ) jm ON jm.emp_id = e.employer_id
    $whereSql";

// Subquery placeholders bind first (jobs agg → edits any → edits mine),
// then the outer WHERE placeholders.
$subqueryParams = array_merge(
    $scopeFilterParams,           // jobs aggregate
    $scopeFilterParams,           // edits by anyone
    [$viewerId], $scopeFilterParams   // edits by me — userId comes BEFORE the scope filter (JOIN condition)
);
$params = array_merge($subqueryParams, $params);

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

// ORDER BY completion percentage ASC (most pending on top). Employers with
// no visible jobs land at the very bottom via NULL-handling — dividing by
// zero returns NULL, which we sort last with `IS NULL`.
$listStmt = db()->prepare("SELECT e.*,
        COALESCE(j.jobs_count, 0) AS jobs_count,
        COALESCE(j.total_open_positions, 0) AS total_open_positions,
        COALESCE(je.edited_any, 0) AS edited_any,
        COALESCE(jm.edited_mine, 0) AS edited_mine,
        CASE WHEN COALESCE(j.jobs_count, 0) > 0
             THEN COALESCE(je.edited_any, 0) * 100.0 / j.jobs_count
             ELSE NULL END AS completion_pct
    $fromSql
    ORDER BY completion_pct IS NULL ASC, completion_pct ASC, e.employer_id ASC
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
    <?php if ($scopedEmployerIds === []): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div>
                No employer or job assignments have been given to your account. Contact the Administrator or DSM Admin to assign employers/jobs before you can see any records here.
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info d-flex align-items-start gap-2">
            <i class="bi bi-info-circle-fill mt-1"></i>
            <div>
                You are viewing an assigned subset: <strong><?= number_format(count($scopedEmployerIds)) ?> employer(s)</strong> out of the full master list. Contact the Administrator to change your assignment.
            </div>
        </div>
    <?php endif; ?>
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
                    <th style="min-width:180px;">Completion</th>
                    <th>Active Status</th>
                    <th>Final Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="12"><div class="empty-state"><i class="bi bi-inbox"></i>No employers match the filters.</div></td></tr>
                <?php endif; ?>
                <?php $idx = $offset + 1; foreach ($rows as $row): ?>
                    <?php
                        $jobsCount   = (int) ($row['jobs_count'] ?? 0);
                        $editedAny   = (int) ($row['edited_any'] ?? 0);
                        $editedMine  = (int) ($row['edited_mine'] ?? 0);
                        $pct         = $jobsCount > 0 ? round(($editedAny * 100) / $jobsCount, 1) : null;
                        $barTone     = $pct === null ? 'secondary' : ($pct >= 90 ? 'success' : ($pct >= 50 ? 'warning' : 'danger'));
                        $minePct     = $jobsCount > 0 ? round(($editedMine * 100) / $jobsCount, 1) : null;
                    ?>
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
                        <td>
                            <?php if ($pct === null): ?>
                                <span class="text-muted small">&mdash;</span>
                            <?php else: ?>
                                <div class="d-flex align-items-center gap-2" title="<?= number_format($editedAny) ?> of <?= number_format($jobsCount) ?> job(s) have a status edit">
                                    <div class="progress flex-grow-1" style="height:8px; max-width:100px;">
                                        <div class="progress-bar bg-<?= esc($barTone) ?>" role="progressbar" style="width: <?= (float) $pct ?>%;" aria-valuenow="<?= (float) $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <span class="fw-bold small"><?= number_format($pct, 1) ?>%</span>
                                </div>
                                <?php if (!$viewerIsAdministrator && $editedMine > 0 && $editedMine !== $editedAny): ?>
                                    <div class="small text-muted mt-1" title="Status edits you personally made on your scope of this employer">
                                        <i class="bi bi-person-check me-1"></i>You: <?= number_format($editedMine) ?> / <?= number_format($jobsCount) ?>
                                        <span class="ms-1">(<?= number_format((float) $minePct, 1) ?>%)</span>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
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

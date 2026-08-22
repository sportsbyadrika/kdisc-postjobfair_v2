<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/demand_side_helpers.php';
require_admin();
demand_side_bootstrap();

$selectedDate = trim((string) ($_GET['date'] ?? ''));
if ($selectedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = '';
}

$fromFilter = trim((string) ($_GET['from'] ?? ''));
$toFilter = trim((string) ($_GET['to'] ?? ''));
$fromFilter = $fromFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromFilter) ? $fromFilter : '';
$toFilter = $toFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toFilter) ? $toFilter : '';

$categoryFilter = trim((string) ($_GET['category'] ?? ''));
$categories = demand_get_categories();
$categoryByName = [];
foreach ($categories as $c) { $categoryByName[(string) $c['name']] = $c; }

// If a Category is selected, resolve it to the employer_id set that fits
// its open-positions range, then use that set to narrow every edit-log
// query below.
$categoryEmployerIds = null; // null = no filter, [] = empty set (no matches)
if ($categoryFilter !== '' && isset($categoryByName[$categoryFilter])) {
    $c = $categoryByName[$categoryFilter];
    $stmt = db()->prepare("SELECT e.employer_id FROM demand_employers e
        LEFT JOIN (SELECT emp_id, SUM(open_positions) AS positions_count FROM demand_employer_jobs GROUP BY emp_id) j ON j.emp_id = e.employer_id
        WHERE COALESCE(j.positions_count, 0) BETWEEN ? AND ?");
    $stmt->execute([(int) $c['min_positions'], (int) $c['max_positions']]);
    $categoryEmployerIds = array_map(static fn(array $r): int => (int) $r['employer_id'], $stmt->fetchAll());
}

/** Helper: build a subquery that scopes edit logs by category when needed. */
$categoryJobRowIdSubquery = null;
$categoryEmployerRowIdSubquery = null;
if ($categoryEmployerIds !== null) {
    if ($categoryEmployerIds === []) {
        // Force empty result — no employer_ids in category.
        $categoryJobRowIdSubquery = '(SELECT id FROM demand_employer_jobs WHERE 1=0)';
        $categoryEmployerRowIdSubquery = '(SELECT id FROM demand_employers WHERE 1=0)';
    } else {
        $eidList = implode(',', array_map('intval', $categoryEmployerIds));
        $categoryJobRowIdSubquery = "(SELECT id FROM demand_employer_jobs WHERE emp_id IN ($eidList))";
        $categoryEmployerRowIdSubquery = "(SELECT id FROM demand_employers WHERE employer_id IN ($eidList))";
    }
}

$dateConds = ["field_name = 'status'"];
$dateParams = [];
if ($fromFilter !== '') { $dateConds[] = 'DATE(edited_at) >= ?'; $dateParams[] = $fromFilter; }
if ($toFilter !== '')   { $dateConds[] = 'DATE(edited_at) <= ?'; $dateParams[] = $toFilter; }
if ($categoryJobRowIdSubquery !== null) { $dateConds[] = "job_row_id IN $categoryJobRowIdSubquery"; }
$dateWhere = 'WHERE ' . implode(' AND ', $dateConds);

$dateSql = "SELECT
        DATE(edited_at) AS d,
        SUM(CASE WHEN new_value = 'Valid'     THEN 1 ELSE 0 END) AS valid_count,
        SUM(CASE WHEN new_value = 'Invalid'   THEN 1 ELSE 0 END) AS invalid_count,
        SUM(CASE WHEN new_value = 'Corrected' THEN 1 ELSE 0 END) AS corrected_count,
        COUNT(*) AS total_changes
    FROM demand_employer_job_edit_log
    $dateWhere
    GROUP BY DATE(edited_at)
    ORDER BY d DESC";
$dateStmt = db()->prepare($dateSql);
$dateStmt->execute($dateParams);
$dateRows = $dateStmt->fetchAll();

// Distinct field names for the Correction Field filter dropdown on the
// Edit-detail table. Union of both edit-log tables.
$fieldOptions = array_map(
    static fn(array $r): string => (string) $r['field_name'],
    db()->query("SELECT DISTINCT field_name FROM (
        SELECT field_name FROM demand_employer_edit_log
        UNION
        SELECT field_name FROM demand_employer_job_edit_log
    ) t ORDER BY field_name ASC")->fetchAll()
);

// Correction Field filter — defaults to 'status'. Blank means "all fields".
$fieldFilter = trim((string) ($_GET['field'] ?? 'status'));
if ($fieldFilter !== '' && $fieldOptions !== [] && !in_array($fieldFilter, $fieldOptions, true)) {
    $fieldFilter = 'status';
}

// Extra edit-detail filters — set by click-through from the User-wise table.
$editorIdFilter = (int) ($_GET['editor_id'] ?? 0);
$entityFilter   = strtolower(trim((string) ($_GET['entity_filter'] ?? '')));
if (!in_array($entityFilter, ['', 'employer', 'job'], true)) { $entityFilter = ''; }
$newValueFilter = trim((string) ($_GET['new_value_filter'] ?? ''));

// Pagination for the Edit-detail table. Per-page allowlist is multiples of
// 25 (25/50/100/200); default 25. CSV download below always exports the
// full filtered set, not just the current page.
$editsPerPageAllowed = [25, 50, 100, 200];
$editsPerPage = (int) ($_GET['edits_per_page'] ?? 25);
if (!in_array($editsPerPage, $editsPerPageAllowed, true)) { $editsPerPage = 25; }
$editsPage = max(1, (int) ($_GET['edits_page'] ?? 1));

$userRows = [];
$editDetailRows = [];
$editDetailTotal = 0;
$editDetailTotalPages = 1;
$editDetailOffset = 0;
if ($selectedDate !== '') {
    // Scope filters that flow into both the user-wise and edit-detail queries.
    $empLogScope = '';
    $jobLogScope = '';
    if ($categoryJobRowIdSubquery !== null) {
        $empLogScope = " AND employer_row_id IN $categoryEmployerRowIdSubquery";
        $jobLogScope = " AND job_row_id IN $categoryJobRowIdSubquery";
    }
    $userSql = "SELECT
            u.id AS user_id,
            u.name AS user_name,
            u.role AS user_role,
            SUM(CASE WHEN src = 'employer' THEN 1 ELSE 0 END) AS employer_edits,
            SUM(CASE WHEN src = 'job'      THEN 1 ELSE 0 END) AS job_edits,
            SUM(CASE WHEN src = 'job' AND field_name = 'status' AND new_value = 'Valid'     THEN 1 ELSE 0 END) AS job_valid,
            SUM(CASE WHEN src = 'job' AND field_name = 'status' AND new_value = 'Invalid'   THEN 1 ELSE 0 END) AS job_invalid,
            SUM(CASE WHEN src = 'job' AND field_name = 'status' AND new_value = 'Corrected' THEN 1 ELSE 0 END) AS job_corrected,
            COUNT(*) AS total_edits
        FROM (
            SELECT 'employer' AS src, edited_by, field_name, new_value FROM demand_employer_edit_log WHERE DATE(edited_at) = ? $empLogScope
            UNION ALL
            SELECT 'job'      AS src, edited_by, field_name, new_value FROM demand_employer_job_edit_log WHERE DATE(edited_at) = ? $jobLogScope
        ) t
        LEFT JOIN users u ON u.id = t.edited_by
        GROUP BY u.id, u.name, u.role
        ORDER BY total_edits DESC, u.name ASC";
    $userStmt = db()->prepare($userSql);
    $userStmt->execute([$selectedDate, $selectedDate]);
    $userRows = $userStmt->fetchAll();

    // Per-edit detail, joined through the emp_id -> employer_id FK so every
    // row shows which employer (and job title / current remarks, for job
    // edits) was touched. Extra filters honour the User-wise click-through
    // (editor_id, entity_filter, new_value_filter) plus the existing
    // Correction Field filter.
    $empParts = [];
    $jobParts = [];
    $empBind = [$selectedDate];
    $jobBind = [$selectedDate];
    if ($fieldFilter !== '') {
        $empParts[] = 'el.field_name = ?';
        $jobParts[] = 'jl.field_name = ?';
        $empBind[] = $fieldFilter; $jobBind[] = $fieldFilter;
    }
    if ($editorIdFilter > 0) {
        $empParts[] = 'el.edited_by = ?';
        $jobParts[] = 'jl.edited_by = ?';
        $empBind[] = $editorIdFilter; $jobBind[] = $editorIdFilter;
    }
    if ($newValueFilter !== '') {
        $empParts[] = 'el.new_value = ?';
        $jobParts[] = 'jl.new_value = ?';
        $empBind[] = $newValueFilter; $jobBind[] = $newValueFilter;
    }
    $empExtra = $empParts === [] ? '' : ' AND ' . implode(' AND ', $empParts);
    $jobExtra = $jobParts === [] ? '' : ' AND ' . implode(' AND ', $jobParts);

    $empBranch = "(SELECT
            'Employer' AS entity,
            el.edited_at, el.field_name, el.old_value, el.new_value,
            u.name AS editor_name, u.id AS editor_id,
            e.employer_id AS employer_id, e.employer_name,
            NULL AS job_id, NULL AS job_title,
            e.active_status AS current_status,
            e.remarks AS current_remarks,
            NULL AS current_remarks_group_name,
            NULL AS current_corrected_open_position,
            NULL AS current_open_positions
        FROM demand_employer_edit_log el
        LEFT JOIN users u ON u.id = el.edited_by
        LEFT JOIN demand_employers e ON e.id = el.employer_row_id
        WHERE DATE(el.edited_at) = ? $empExtra $empLogScope)";
    $jobBranch = "(SELECT
            'Job' AS entity,
            jl.edited_at, jl.field_name, jl.old_value, jl.new_value,
            u.name AS editor_name, u.id AS editor_id,
            j.emp_id AS employer_id, e.employer_name,
            j.job_id, j.jobtitle,
            j.status AS current_status,
            j.remarks AS current_remarks,
            rg.name AS current_remarks_group_name,
            j.corrected_open_position AS current_corrected_open_position,
            j.open_positions AS current_open_positions
        FROM demand_employer_job_edit_log jl
        LEFT JOIN users u ON u.id = jl.edited_by
        LEFT JOIN demand_employer_jobs j ON j.id = jl.job_row_id
        LEFT JOIN demand_employers e ON e.employer_id = j.emp_id
        LEFT JOIN demand_remarks_groups rg ON rg.id = j.remarks_group_id
        WHERE DATE(jl.edited_at) = ? $jobExtra $jobLogScope)";

    // Count query for pagination + CSV. Same filters as the detail fetch,
    // just without the heavy join to remarks_group / employers.
    $empCountSql = "SELECT COUNT(*) FROM demand_employer_edit_log el WHERE DATE(el.edited_at) = ? $empExtra $empLogScope";
    $jobCountSql = "SELECT COUNT(*) FROM demand_employer_job_edit_log jl WHERE DATE(jl.edited_at) = ? $jobExtra $jobLogScope";
    if ($entityFilter === 'employer') {
        $countStmt = db()->prepare($empCountSql);
        $countStmt->execute($empBind);
        $editDetailTotal = (int) $countStmt->fetchColumn();
    } elseif ($entityFilter === 'job') {
        $countStmt = db()->prepare($jobCountSql);
        $countStmt->execute($jobBind);
        $editDetailTotal = (int) $countStmt->fetchColumn();
    } else {
        $c1 = db()->prepare($empCountSql); $c1->execute($empBind);
        $c2 = db()->prepare($jobCountSql); $c2->execute($jobBind);
        $editDetailTotal = (int) $c1->fetchColumn() + (int) $c2->fetchColumn();
    }
    $editDetailTotalPages = max(1, (int) ceil($editDetailTotal / $editsPerPage));
    $editsPage = min($editsPage, $editDetailTotalPages);
    $editDetailOffset = ($editsPage - 1) * $editsPerPage;

    if ($entityFilter === 'employer') {
        $detailBaseSql = $empBranch;
        $detailParams  = $empBind;
    } elseif ($entityFilter === 'job') {
        $detailBaseSql = $jobBranch;
        $detailParams  = $jobBind;
    } else {
        $detailBaseSql = $empBranch . ' UNION ALL ' . $jobBranch;
        $detailParams  = array_merge($empBind, $jobBind);
    }
    $detailSql = $detailBaseSql . ' ORDER BY edited_at DESC LIMIT ? OFFSET ?';
    $detailStmt = db()->prepare($detailSql);
    $detailStmt->execute([...$detailParams, $editsPerPage, $editDetailOffset]);
    $editDetailRows = $detailStmt->fetchAll();
}

// CSV download handlers. Streamed before any HTML output.
$download = trim((string) ($_GET['download'] ?? ''));
if ($download === 'users' && $userRows !== []) {
    $filename = 'demand_stats_users_' . $selectedDate . '.csv';
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['User', 'Role', 'Employer edits', 'Job edits', 'Job — Valid', 'Job — Invalid', 'Job — Corrected', 'Total (V+I+C)', 'Total edits']);
    foreach ($userRows as $r) {
        $v = (int) $r['job_valid']; $i = (int) $r['job_invalid']; $co = (int) $r['job_corrected'];
        fputcsv($out, [
            (string) ($r['user_name'] ?? ''),
            (string) ($r['user_role'] ?? ''),
            (int) $r['employer_edits'], (int) $r['job_edits'],
            $v, $i, $co, $v + $i + $co, (int) $r['total_edits'],
        ]);
    }
    fclose($out); exit;
}
if ($download === 'jobs_status') {
    // Snapshot export — one row per demand_employer_jobs row with its
    // current modification status. Respects the Category filter above
    // (from/to date filters intentionally don't apply here — they scope
    // the edit-log, not the current status of each job).
    @set_time_limit(0);
    ignore_user_abort(true);
    $conds  = ['1=1'];
    $params = [];
    if ($categoryEmployerIds !== null) {
        if ($categoryEmployerIds === []) {
            $conds[] = '1=0';
        } else {
            $eidList = implode(',', array_map('intval', $categoryEmployerIds));
            $conds[] = "e.employer_id IN ($eidList)";
        }
    }
    $sql = "SELECT e.employer_id, e.employer_name, e.jobagency,
                j.job_id, j.jobtitle, j.status
            FROM demand_employer_jobs j
            INNER JOIN demand_employers e ON e.employer_id = j.emp_id
            WHERE " . implode(' AND ', $conds) . "
            ORDER BY e.employer_id ASC, j.job_id ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $filename = 'demand_stats_jobs_status_' . date('Ymd_His') . '.csv';
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Employer ID', 'Name of Employer', 'Agency', 'Job ID', 'Job Title', 'Modification Status']);
    while ($r = $stmt->fetch()) {
        $status = trim((string) ($r['status'] ?? ''));
        // NULL / blank in-app status renders as "Pending" per the user's
        // requested vocabulary (matches the "Not Yet Started" bucket on
        // the Verification Status card).
        if ($status === '') { $status = 'Pending'; }
        fputcsv($out, [
            (string) ($r['employer_id'] ?? ''),
            (string) ($r['employer_name'] ?? ''),
            (string) ($r['jobagency'] ?? ''),
            (string) ($r['job_id'] ?? ''),
            (string) ($r['jobtitle'] ?? ''),
            $status,
        ]);
    }
    fclose($out); exit;
}
if ($download === 'open_jobs') {
    // Snapshot export scoped to jobs whose DWMS source posting status is
    // currently "open" — matches Open / Active / Live / Valid (case-
    // insensitive, trimmed) to catch every DWMS spelling. Respects the
    // Category filter (from/to date filters aren't relevant here — those
    // scope the edit log, not the current per-job status).
    @set_time_limit(0);
    ignore_user_abort(true);
    $conds  = ["LOWER(TRIM(j.job_status_data)) IN ('open','active','live','valid')"];
    $params = [];
    if ($categoryEmployerIds !== null) {
        if ($categoryEmployerIds === []) {
            $conds[] = '1=0';
        } else {
            $eidList = implode(',', array_map('intval', $categoryEmployerIds));
            $conds[] = "e.employer_id IN ($eidList)";
        }
    }
    $sql = "SELECT e.employer_id, e.employer_name,
                j.job_id, j.jobtitle, j.open_positions,
                j.job_status_data, j.status,
                j.location, j.salary_type, j.salary_slab
            FROM demand_employer_jobs j
            INNER JOIN demand_employers e ON e.employer_id = j.emp_id
            WHERE " . implode(' AND ', $conds) . "
            ORDER BY e.employer_id ASC, j.job_id ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $filename = 'demand_stats_open_jobs_' . date('Ymd_His') . '.csv';
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Employer ID', 'Employer Name', 'Job ID', 'Job Title',
        'Positions', 'Status', 'Verification Status', 'Job Location', 'Salary Range']);
    while ($r = $stmt->fetch()) {
        // Verification status renders NULL / blank as "Not Yet Started"
        // so every row is meaningful, matching the on-page labelling.
        $ver = trim((string) ($r['status'] ?? ''));
        if ($ver === '') { $ver = 'Not Yet Started'; }
        // Combine salary_type + salary_slab into a single "Range" column
        // — either alone if the other is blank, joined with a middle-dot
        // when both are present.
        $st = trim((string) ($r['salary_type'] ?? ''));
        $ss = trim((string) ($r['salary_slab'] ?? ''));
        $salary = $st !== '' && $ss !== '' ? "$st · $ss" : ($st !== '' ? $st : $ss);
        fputcsv($out, [
            (string) ($r['employer_id'] ?? ''),
            (string) ($r['employer_name'] ?? ''),
            (string) ($r['job_id'] ?? ''),
            (string) ($r['jobtitle'] ?? ''),
            (string) ($r['open_positions'] ?? ''),
            (string) ($r['job_status_data'] ?? ''),
            $ver,
            (string) ($r['location'] ?? ''),
            $salary,
        ]);
    }
    fclose($out); exit;
}
if ($download === 'edits' && $editDetailTotal > 0 && isset($detailBaseSql, $detailParams)) {
    // Run the SAME filtered query as the on-page table, but without the
    // LIMIT so the export always returns every matching row.
    $fullSql = $detailBaseSql . ' ORDER BY edited_at DESC';
    $fullStmt = db()->prepare($fullSql);
    $fullStmt->execute($detailParams);
    $filename = 'demand_stats_edits_' . $selectedDate . '_' . ($fieldFilter !== '' ? $fieldFilter : 'all') . '.csv';
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['When', 'Entity', 'Employer ID', 'Employer Name', 'Job ID', 'Job Title', 'Field', 'Old', 'New', 'Edited By']);
    while ($r = $fullStmt->fetch()) {
        fputcsv($out, [
            (string) ($r['edited_at'] ?? ''),
            (string) ($r['entity'] ?? ''),
            (string) ($r['employer_id'] ?? ''),
            (string) ($r['employer_name'] ?? ''),
            (string) ($r['job_id'] ?? ''),
            (string) ($r['job_title'] ?? ''),
            (string) ($r['field_name'] ?? ''),
            (string) ($r['old_value'] ?? ''),
            (string) ($r['new_value'] ?? ''),
            (string) ($r['editor_name'] ?? ''),
        ]);
    }
    fclose($out); exit;
}

render_header('Demand Side · Data Modification Statistics', ['main_container_class' => 'container-fluid']);
$jobsStatusCsvUrl = '/demand_side_stats.php?' . http_build_query(array_filter([
    'category' => $categoryFilter,
    'download' => 'jobs_status',
], static fn($v): bool => $v !== '' && $v !== null));
$openJobsCsvUrl = '/demand_side_stats.php?' . http_build_query(array_filter([
    'category' => $categoryFilter,
    'download' => 'open_jobs',
], static fn($v): bool => $v !== '' && $v !== null));
render_page_header('Demand Side · Data Modification Statistics', [
    'icon' => 'bi-bar-chart-line',
    'subtitle' => 'Date-wise employer_jobs status change counts (Valid / Invalid / Corrected). Click a date row for user-wise breakdown across employers and jobs.',
    'actions' => '<a class="btn btn-light me-1" href="' . esc($jobsStatusCsvUrl) . '" title="Download every job with its current Valid / Invalid / Corrected / Pending status"><i class="bi bi-download me-1"></i>Download Jobs Status CSV</a>'
        . '<a class="btn btn-light me-1" href="' . esc($openJobsCsvUrl) . '" title="Download every job whose Job Posting Status is Open / Active / Live / Valid, with verification status, location and salary"><i class="bi bi-download me-1"></i>Download Open Jobs CSV</a>'
        . '<a class="btn btn-light" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>',
]);
?>

<form method="get" class="card mb-3">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-funnel text-primary me-1"></i>Filters</h2>
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">From date</label>
                <input type="date" class="form-control" name="from" value="<?= esc($fromFilter) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">To date</label>
                <input type="date" class="form-control" name="to" value="<?= esc($toFilter) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Category</label>
                <select class="form-select" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= esc((string) $c['name']) ?>" <?= $categoryFilter === (string) $c['name'] ? 'selected' : '' ?>><?= esc((string) $c['name']) ?> (<?= number_format((int) $c['min_positions']) ?>–<?= number_format((int) $c['max_positions']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($selectedDate !== ''): ?>
                <input type="hidden" name="date" value="<?= esc($selectedDate) ?>">
            <?php endif; ?>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary">Apply</button>
                <a class="btn btn-light" href="/demand_side_stats.php">Reset</a>
            </div>
        </div>
    </div>
</form>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar-week text-primary me-1"></i>Date-wise Employer Jobs Status Changes</span>
        <span class="status-chip status-info"><?= number_format(count($dateRows)) ?> dates</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="text-end">Valid</th>
                    <th class="text-end">Invalid</th>
                    <th class="text-end">Corrected</th>
                    <th class="text-end">Total (V+I+C)</th>
                    <th class="text-end">Total status changes</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($dateRows === []): ?>
                    <tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i>No employer_jobs status changes recorded in this range yet.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($dateRows as $row): ?>
                    <?php
                        $d = (string) $row['d'];
                        $url = '/demand_side_stats.php?' . http_build_query(array_filter([
                            'date' => $d, 'from' => $fromFilter, 'to' => $toFilter, 'category' => $categoryFilter,
                        ], static fn($v): bool => $v !== ''));
                        $isSelected = $selectedDate === $d;
                        $vicTotal = (int) $row['valid_count'] + (int) $row['invalid_count'] + (int) $row['corrected_count'];
                    ?>
                    <tr <?= $isSelected ? 'class="table-primary"' : '' ?>>
                        <td class="fw-semibold"><a href="<?= esc($url) ?>"><?= esc($d) ?></a></td>
                        <td class="text-end"><?= number_format((int) $row['valid_count']) ?></td>
                        <td class="text-end"><?= number_format((int) $row['invalid_count']) ?></td>
                        <td class="text-end"><?= number_format((int) $row['corrected_count']) ?></td>
                        <td class="text-end fw-bold"><?= number_format($vicTotal) ?></td>
                        <td class="text-end fw-semibold"><?= number_format((int) $row['total_changes']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($selectedDate !== ''): ?>
    <?php $userCsvUrl = '/demand_side_stats.php?' . http_build_query(array_filter([
        'date' => $selectedDate, 'from' => $fromFilter, 'to' => $toFilter,
        'category' => $categoryFilter, 'download' => 'users',
    ], static fn($v): bool => $v !== '')); ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-people text-primary me-1"></i>User-wise correction counts on <?= esc($selectedDate) ?></span>
            <div class="d-flex gap-2">
                <?php if ($userRows !== []): ?>
                    <a class="btn btn-sm btn-light" href="<?= esc($userCsvUrl) ?>"><i class="bi bi-download me-1"></i>Download CSV</a>
                <?php endif; ?>
                <span class="status-chip status-info"><?= number_format(count($userRows)) ?> users</span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th class="text-end">Employer edits</th>
                        <th class="text-end">Job edits</th>
                        <th class="text-end">Job — Valid</th>
                        <th class="text-end">Job — Invalid</th>
                        <th class="text-end">Job — Corrected</th>
                        <th class="text-end">Total (V+I+C)</th>
                        <th class="text-end">Total edits</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($userRows === []): ?>
                        <tr><td colspan="9"><div class="empty-state"><i class="bi bi-inbox"></i>No user edits recorded on this date.</div></td></tr>
                    <?php endif; ?>
                    <?php
                        // Build a click-through URL that jumps back to this page with
                        // the Edit-detail filters set to a specific slice.
                        $filterUrl = static function (int $uid, string $entity = '', string $newValue = '', string $field = '') use ($selectedDate, $fromFilter, $toFilter, $categoryFilter): string {
                            return '/demand_side_stats.php?' . http_build_query(array_filter([
                                'date' => $selectedDate, 'from' => $fromFilter, 'to' => $toFilter,
                                'category' => $categoryFilter,
                                'editor_id' => $uid,
                                'entity_filter' => $entity,
                                'new_value_filter' => $newValue,
                                'field' => $field,
                            ], static fn($v): bool => $v !== '' && $v !== 0)) . '#editDetailCard';
                        };
                        $countLink = static function (int $value, string $url): string {
                            if ($value === 0) { return '<span class="text-muted">0</span>'; }
                            return '<a href="' . esc($url) . '" class="text-decoration-none">' . number_format($value) . '</a>';
                        };
                    ?>
                    <?php foreach ($userRows as $row): ?>
                        <?php
                            $uid = (int) ($row['user_id'] ?? 0);
                            $vicUser = (int) $row['job_valid'] + (int) $row['job_invalid'] + (int) $row['job_corrected'];
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= esc((string) ($row['user_name'] ?? '(unknown)')) ?></td>
                            <td><span class="status-chip status-neutral"><?= esc(role_label((string) ($row['user_role'] ?? ''))) ?></span></td>
                            <td class="text-end"><?= $countLink((int) $row['employer_edits'], $filterUrl($uid, 'employer')) ?></td>
                            <td class="text-end"><?= $countLink((int) $row['job_edits'],      $filterUrl($uid, 'job')) ?></td>
                            <td class="text-end"><?= $countLink((int) $row['job_valid'],     $filterUrl($uid, 'job', 'Valid',     'status')) ?></td>
                            <td class="text-end"><?= $countLink((int) $row['job_invalid'],   $filterUrl($uid, 'job', 'Invalid',   'status')) ?></td>
                            <td class="text-end"><?= $countLink((int) $row['job_corrected'], $filterUrl($uid, 'job', 'Corrected', 'status')) ?></td>
                            <td class="text-end fw-bold"><?= $countLink($vicUser, $filterUrl($uid, 'job', '', 'status')) ?></td>
                            <td class="text-end fw-semibold"><?= $countLink((int) $row['total_edits'], $filterUrl($uid)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php $editsCsvUrl = '/demand_side_stats.php?' . http_build_query(array_filter([
        'date' => $selectedDate, 'from' => $fromFilter, 'to' => $toFilter,
        'category' => $categoryFilter, 'field' => $fieldFilter,
        'editor_id' => $editorIdFilter > 0 ? $editorIdFilter : '',
        'entity_filter' => $entityFilter, 'new_value_filter' => $newValueFilter,
        'download' => 'edits',
    ], static fn($v): bool => $v !== '' && $v !== 0)); ?>
    <div class="card mb-4" id="editDetailCard">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-list-check text-primary me-1"></i>Edit detail on <?= esc($selectedDate) ?> <span class="text-muted small ms-1">(joined via emp_id → employer_id)</span></span>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <?php if ($editorIdFilter > 0 || $entityFilter !== '' || $newValueFilter !== ''): ?>
                    <?php
                        // Look up editor name for the chip label.
                        $editorName = '';
                        if ($editorIdFilter > 0) {
                            $s = db()->prepare('SELECT name FROM users WHERE id = ?');
                            $s->execute([$editorIdFilter]);
                            $editorName = (string) ($s->fetchColumn() ?: ('#' . $editorIdFilter));
                        }
                        $clearUrl = '/demand_side_stats.php?' . http_build_query(array_filter([
                            'date' => $selectedDate, 'from' => $fromFilter, 'to' => $toFilter,
                            'category' => $categoryFilter, 'field' => $fieldFilter,
                        ], static fn($v): bool => $v !== '')) . '#editDetailCard';
                    ?>
                    <?php if ($editorName !== ''): ?><span class="status-chip status-primary"><i class="bi bi-person-fill me-1"></i><?= esc($editorName) ?></span><?php endif; ?>
                    <?php if ($entityFilter !== ''): ?><span class="status-chip status-primary"><?= esc(ucfirst($entityFilter)) ?></span><?php endif; ?>
                    <?php if ($newValueFilter !== ''): ?><span class="status-chip status-primary"><?= esc($newValueFilter) ?></span><?php endif; ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= esc($clearUrl) ?>"><i class="bi bi-x-lg me-1"></i>Clear user filters</a>
                <?php endif; ?>
                <?php if ($editDetailTotal > 0): ?>
                    <a class="btn btn-sm btn-light" href="<?= esc($editsCsvUrl) ?>"><i class="bi bi-download me-1"></i>Download CSV</a>
                <?php endif; ?>
                <?php if (count($editDetailRows) !== $editDetailTotal): ?>
                    <span class="status-chip status-neutral" title="Rows on this page / total matched"><?= number_format(count($editDetailRows)) ?> on page &middot; <?= number_format($editDetailTotal) ?> matched</span>
                <?php else: ?>
                    <span class="status-chip status-info"><?= number_format($editDetailTotal) ?> edits</span>
                <?php endif; ?>
                <?php
                    // Per-page selector (auto-submits on change). Carries every
                    // active filter through hidden fields so the switch doesn't
                    // reset user_id / entity_filter / field / etc.
                    $editsPerPageBase = array_filter([
                        'date' => $selectedDate, 'from' => $fromFilter, 'to' => $toFilter,
                        'category' => $categoryFilter, 'field' => $fieldFilter,
                        'editor_id' => $editorIdFilter > 0 ? $editorIdFilter : '',
                        'entity_filter' => $entityFilter, 'new_value_filter' => $newValueFilter,
                    ], static fn($v): bool => $v !== '' && $v !== 0);
                ?>
                <form method="get" class="d-inline">
                    <?php foreach ($editsPerPageBase as $k => $v): ?>
                        <input type="hidden" name="<?= esc((string) $k) ?>" value="<?= esc((string) $v) ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="edits_page" value="1">
                    <select class="form-select form-select-sm" name="edits_per_page" onchange="this.form.submit()" style="width:auto;">
                        <?php foreach ($editsPerPageAllowed as $pp): ?>
                            <option value="<?= (int) $pp ?>" <?= $editsPerPage === $pp ? 'selected' : '' ?>><?= (int) $pp ?>/pg</option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>
        <div class="card-body pb-2">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="date" value="<?= esc($selectedDate) ?>">
                <?php if ($fromFilter !== '') { echo '<input type="hidden" name="from" value="' . esc($fromFilter) . '">'; } ?>
                <?php if ($toFilter !== '')   { echo '<input type="hidden" name="to" value="' . esc($toFilter) . '">';   } ?>
                <?php if ($categoryFilter !== '') { echo '<input type="hidden" name="category" value="' . esc($categoryFilter) . '">'; } ?>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Correction Field</label>
                    <select class="form-select form-select-sm" name="field">
                        <option value="" <?= $fieldFilter === '' ? 'selected' : '' ?>>All fields</option>
                        <?php foreach ($fieldOptions as $f): ?>
                            <option value="<?= esc($f) ?>" <?= $fieldFilter === $f ? 'selected' : '' ?>><?= esc(str_replace('_', ' ', $f)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
                    <a class="btn btn-sm btn-light" href="/demand_side_stats.php?<?= esc(http_build_query(array_filter(['date' => $selectedDate, 'from' => $fromFilter, 'to' => $toFilter, 'category' => $categoryFilter], static fn($v): bool => $v !== ''))) ?>">Reset field</a>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-bordered align-middle mb-0">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Entity</th>
                        <th>Employer</th>
                        <th>Job</th>
                        <th>Field</th>
                        <th>Old</th>
                        <th>New</th>
                        <th>Edited By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($editDetailRows === []): ?>
                        <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No edits on this date.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($editDetailRows as $er): ?>
                        <tr>
                            <td class="small text-muted"><?= esc((string) $er['edited_at']) ?></td>
                            <td><span class="status-chip status-neutral"><?= esc((string) $er['entity']) ?></span></td>
                            <td>
                                <?php if (!empty($er['employer_id'])): ?>
                                    <div class="fw-semibold"><?= esc((string) ($er['employer_name'] ?? '')) ?></div>
                                    <div class="small text-muted">ID <?= (int) $er['employer_id'] ?></div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($er['job_id'])): ?>
                                    <div><?= esc((string) ($er['job_title'] ?? '')) ?></div>
                                    <div class="small text-muted">Job ID <?= (int) $er['job_id'] ?></div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= esc((string) $er['field_name']) ?></td>
                            <td class="small text-muted"><?= esc((string) ($er['old_value'] ?? '')) ?></td>
                            <?php
                                $newVal = (string) ($er['new_value'] ?? '');
                                $bg = ''; $fg = '';
                                if ($er['field_name'] === 'status') {
                                    if ($newVal === 'Valid')     { $bg = '#d1e7dd'; $fg = '#0f5132'; }
                                    elseif ($newVal === 'Invalid') { $bg = '#f8d7da'; $fg = '#842029'; }
                                    elseif ($newVal === 'Corrected'){ $bg = '#ffe5d0'; $fg = '#8a4b00'; }
                                }
                                // Modal-trigger data attributes — always populated so
                                // clicking any New cell (status or otherwise) opens the
                                // record-details modal for that row.
                                $modalAttrs = 'data-bs-toggle="modal" data-bs-target="#editDetailModal"'
                                    . ' data-entity="' . esc((string) $er['entity']) . '"'
                                    . ' data-when="' . esc((string) $er['edited_at']) . '"'
                                    . ' data-editor="' . esc((string) ($er['editor_name'] ?? '')) . '"'
                                    . ' data-field="' . esc((string) $er['field_name']) . '"'
                                    . ' data-old="' . esc((string) ($er['old_value'] ?? '')) . '"'
                                    . ' data-new="' . esc($newVal) . '"'
                                    . ' data-employer-id="' . (int) ($er['employer_id'] ?? 0) . '"'
                                    . ' data-employer-name="' . esc((string) ($er['employer_name'] ?? '')) . '"'
                                    . ' data-job-id="' . (int) ($er['job_id'] ?? 0) . '"'
                                    . ' data-job-title="' . esc((string) ($er['job_title'] ?? '')) . '"'
                                    . ' data-current-status="' . esc((string) ($er['current_status'] ?? '')) . '"'
                                    . ' data-current-remarks="' . esc((string) ($er['current_remarks'] ?? '')) . '"'
                                    . ' data-current-remarks-group="' . esc((string) ($er['current_remarks_group_name'] ?? '')) . '"'
                                    . ' data-current-corrected-open-position="' . esc((string) ($er['current_corrected_open_position'] ?? '')) . '"'
                                    . ' data-current-open-positions="' . esc((string) ($er['current_open_positions'] ?? '')) . '"';
                                $style = ($bg !== '') ? 'background:' . $bg . '; color:' . $fg . '; cursor:pointer;' : 'cursor:pointer;';
                            ?>
                            <td class="small fw-bold js-edit-detail-open" style="<?= $style ?>" title="Click for full record details" <?= $modalAttrs ?>>
                                <?= esc($newVal) ?> <i class="bi bi-info-circle small ms-1"></i>
                            </td>
                            <td class="small"><?= esc((string) ($er['editor_name'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($editDetailTotalPages > 1): ?>
            <?php
                $editsPageLink = static function (int $p) use ($editsPerPageBase, $editsPerPage): string {
                    $params = $editsPerPageBase;
                    $params['edits_per_page'] = $editsPerPage;
                    $params['edits_page']     = $p;
                    return '/demand_side_stats.php?' . http_build_query($params) . '#editDetailCard';
                };
                $winStart = max(1, $editsPage - 2);
                $winEnd   = min($editDetailTotalPages, $editsPage + 2);
            ?>
            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="small text-muted">
                    Showing <?= number_format(($editsPage - 1) * $editsPerPage + 1) ?>&ndash;<?= number_format(min($editsPage * $editsPerPage, $editDetailTotal)) ?> of <?= number_format($editDetailTotal) ?>
                </div>
                <nav aria-label="Edit detail pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $editsPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $editsPage <= 1 ? '#' : esc($editsPageLink($editsPage - 1)) ?>">Prev</a></li>
                        <?php if ($winStart > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= esc($editsPageLink(1)) ?>">1</a></li>
                            <?php if ($winStart > 2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
                        <?php endif; ?>
                        <?php for ($p = $winStart; $p <= $winEnd; $p++): ?>
                            <li class="page-item <?= $p === $editsPage ? 'active' : '' ?>"><a class="page-link" href="<?= esc($editsPageLink($p)) ?>"><?= $p ?></a></li>
                        <?php endfor; ?>
                        <?php if ($winEnd < $editDetailTotalPages): ?>
                            <?php if ($winEnd < $editDetailTotalPages - 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?= esc($editsPageLink((int) $editDetailTotalPages)) ?>"><?= (int) $editDetailTotalPages ?></a></li>
                        <?php endif; ?>
                        <li class="page-item <?= $editsPage >= $editDetailTotalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $editsPage >= $editDetailTotalPages ? '#' : esc($editsPageLink($editsPage + 1)) ?>">Next</a></li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Record details modal — populated on click from the Edit-detail cell's
     data-* attributes. Works for both Job and Employer edits; sections
     that don't apply to the current entity are hidden by the JS. -->
<div class="modal fade" id="editDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-info-circle me-1"></i>Edit record details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="small text-muted">When</div>
                        <div class="fw-semibold" id="editModalWhen">—</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Entity</div>
                        <div class="fw-semibold" id="editModalEntity">—</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Edited By</div>
                        <div class="fw-semibold" id="editModalEditor">—</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">Employer</div>
                        <div class="fw-semibold" id="editModalEmployer">—</div>
                    </div>
                    <div class="col-md-6 js-jobonly">
                        <div class="small text-muted">Job</div>
                        <div class="fw-semibold" id="editModalJob">—</div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted">Field changed</div>
                        <div class="fw-semibold" id="editModalField">—</div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted">Old value</div>
                        <div class="fw-semibold" id="editModalOld">—</div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted">New value</div>
                        <div class="fw-semibold" id="editModalNew">—</div>
                    </div>
                </div>
                <hr>
                <h6 class="text-muted text-uppercase small mb-2">Current record state</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="small text-muted">Current Status</div>
                        <div class="fw-semibold" id="editModalCurrentStatus">—</div>
                    </div>
                    <div class="col-md-4 js-jobonly">
                        <div class="small text-muted">Open Positions</div>
                        <div class="fw-semibold" id="editModalOpen">—</div>
                    </div>
                    <div class="col-md-4 js-jobonly">
                        <div class="small text-muted">Corrected Open Position</div>
                        <div class="fw-semibold" id="editModalCorrected">—</div>
                    </div>
                    <div class="col-md-6 js-jobonly">
                        <div class="small text-muted">Remarks Group</div>
                        <div class="fw-semibold" id="editModalRemarksGroup">—</div>
                    </div>
                    <div class="col-md-12">
                        <div class="small text-muted">Remarks entered</div>
                        <div class="border rounded p-2 bg-light-subtle" id="editModalRemarks" style="min-height:2rem; white-space:pre-wrap;">—</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('editDetailModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', (ev) => {
        const btn = ev.relatedTarget;
        if (!btn) return;
        const d = btn.dataset;
        const setText = (id, v) => {
            const el = document.getElementById(id);
            if (el) { el.textContent = (v && v.trim() !== '') ? v : '—'; }
        };
        setText('editModalWhen',           d.when || '');
        setText('editModalEntity',         d.entity || '');
        setText('editModalEditor',         d.editor || '');
        const empText = (d.employerName || '') + ((d.employerId && d.employerId !== '0') ? ' (ID ' + d.employerId + ')' : '');
        setText('editModalEmployer',       empText.trim());
        const jobText = (d.jobTitle || '') + ((d.jobId && d.jobId !== '0') ? ' (Job ID ' + d.jobId + ')' : '');
        setText('editModalJob',            jobText.trim());
        setText('editModalField',          (d.field || '').replaceAll('_', ' '));
        setText('editModalOld',            d.old || '');
        setText('editModalNew',            d.new || '');
        setText('editModalCurrentStatus',  d.currentStatus || '');
        setText('editModalOpen',           d.currentOpenPositions || '');
        setText('editModalCorrected',      d.currentCorrectedOpenPosition || '');
        setText('editModalRemarksGroup',   d.currentRemarksGroup || '');
        setText('editModalRemarks',        d.currentRemarks || '');
        // Hide Job-only sections when the entity is Employer.
        const isJob = (d.entity === 'Job');
        modal.querySelectorAll('.js-jobonly').forEach((el) => { el.style.display = isJob ? '' : 'none'; });
    });
})();
</script>

<?php render_footer(); ?>

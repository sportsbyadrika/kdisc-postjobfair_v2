<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/demand_side_helpers.php';
require_admin();
demand_side_bootstrap();

$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);

$employerRowId = (int) ($_GET['id'] ?? 0);
$mode = ($_GET['mode'] ?? 'view') === 'edit' ? 'edit' : 'view';

$flashMessage = null;
$flashType = 'success';

if ($employerRowId <= 0) {
    render_header('Employer');
    render_page_header('Employer not found', ['icon' => 'bi-exclamation-triangle']);
    echo '<div class="alert alert-danger">Missing employer id.</div>';
    render_footer();
    exit;
}

$employerStmt = db()->prepare('SELECT * FROM demand_employers WHERE id = ?');
$employerStmt->execute([$employerRowId]);
$employer = $employerStmt->fetch();
if (!$employer) {
    render_header('Employer');
    render_page_header('Employer not found', ['icon' => 'bi-exclamation-triangle']);
    echo '<div class="alert alert-danger">Employer not found.</div>';
    render_footer();
    exit;
}

// Access scope: non-administrators must have this employer in their
// assignment scope (either directly or via any of its assigned jobs).
if (($currentUser['role'] ?? '') !== 'administrator') {
    $eid = (int) $employer['employer_id'];
    $inEmployerScope = in_array($eid, demand_get_assigned_employer_ids($userId), true);
    $inJobScope      = in_array($eid, demand_get_employer_ids_from_assigned_jobs($userId), true);
    if (!$inEmployerScope && !$inJobScope) {
        http_response_code(403);
        render_header('Access denied');
        render_page_header('Access denied', ['icon' => 'bi-shield-lock', 'subtitle' => 'This employer is not part of your assignment scope.']);
        echo '<div class="alert alert-danger">This employer isn\'t in your assigned scope. Contact your Administrator or DSM Admin.</div>';
        echo '<a class="btn btn-primary" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>';
        render_footer();
        exit;
    }
}

/* ------------------------------------------------------------------------- *
 * Save handlers (mode=edit)
 * ------------------------------------------------------------------------- */
if (is_post() && $mode === 'edit') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_employer') {
        $newActive = trim((string) ($_POST['active_status'] ?? ''));
        $newRemarks = trim((string) ($_POST['remarks'] ?? ''));
        $allowedActive = demand_employer_active_status_options();
        if ($newActive !== '' && !in_array($newActive, $allowedActive, true)) {
            $flashMessage = 'Invalid active status value.';
            $flashType = 'danger';
        } else {
            $oldActive = (string) ($employer['active_status'] ?? '');
            $oldRemarks = (string) ($employer['remarks'] ?? '');
            $update = db()->prepare('UPDATE demand_employers SET active_status = ?, remarks = ?, task_owner_id = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
            $update->execute([
                $newActive === '' ? null : $newActive,
                $newRemarks === '' ? null : $newRemarks,
                $userId,
                $userId,
                $employerRowId,
            ]);
            demand_write_edit_log('employer', $employerRowId, 'active_status', $oldActive, $newActive, $userId);
            demand_write_edit_log('employer', $employerRowId, 'remarks', $oldRemarks, $newRemarks, $userId);
            $flashMessage = 'Employer updated.';
            // Refresh
            $employerStmt->execute([$employerRowId]);
            $employer = $employerStmt->fetch();
        }
    } elseif ($action === 'save_job_field') {
        // AJAX per-field autosave. Returns JSON so the client can show
        // subtle Saving / Saved / error feedback without a full page reload.
        header('Content-Type: application/json');
        $jobRowId = (int) ($_POST['job_row_id'] ?? 0);
        $field    = (string) ($_POST['field'] ?? '');
        $value    = (string) ($_POST['value'] ?? '');
        $editableJob = [
            'posted_on'                => 'date',
            'posted_by'                => 'text',
            'expired_date'             => 'date',
            'corrected_open_position'  => 'int',
            'status'                   => 'enum',
            'remarks'                  => 'text',
            'remarks_group_id'         => 'int',
        ];
        if ($jobRowId <= 0 || !isset($editableJob[$field])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid field or job']);
            exit;
        }
        $jobStmt = db()->prepare('SELECT * FROM demand_employer_jobs WHERE id = ? AND emp_id = ?');
        $jobStmt->execute([$jobRowId, (int) $employer['employer_id']]);
        $existingJob = $jobStmt->fetch();
        if (!$existingJob) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            exit;
        }
        // Coerce the incoming value per its expected type.
        switch ($editableJob[$field]) {
            case 'date':
                $newDb = demand_parse_date($value);
                if ($value !== '' && $newDb === null) {
                    echo json_encode(['ok' => false, 'error' => "Bad date '$value'"]);
                    exit;
                }
                break;
            case 'int':
                if (trim($value) === '') { $newDb = null; }
                else {
                    $newDb = demand_parse_int($value);
                    if ($newDb === null) { echo json_encode(['ok' => false, 'error' => 'Not an integer']); exit; }
                }
                break;
            case 'enum':
                $allowed = demand_employer_job_status_options();
                if ($value !== '' && !in_array($value, $allowed, true)) {
                    echo json_encode(['ok' => false, 'error' => 'Invalid status']);
                    exit;
                }
                $newDb = $value === '' ? null : $value;
                break;
            default: // text
                $newDb = trim($value) === '' ? null : trim($value);
        }
        // Server-side mirror of the Valid/Invalid rules so a tampered call
        // can't persist forbidden values.
        $currentStatus = (string) ($existingJob['status'] ?? '');
        if ($field === 'status') {
            $currentStatus = (string) $newDb;
        }
        if ($field === 'corrected_open_position' && ($currentStatus === 'Valid' || $currentStatus === 'Invalid')) {
            $newDb = null;
        }
        if ($field === 'remarks_group_id' && $currentStatus === 'Valid') {
            $newDb = null;
        }
        // Compute old-value string for the edit log using the same shape the
        // field's storage naturally serialises to.
        $oldRaw = $existingJob[$field] ?? null;
        $oldStr = $field === 'posted_on' || $field === 'expired_date'
            ? substr((string) ($oldRaw ?? ''), 0, 10)
            : (string) ($oldRaw ?? '');
        $newStr = $field === 'posted_on' || $field === 'expired_date'
            ? (string) ($newDb ?? '')
            : (string) ($newDb ?? '');
        $upd = db()->prepare("UPDATE demand_employer_jobs SET $field = ?, task_owner_id = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
        $upd->execute([$newDb, $userId, $userId, $jobRowId]);
        if ($oldStr !== $newStr) {
            demand_write_edit_log('job', $jobRowId, $field, $oldStr, $newStr, $userId);
        }
        echo json_encode(['ok' => true, 'stored' => $newStr]);
        exit;
    } elseif ($action === 'bulk_update_jobs') {
        // Bulk apply Status / Remarks Group / Remarks to a checkbox-selected
        // set of job rows. Blank field values are skipped so the operator
        // can update a subset.
        $ids = $_POST['job_ids'] ?? [];
        if (!is_array($ids)) { $ids = [$ids]; }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, static fn(int $v): bool => $v > 0));
        $bStatus         = trim((string) ($_POST['bulk_status'] ?? ''));
        $bRemarksGroupId = trim((string) ($_POST['bulk_remarks_group_id'] ?? ''));
        $bRemarks        = trim((string) ($_POST['bulk_remarks'] ?? ''));
        $bRemarksGroupId = ($bRemarksGroupId === '' ? null : (int) $bRemarksGroupId);
        $allowed = demand_employer_job_status_options();
        if ($ids === []) {
            $flashMessage = 'Bulk update: no rows were selected.';
            $flashType = 'warning';
        } elseif ($bStatus !== '' && !in_array($bStatus, $allowed, true)) {
            $flashMessage = 'Invalid status value for bulk update.';
            $flashType = 'danger';
        } else {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $rows = db()->prepare("SELECT * FROM demand_employer_jobs WHERE emp_id = ? AND id IN ($ph)");
            $rows->execute([(int) $employer['employer_id'], ...$ids]);
            $affected = 0;
            foreach ($rows->fetchAll() as $r) {
                $rid = (int) $r['id'];
                $sets = []; $params = [];
                $statusNow = (string) ($r['status'] ?? '');
                if ($bStatus !== '') {
                    $sets[] = 'status = ?'; $params[] = $bStatus;
                    demand_write_edit_log('job', $rid, 'status', $statusNow, $bStatus, $userId);
                    $statusNow = $bStatus;
                    // Apply the same Valid/Invalid rules.
                    if ($bStatus === 'Valid') {
                        $sets[] = 'corrected_open_position = ?'; $params[] = null;
                        $sets[] = 'remarks_group_id = ?';         $params[] = null;
                        demand_write_edit_log('job', $rid, 'corrected_open_position', (string) ($r['corrected_open_position'] ?? ''), '', $userId);
                        demand_write_edit_log('job', $rid, 'remarks_group_id', (string) ($r['remarks_group_id'] ?? ''), '', $userId);
                    } elseif ($bStatus === 'Invalid') {
                        $sets[] = 'corrected_open_position = ?'; $params[] = null;
                        demand_write_edit_log('job', $rid, 'corrected_open_position', (string) ($r['corrected_open_position'] ?? ''), '', $userId);
                    }
                }
                if ($bRemarksGroupId !== null && $statusNow !== 'Valid') {
                    $sets[] = 'remarks_group_id = ?'; $params[] = $bRemarksGroupId;
                    demand_write_edit_log('job', $rid, 'remarks_group_id', (string) ($r['remarks_group_id'] ?? ''), (string) $bRemarksGroupId, $userId);
                }
                if ($bRemarks !== '') {
                    $sets[] = 'remarks = ?'; $params[] = $bRemarks;
                    demand_write_edit_log('job', $rid, 'remarks', (string) ($r['remarks'] ?? ''), $bRemarks, $userId);
                }
                if ($sets === []) { continue; }
                $sets[] = 'task_owner_id = ?'; $params[] = $userId;
                $sets[] = 'updated_by = ?';    $params[] = $userId;
                $sets[] = 'updated_at = NOW()';
                $params[] = $rid;
                db()->prepare('UPDATE demand_employer_jobs SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
                $affected++;
            }
            $flashMessage = "Bulk update applied to $affected row(s).";
        }
    } elseif ($action === 'save_job') {
        $jobRowId = (int) ($_POST['job_row_id'] ?? 0);
        if ($jobRowId <= 0) {
            $flashMessage = 'Invalid job id.';
            $flashType = 'danger';
        } else {
            $jobStmt = db()->prepare('SELECT * FROM demand_employer_jobs WHERE id = ? AND emp_id = ?');
            $jobStmt->execute([$jobRowId, (int) $employer['employer_id']]);
            $job = $jobStmt->fetch();
            if (!$job) {
                $flashMessage = 'Job not found for this employer.';
                $flashType = 'danger';
            } else {
                $postedOn = demand_parse_date((string) ($_POST['posted_on'] ?? ''));
                $postedBy = trim((string) ($_POST['posted_by'] ?? ''));
                $expiredDate = demand_parse_date((string) ($_POST['expired_date'] ?? ''));
                $correctedPos = demand_parse_int((string) ($_POST['corrected_open_position'] ?? ''));
                $status = trim((string) ($_POST['status'] ?? ''));
                $remarks = trim((string) ($_POST['remarks'] ?? ''));
                $remarksGroupId = demand_parse_int((string) ($_POST['remarks_group_id'] ?? ''));
                $allowedStatus = demand_employer_job_status_options();
                if ($status !== '' && !in_array($status, $allowedStatus, true)) {
                    $flashMessage = 'Invalid status value.';
                    $flashType = 'danger';
                } else {
                    // Server-side mirror of the client-side edit rules on
                    // status: Valid clears both corrected and remarks group,
                    // Invalid clears only corrected. Prevents a tampered form
                    // from persisting values the UI wouldn't allow.
                    if ($status === 'Valid') {
                        $correctedPos = null;
                        $remarksGroupId = null;
                    } elseif ($status === 'Invalid') {
                        $correctedPos = null;
                    }
                    $update = db()->prepare('UPDATE demand_employer_jobs
                        SET posted_on = ?, posted_by = ?, expired_date = ?, corrected_open_position = ?,
                            status = ?, remarks = ?, remarks_group_id = ?, task_owner_id = ?, updated_by = ?, updated_at = NOW()
                        WHERE id = ?');
                    $update->execute([
                        $postedOn,
                        $postedBy === '' ? null : $postedBy,
                        $expiredDate,
                        $correctedPos,
                        $status === '' ? null : $status,
                        $remarks === '' ? null : $remarks,
                        $remarksGroupId,
                        $userId,
                        $userId,
                        $jobRowId,
                    ]);
                    demand_write_edit_log('job', $jobRowId, 'posted_on', (string) ($job['posted_on'] ?? ''), (string) ($postedOn ?? ''), $userId);
                    demand_write_edit_log('job', $jobRowId, 'posted_by', (string) ($job['posted_by'] ?? ''), $postedBy, $userId);
                    demand_write_edit_log('job', $jobRowId, 'expired_date', (string) ($job['expired_date'] ?? ''), (string) ($expiredDate ?? ''), $userId);
                    demand_write_edit_log('job', $jobRowId, 'corrected_open_position', (string) ($job['corrected_open_position'] ?? ''), (string) ($correctedPos ?? ''), $userId);
                    demand_write_edit_log('job', $jobRowId, 'status', (string) ($job['status'] ?? ''), $status, $userId);
                    demand_write_edit_log('job', $jobRowId, 'remarks', (string) ($job['remarks'] ?? ''), $remarks, $userId);
                    demand_write_edit_log('job', $jobRowId, 'remarks_group_id', (string) ($job['remarks_group_id'] ?? ''), (string) ($remarksGroupId ?? ''), $userId);
                    $flashMessage = 'Job updated (Job ID ' . (int) $job['job_id'] . ').';
                }
            }
        }
    }
}

/* ------------------------------------------------------------------------- *
 * Load jobs list (post-save so counts reflect updates). Supports comma-
 * separated Job ID search, Job Title LIKE search and column sort on
 * job_id / jobtitle / open_positions / posted_on via GET params. All
 * search/sort state is carried via GET only, so the row-level Save POSTs
 * on this page are unaffected.
 * ------------------------------------------------------------------------- */
$jobIdSearch        = trim((string) ($_GET['job_ids_search']    ?? ''));
$jobTitleSearch     = trim((string) ($_GET['job_title_search']  ?? ''));
$jobPostStatusFilter = trim((string) ($_GET['job_post_status']  ?? ''));
$rawJobSort     = strtolower((string) ($_GET['job_sort']    ?? 'job_id'));
$rawJobDir      = strtolower((string) ($_GET['job_dir']     ?? 'asc'));
$allowedJobSort = [
    'job_id'         => 'j.job_id',
    'jobtitle'       => 'j.jobtitle',
    'open_positions' => 'j.open_positions',
    'posted_on'      => 'j.posted_on',
];
$jobSort = array_key_exists($rawJobSort, $allowedJobSort) ? $rawJobSort : 'job_id';
$jobDir  = ($rawJobDir === 'desc') ? 'desc' : 'asc';

$jobConds = ['j.emp_id = ?'];
$jobParams = [(int) $employer['employer_id']];
$parsedJobIdSearch = demand_parse_employer_id_list($jobIdSearch);
if ($parsedJobIdSearch !== []) {
    $ph = implode(',', array_fill(0, count($parsedJobIdSearch), '?'));
    $jobConds[] = "j.job_id IN ($ph)";
    foreach ($parsedJobIdSearch as $jid) { $jobParams[] = $jid; }
}
if ($jobTitleSearch !== '') {
    $jobConds[] = 'j.jobtitle LIKE ?';
    $jobParams[] = '%' . $jobTitleSearch . '%';
}
if ($jobPostStatusFilter !== '') {
    // DWMS source posting status filter (`job_status_data`) — same rule as
    // the Employer listing filter: '(Unknown)' targets rows where the
    // source status is NULL or blank.
    if ($jobPostStatusFilter === '(Unknown)') {
        $jobConds[] = '(j.job_status_data IS NULL OR TRIM(j.job_status_data) = "")';
    } else {
        $jobConds[] = 'TRIM(j.job_status_data) = ?';
        $jobParams[] = $jobPostStatusFilter;
    }
}
// Per-user job scope. Rule per requirement:
//   - Assigning an employer means "you own every job of that employer",
//     BUT
//   - Assigning specific job_ids of that same employer narrows the view
//     to exactly those job_ids on that employer (specific-job assignment
//     is treated as an explicit narrowing intent even when the employer
//     is also in the user's employer scope).
//   - For an employer reached only via job-scope, we naturally narrow to
//     the assigned job_ids that belong to this employer.
//   - A pure employer-scope user (no job assignments at all) sees every
//     job of the employer.
// Administrator is unscoped anywhere else so we still let them through.
if (($currentUser['role'] ?? '') !== 'administrator') {
    $viewerAssignedJobs = demand_get_assigned_job_ids($userId);
    if ($viewerAssignedJobs !== []) {
        $eidCurrent = (int) $employer['employer_id'];
        $ph = implode(',', array_fill(0, count($viewerAssignedJobs), '?'));
        // Intersection = viewer's assigned jobs that live on THIS employer.
        $interStmt = db()->prepare("SELECT job_id FROM demand_employer_jobs WHERE emp_id = ? AND job_id IN ($ph)");
        $interStmt->execute([$eidCurrent, ...$viewerAssignedJobs]);
        $viewerAssignedJobsOnThisEmployer = array_map(
            static fn(array $r): int => (int) $r['job_id'],
            $interStmt->fetchAll()
        );
        if ($viewerAssignedJobsOnThisEmployer !== []) {
            $ph2 = implode(',', array_fill(0, count($viewerAssignedJobsOnThisEmployer), '?'));
            $jobConds[] = "j.job_id IN ($ph2)";
            foreach ($viewerAssignedJobsOnThisEmployer as $jid) { $jobParams[] = $jid; }
        }
        // Empty intersection → this employer is reached only via employer
        // scope, so no additional restriction — every job is visible.
        // (Access guard above already denied users who have neither.)
    }
}
$jobWhereSql = 'WHERE ' . implode(' AND ', $jobConds);
$jobOrderSql = 'ORDER BY ' . $allowedJobSort[$jobSort] . ' ' . ($jobDir === 'desc' ? 'DESC' : 'ASC');

// Pagination for the Jobs listing (view + edit). Page size restricted to
// multiples of 25 so the server never renders more than 200 job rows at
// a time. CSV download below still returns the full filtered set.
$jobsPerPageAllowed = [25, 50, 100, 200];
$jobsPerPage = (int) ($_GET['jobs_per_page'] ?? 25);
if (!in_array($jobsPerPage, $jobsPerPageAllowed, true)) { $jobsPerPage = 25; }
$jobsPage = max(1, (int) ($_GET['page'] ?? 1));
// jobs_page is a legacy alias in case a bookmarked URL uses it; page wins.
if ($jobsPage === 1 && ($_GET['jobs_page'] ?? '') !== '') {
    $jobsPage = max(1, (int) $_GET['jobs_page']);
}

// Distinct source-posting-status values across this employer's jobs — for
// the filter dropdown below. Pulled once per page load and grouped so blank
// / NULL rows collapse to a single '(Unknown)' entry the operator can still
// pick from. Scoped by emp_id (not by the current filter set) so the
// dropdown always reflects every real bucket, not just those matching the
// currently selected filter.
$jsdStmt = db()->prepare("SELECT DISTINCT job_status_data AS value FROM demand_employer_jobs WHERE emp_id = ?");
$jsdStmt->execute([(int) $employer['employer_id']]);
$jobPostStatusOptionsRaw = array_map(
    static fn(array $r): string => trim((string) $r['value']),
    $jsdStmt->fetchAll()
);
$jobPostStatusOptions = [];
$hasBlankJobPostStatus = false;
foreach ($jobPostStatusOptionsRaw as $v) {
    if ($v === '') { $hasBlankJobPostStatus = true; continue; }
    $jobPostStatusOptions[] = $v;
}
sort($jobPostStatusOptions, SORT_NATURAL | SORT_FLAG_CASE);
if ($hasBlankJobPostStatus) { $jobPostStatusOptions[] = '(Unknown)'; }

$jobsCountStmt = db()->prepare("SELECT COUNT(*) FROM demand_employer_jobs j $jobWhereSql");
$jobsCountStmt->execute($jobParams);
$jobsMatchingCount = (int) $jobsCountStmt->fetchColumn();
$jobsTotalPages = max(1, (int) ceil($jobsMatchingCount / $jobsPerPage));
$jobsPage = min($jobsPage, $jobsTotalPages);
$jobsOffset = ($jobsPage - 1) * $jobsPerPage;

// CSV download — always the full filtered set, not just the current page.
if (($_GET['jobs_download'] ?? '') === 'csv') {
    $fullStmt = db()->prepare("SELECT j.job_id, j.jobtitle, j.open_positions, j.status, j.posted_on, j.expired_date
        FROM demand_employer_jobs j
        $jobWhereSql
        $jobOrderSql");
    $fullStmt->execute($jobParams);
    $filename = 'employer_' . (int) $employer['employer_id'] . '_jobs_' . date('Ymd_His') . '.csv';
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel-friendly BOM
    fputcsv($out, ['job_id', 'jobtitle', 'open_positions', 'status', 'posted_on', 'expired_date']);
    while ($jr = $fullStmt->fetch()) {
        fputcsv($out, [
            (int) ($jr['job_id'] ?? 0),
            (string) ($jr['jobtitle'] ?? ''),
            (string) ($jr['open_positions'] ?? ''),
            (string) ($jr['status'] ?? ''),
            substr((string) ($jr['posted_on'] ?? ''), 0, 10),
            substr((string) ($jr['expired_date'] ?? ''), 0, 10),
        ]);
    }
    fclose($out);
    exit;
}

$jobsStmt = db()->prepare("SELECT j.*, u.name AS task_owner_name, rg.name AS remarks_group_name
    FROM demand_employer_jobs j
    LEFT JOIN users u ON u.id = j.task_owner_id
    LEFT JOIN demand_remarks_groups rg ON rg.id = j.remarks_group_id
    $jobWhereSql
    $jobOrderSql
    LIMIT ? OFFSET ?");
$jobsStmt->execute([...$jobParams, $jobsPerPage, $jobsOffset]);
$jobs = $jobsStmt->fetchAll();

// Vacancies total across ALL jobs for this employer (independent of the
// current search) so the header chip always shows the true figure.
$vacStmt = db()->prepare('SELECT COUNT(*) AS jobs_count, COALESCE(SUM(open_positions), 0) AS positions
    FROM demand_employer_jobs WHERE emp_id = ?');
$vacStmt->execute([(int) $employer['employer_id']]);
$vacRow = $vacStmt->fetch() ?: ['jobs_count' => 0, 'positions' => 0];
$employerJobsCount = (int) $vacRow['jobs_count'];
$employerVacancies = (int) $vacRow['positions'];

// URL builder for column-sort links. Sort resets to page 1 (per-page size
// is preserved) so the operator doesn't land on an out-of-range page after
// re-sorting.
$jobSortLink = static function (string $col, string $label) use ($jobSort, $jobDir, $jobIdSearch, $jobTitleSearch, $jobPostStatusFilter, $jobsPerPage): string {
    $nextDir = ($jobSort === $col && $jobDir === 'asc') ? 'desc' : 'asc';
    $params = array_filter([
        'id'               => (int) ($_GET['id'] ?? 0),
        'mode'             => (string) ($_GET['mode'] ?? 'view'),
        'job_ids_search'   => $jobIdSearch,
        'job_title_search' => $jobTitleSearch,
        'job_post_status'  => $jobPostStatusFilter,
        'job_sort'         => $col,
        'job_dir'          => $nextDir,
        'jobs_per_page'    => $jobsPerPage,
        'page'        => 1,
    ], static fn($v): bool => $v !== '' && $v !== 0);
    $arrow = '';
    if ($jobSort === $col) {
        $arrow = $jobDir === 'asc' ? ' <i class="bi bi-caret-up-fill small"></i>' : ' <i class="bi bi-caret-down-fill small"></i>';
    }
    return '<a class="text-decoration-none text-reset" href="/demand_side_employer_edit.php?' . esc(http_build_query($params)) . '#jobs">' . esc($label) . $arrow . '</a>';
};

$remarksGroups = db()->query('SELECT id, name FROM demand_remarks_groups WHERE active = 1 ORDER BY name ASC')->fetchAll();

$employerLogs = db()->prepare('SELECT l.*, u.name AS editor_name FROM demand_employer_edit_log l LEFT JOIN users u ON u.id = l.edited_by WHERE l.employer_row_id = ? ORDER BY l.edited_at DESC, l.id DESC LIMIT 200');
$employerLogs->execute([$employerRowId]);
$employerLogRows = $employerLogs->fetchAll();

// Employer Jobs Edit History — paginated, scoped to ALL jobs of the current
// employer (not just the jobs on the current page). Page size restricted to
// multiples of 25 so the response stays small.
$histPerPageAllowed = [25, 50, 100, 200];
$histPerPage = (int) ($_GET['hist_per_page'] ?? 25);
if (!in_array($histPerPage, $histPerPageAllowed, true)) { $histPerPage = 25; }
$histPage = max(1, (int) ($_GET['hist_page'] ?? 1));

$empIdForHist = (int) $employer['employer_id'];
$histCountStmt = db()->prepare('SELECT COUNT(*)
    FROM demand_employer_job_edit_log l
    INNER JOIN demand_employer_jobs j ON j.id = l.job_row_id
    WHERE j.emp_id = ?');
$histCountStmt->execute([$empIdForHist]);
$histTotalCount = (int) $histCountStmt->fetchColumn();
$histTotalPages = max(1, (int) ceil($histTotalCount / $histPerPage));
$histPage = min($histPage, $histTotalPages);
$histOffset = ($histPage - 1) * $histPerPage;

$jobLogRows = [];
if ($histTotalCount > 0) {
    $stmt = db()->prepare('SELECT l.*, u.name AS editor_name, j.job_id
        FROM demand_employer_job_edit_log l
        LEFT JOIN users u ON u.id = l.edited_by
        INNER JOIN demand_employer_jobs j ON j.id = l.job_row_id
        WHERE j.emp_id = ?
        ORDER BY l.edited_at DESC, l.id DESC
        LIMIT ? OFFSET ?');
    $stmt->execute([$empIdForHist, $histPerPage, $histOffset]);
    $jobLogRows = $stmt->fetchAll();
}

$isEdit = ($mode === 'edit');

render_header('Employer ' . ($isEdit ? 'Edit' : 'View'), ['main_container_class' => 'container-fluid']);
render_page_header('Employer ' . ($isEdit ? 'Edit' : 'View') . ' · ' . esc((string) ($employer['employer_name'] ?? '')), [
    'icon' => $isEdit ? 'bi-pencil-square' : 'bi-eye',
    'subtitle' => 'Employer ID ' . (int) $employer['employer_id'] . ' · ' . (int) count($jobs) . ' job(s)',
    'actions' => '<a class="btn btn-light me-1" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to List</a>'
        . ($isEdit
            ? '<a class="btn btn-outline-secondary" href="/demand_side_employer_edit.php?id=' . $employerRowId . '&mode=view"><i class="bi bi-eye me-1"></i>View mode</a>'
            : '<a class="btn btn-primary" href="/demand_side_employer_edit.php?id=' . $employerRowId . '&mode=edit"><i class="bi bi-pencil-square me-1"></i>Edit mode</a>'),
]);

if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?> d-flex align-items-start gap-2">
        <i class="bi bi-<?= $flashType === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill' ?> mt-1"></i>
        <span><?= esc($flashMessage) ?></span>
    </div>
<?php endif; ?>

<?php
/**
 * $renderKvTable(title, rows) prints a striped 2-column table where each row
 * is [Field, Value]. Values are bold; empty values fall through to a muted
 * em-dash. Alternate row colouring comes from Bootstrap's table-striped.
 */
$renderKvTable = static function (string $title, array $pairs): void {
    ?>
    <div class="mb-3">
        <div class="detail-group-title mb-1"><?= esc($title) ?></div>
        <div class="table-responsive">
            <table class="table table-sm table-striped table-bordered align-middle mb-0" style="table-layout: fixed;">
                <colgroup>
                    <col style="width: 38%;">
                    <col>
                </colgroup>
                <tbody>
                <?php foreach ($pairs as [$k, $v]): ?>
                    <?php $val = (string) ($v ?? ''); ?>
                    <tr>
                        <th class="fw-normal text-muted"><?= esc($k) ?></th>
                        <td class="fw-bold text-break"><?= $val === '' ? '<span class="text-muted fw-normal">&mdash;</span>' : nl2br(esc($val)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
};

$ownerName = '';
if ((int) ($employer['task_owner_id'] ?? 0) > 0) {
    $ownerStmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $ownerStmt->execute([(int) $employer['task_owner_id']]);
    $ownerName = (string) ($ownerStmt->fetchColumn() ?: '');
}

$nicJoin = static function (?string $code, ?string $name): string {
    $c = trim((string) ($code ?? ''));
    $n = trim((string) ($name ?? ''));
    if ($c === '' && $n === '') return '';
    if ($c === '') return $n;
    if ($n === '') return $c;
    return $c . ' — ' . $n;
};
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-building text-primary me-1"></i>Employer</span>
        <?php if ($isEdit): ?><span class="status-chip status-warning"><i class="bi bi-pencil-square me-1"></i>Edit mode</span><?php endif; ?>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-6">
                <?php $renderKvTable('Identity', [
                    ['Employer ID',           (string) $employer['employer_id']],
                    ['Clustered Employer ID', (string) ($employer['clustered_employer_id'] ?? '')],
                    ['Employer Name',         (string) ($employer['employer_name'] ?? '')],
                    ['Cluster Employer Name', (string) ($employer['clusteremployername'] ?? '')],
                    ['Website',               (string) ($employer['website'] ?? '')],
                    ['Company Address',       (string) ($employer['company_address'] ?? '')],
                    ['Type of Company',       (string) ($employer['type_of_company'] ?? '')],
                    ['Created',               (string) ($employer['created_datetime'] ?? '')],
                ]); ?>
            </div>
            <div class="col-lg-6">
                <?php $renderKvTable('Job Agency & Flags', [
                    ['Job Agency',              (string) ($employer['jobagency'] ?? '')],
                    ['Job Agency ID',           (string) ($employer['job_agency_id'] ?? '')],
                    ['Job Fair Flag',           (string) ($employer['jobfair_flag'] ?? '')],
                    ['VK Flag',                 (string) ($employer['vk_flag'] ?? '')],
                    ['Final Status',            (string) ($employer['final_status'] ?? '')],
                    ['Task Owner (last edit)',  $ownerName],
                ]); ?>
            </div>
            <div class="col-lg-6">
                <?php $renderKvTable('NIC Classification (1/2)', [
                    ['Section',  $nicJoin($employer['nic_section_code']  ?? '', $employer['nic_section_name']  ?? '')],
                    ['Division', $nicJoin($employer['nic_division_code'] ?? '', $employer['nic_division_name'] ?? '')],
                    ['Group',    $nicJoin($employer['nic_group_code']    ?? '', $employer['nic_group_name']    ?? '')],
                ]); ?>
            </div>
            <div class="col-lg-6">
                <?php $renderKvTable('NIC Classification (2/2)', [
                    ['Class',                     $nicJoin($employer['nic_class_code']     ?? '', $employer['nic_class_name']     ?? '')],
                    ['Sub-class',                 $nicJoin($employer['nic_sub_class_code'] ?? '', $employer['nic_sub_class_name'] ?? '')],
                    ['Reason for Classification', (string) ($employer['reason_for_classification'] ?? '')],
                ]); ?>
            </div>
        </div>

        <?php if ($isEdit): ?>
            <hr>
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="save_employer">
                <div class="col-md-4">
                    <label class="form-label">Active Status</label>
                    <select class="form-select" name="active_status">
                        <option value="">Select</option>
                        <?php foreach (demand_employer_active_status_options() as $opt): ?>
                            <option value="<?= esc($opt) ?>" <?= ((string) ($employer['active_status'] ?? '')) === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Remarks</label>
                    <textarea class="form-control" name="remarks" rows="2"><?= esc((string) ($employer['remarks'] ?? '')) ?></textarea>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save employer</button>
                </div>
            </form>
        <?php else: ?>
            <hr>
            <div class="row g-3">
                <div class="col-md-4"><span class="form-label">Active Status</span><div><?= render_status_chip((string) ($employer['active_status'] ?? '')) ?></div></div>
                <div class="col-md-8"><span class="form-label">Remarks</span><div class="border rounded p-2 bg-light-subtle"><?= nl2br(esc((string) ($employer['remarks'] ?? ''))) ?: '<span class="text-muted">—</span>' ?></div></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4" id="jobs">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-briefcase text-primary me-1"></i>Employer Jobs <span class="text-muted small ms-1">emp_id = <?= (int) $employer['employer_id'] ?></span></span>
        <div class="d-flex align-items-center gap-2">
            <?php $showing = count($jobs); ?>
            <?php if ($showing !== $employerJobsCount || $jobsMatchingCount !== $employerJobsCount): ?>
                <span class="status-chip status-neutral" title="Rows on this page / rows matching current filter"><?= number_format($showing) ?> on page · <?= number_format($jobsMatchingCount) ?> matched</span>
            <?php endif; ?>
            <span class="status-chip status-info"><?= number_format($employerJobsCount) ?> jobs</span>
            <span class="status-chip status-success"><?= number_format($employerVacancies) ?> vacancies</span>
        </div>
    </div>
    <div class="card-body pb-2">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="id" value="<?= $employerRowId ?>">
            <input type="hidden" name="mode" value="<?= esc($mode) ?>">
            <input type="hidden" name="job_sort" value="<?= esc($jobSort) ?>">
            <input type="hidden" name="job_dir" value="<?= esc($jobDir) ?>">
            <div class="col-md-3">
                <label class="form-label small mb-1">Job IDs <span class="text-muted">(comma separated)</span></label>
                <input type="text" class="form-control form-control-sm" name="job_ids_search" value="<?= esc($jobIdSearch) ?>" placeholder="e.g. 1234, 5678">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Job Title contains</label>
                <input type="text" class="form-control form-control-sm" name="job_title_search" value="<?= esc($jobTitleSearch) ?>" placeholder="Search title">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Job Posting Status</label>
                <select class="form-select form-select-sm" name="job_post_status">
                    <option value="">All</option>
                    <?php foreach ($jobPostStatusOptions as $opt): ?>
                        <option value="<?= esc($opt) ?>" <?= $jobPostStatusFilter === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Per page</label>
                <select class="form-select form-select-sm" name="jobs_per_page" onchange="this.form.submit()">
                    <?php foreach ($jobsPerPageAllowed as $pp): ?>
                        <option value="<?= (int) $pp ?>" <?= $jobsPerPage === $pp ? 'selected' : '' ?>><?= (int) $pp ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2 flex-wrap">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Search</button>
                <a class="btn btn-sm btn-light" href="/demand_side_employer_edit.php?id=<?= $employerRowId ?>&mode=<?= esc($mode) ?>#jobs">Reset</a>
                <?php
                    $jobsCsvUrl = '/demand_side_employer_edit.php?' . http_build_query(array_filter([
                        'id' => $employerRowId,
                        'mode' => $mode,
                        'job_ids_search' => $jobIdSearch,
                        'job_title_search' => $jobTitleSearch,
                        'job_post_status' => $jobPostStatusFilter,
                        'job_sort' => $jobSort,
                        'job_dir' => $jobDir,
                        'jobs_download' => 'csv',
                    ], static fn($v): bool => $v !== '' && $v !== null));
                ?>
                <a class="btn btn-sm btn-light" href="<?= esc($jobsCsvUrl) ?>" title="Download the full filtered result set as CSV"><i class="bi bi-download me-1"></i>Download CSV</a>
                <?php if ($isEdit): ?>
                    <button type="button" class="btn btn-sm btn-warning ms-auto" id="bulkUpdateBtn" data-bs-toggle="modal" data-bs-target="#bulkUpdateModal" disabled>
                        <i class="bi bi-list-check me-1"></i>Bulk Update <span class="badge bg-dark ms-1" id="bulkUpdateCount">0</span>
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <?php
        $jobsPaginationBase = array_filter([
            'id'               => $employerRowId,
            'mode'             => $mode,
            'job_ids_search'   => $jobIdSearch,
            'job_title_search' => $jobTitleSearch,
            'job_post_status'  => $jobPostStatusFilter,
            'job_sort'         => $jobSort,
            'job_dir'          => $jobDir,
            'jobs_per_page'    => $jobsPerPage,
        ], static fn($v): bool => $v !== '' && $v !== null && $v !== 0);
    ?>
    <div class="card-body pt-0 pb-2">
        <?php render_pagination($jobsPage, $jobsTotalPages, $jobsMatchingCount, $jobsPerPage, '/demand_side_employer_edit.php', $jobsPaginationBase, 'Employer Jobs pagination'); ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead>
                    <?php
                        // Extra source-only columns surface only in view mode
                        // (they aren't editable per spec, so keeping them out
                        // of the edit form keeps the row narrow).
                        $extraJobCols = [
                            'job_status_data'        => 'Job Status',
                            'min_experience'         => 'Min Experience',
                            'max_experience'         => 'Max Experience',
                            'academic_preference'    => 'Academic Preference',
                            'job_sector'             => 'Job Sector',
                            'location'               => 'Location',
                            'domain_skills'          => 'Domain Skills',
                            'soft_skills'            => 'Soft Skills',
                            'job_agency'             => 'Job Agency',
                            'specialization'         => 'Specialization',
                            'courses'                => 'Courses',
                            'location_type'          => 'Location Type',
                            'employment_mode'        => 'Employment Mode',
                            'age_preference'         => 'Age Preference',
                            'gender_preference'      => 'Gender Preference',
                            'job_category'           => 'Job Category',
                            'job_sub_category'       => 'Job Sub Category',
                            'jobfair_only_job'       => 'Job Fair Only Job',
                            'posted_in_job_fair'     => 'Posted in Job Fair',
                            'number_of_applications' => '# Applications',
                        ];
                        $extraColCount = $isEdit ? 0 : count($extraJobCols);
                        // Edit mode is now narrower — 11 cols (checkbox + 9 data
                        // + applicants). View mode keeps its full 14 + extras.
                        $editCols = 11;
                        $viewCols = 14;
                        $totalCols = ($isEdit ? $editCols : $viewCols) + $extraColCount;
                        // Renders the four applicant-funnel counts as a
                        // compact 2x2 grid inside a single table cell so the
                        // Jobs table stays scannable. Called from both the
                        // edit and view branches below.
                        $renderApplicantCounts = static function (array $job): string {
                            $fmt = static function ($v): string {
                                return $v === null || $v === '' ? '<span class="text-muted">&mdash;</span>' : number_format((int) $v);
                            };
                            return
                                '<div class="small" style="line-height:1.25;min-width:120px;">'
                                . '<div class="d-flex justify-content-between gap-2"><span class="text-success">Sel</span>'
                                    . '<span class="fw-semibold">' . $fmt($job['selected']    ?? null) . '</span></div>'
                                . '<div class="d-flex justify-content-between gap-2"><span class="text-primary">Sht</span>'
                                    . '<span class="fw-semibold">' . $fmt($job['shortlisted'] ?? null) . '</span></div>'
                                . '<div class="d-flex justify-content-between gap-2"><span class="text-warning">OH</span>'
                                    . '<span class="fw-semibold">' . $fmt($job['onhold']      ?? null) . '</span></div>'
                                . '<div class="d-flex justify-content-between gap-2"><span class="text-danger">Rej</span>'
                                    . '<span class="fw-semibold">' . $fmt($job['rejected']    ?? null) . '</span></div>'
                                . '</div>';
                        };
                    ?>
                    <?php if ($isEdit): ?>
                    <tr>
                        <th><input type="checkbox" id="jobsSelectAll" title="Select all"></th>
                        <th><?= $jobSortLink('job_id', 'Job ID') ?></th>
                        <th><?= $jobSortLink('jobtitle', 'Job Title') ?></th>
                        <th class="text-end"><?= $jobSortLink('open_positions', 'Open Positions') ?></th>
                        <th>Qualification<br><span class="small text-muted">Salary</span></th>
                        <th><?= $jobSortLink('posted_on', 'Posted On') ?><br><span class="small text-muted">Expired Date</span></th>
                        <th>Posted By</th>
                        <th>Status<br><span class="small text-muted">Corr. Open Pos.</span></th>
                        <th>Remarks Group<br><span class="small text-muted">Remarks</span></th>
                        <th>Task Owner</th>
                        <th title="Sel = Selected · Sht = Shortlisted · OH = On Hold · Rej = Rejected">Applicants</th>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <th><?= $jobSortLink('job_id', 'Job ID') ?></th>
                        <th><?= $jobSortLink('jobtitle', 'Job Title') ?></th>
                        <th class="text-end"><?= $jobSortLink('open_positions', 'Open Positions') ?></th>
                        <th>Salary</th>
                        <th>Qualification</th>
                        <th><?= $jobSortLink('posted_on', 'Posted On') ?></th>
                        <th>Posted By</th>
                        <th>Expired Date</th>
                        <th>Status</th>
                        <th class="text-end">Corrected Open Position</th>
                        <th>Remarks Group</th>
                        <th>Remarks</th>
                        <th>Task Owner</th>
                        <th title="Sel = Selected · Sht = Shortlisted · OH = On Hold · Rej = Rejected">Applicants</th>
                        <?php foreach ($extraJobCols as $label): ?>
                            <th class="small text-muted"><?= esc($label) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                <?php if ($jobs === []): ?>
                    <tr><td colspan="<?= (int) $totalCols ?>"><div class="empty-state"><i class="bi bi-inbox"></i>No jobs on file for this employer.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($jobs as $job): ?>
                    <?php
                        $status = (string) ($job['status'] ?? '');
                        // Color-code job_status_data badge (shared between modes)
                        $jsd = trim((string) ($job['job_status_data'] ?? ''));
                        $jsdKey = strtolower(preg_replace('/[^a-z0-9]+/i', '', $jsd));
                        $jsdBg = '#e2e3e5'; $jsdFg = '#495057';
                        if ($jsdKey === '') { /* no badge */ }
                        elseif (in_array($jsdKey, ['active', 'open', 'valid', 'live'], true)) { $jsdBg = '#d1e7dd'; $jsdFg = '#0f5132'; }
                        elseif (in_array($jsdKey, ['expired', 'closed', 'inactive', 'invalid'], true)) { $jsdBg = '#f8d7da'; $jsdFg = '#842029'; }
                        elseif (in_array($jsdKey, ['draft', 'pending', 'unpublished', 'onhold', 'hold'], true)) { $jsdBg = '#fff3cd'; $jsdFg = '#664d03'; }
                        elseif (in_array($jsdKey, ['corrected', 'updated', 'modified'], true)) { $jsdBg = '#cfe2ff'; $jsdFg = '#084298'; }
                    ?>
                    <?php if ($isEdit): ?>
                        <tr data-job-row-id="<?= (int) $job['id'] ?>" data-job-id="<?= (int) $job['job_id'] ?>">
                            <td><input type="checkbox" class="js-bulk-row" value="<?= (int) $job['id'] ?>"></td>
                            <?php
                                // Serialize the job (+ derived labels) once so
                                // the shared details modal can render every
                                // field client-side without another round-trip.
                                $jobModalPayload = $job;
                                $jobModalPayload['posted_on']    = substr((string) ($job['posted_on'] ?? ''), 0, 10);
                                $jobModalPayload['expired_date'] = substr((string) ($job['expired_date'] ?? ''), 0, 10);
                                $jobModalJson = htmlspecialchars(
                                    json_encode($jobModalPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT),
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            ?>
                            <td>
                                <button type="button"
                                        class="btn btn-link btn-sm p-0 fw-semibold text-decoration-underline js-job-details-btn"
                                        data-bs-toggle="modal" data-bs-target="#jobDetailsModal"
                                        data-job="<?= $jobModalJson ?>"
                                        title="View all fields of this job">
                                    <?= (int) $job['job_id'] ?>
                                </button>
                            </td>
                            <td class="fw-semibold" style="min-width:180px;">
                                <div><?= esc((string) ($job['jobtitle'] ?? '')) ?></div>
                                <?php if ($jsd !== ''): ?>
                                    <div class="mt-1"><span class="small px-2 py-1 rounded" style="background: <?= $jsdBg ?>; color: <?= $jsdFg ?>; font-weight: 600;"><?= esc($jsd) ?></span></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= (int) ($job['open_positions'] ?? 0) ?></td>
                            <td class="small text-muted" style="min-width:140px;">
                                <div><?= esc((string) ($job['qualificationcategory'] ?? '')) ?></div>
                                <hr class="my-1">
                                <div><?= esc((string) ($job['salary_type'] ?? '')) ?><?= trim((string) ($job['salary_slab'] ?? '')) !== '' ? ' · ' . esc((string) ($job['salary_slab'] ?? '')) : '' ?></div>
                            </td>
                            <td style="min-width:150px;">
                                <input type="date" class="form-control form-control-sm js-autosave" data-field="posted_on" value="<?= esc(substr((string) ($job['posted_on'] ?? ''), 0, 10)) ?>">
                                <input type="date" class="form-control form-control-sm js-autosave mt-1" data-field="expired_date" value="<?= esc(substr((string) ($job['expired_date'] ?? ''), 0, 10)) ?>">
                            </td>
                            <td><input type="text" class="form-control form-control-sm js-autosave" data-field="posted_by" value="<?= esc((string) ($job['posted_by'] ?? '')) ?>" style="min-width:110px;"></td>
                            <td style="min-width:130px;">
                                <select class="form-select form-select-sm js-job-status js-autosave" data-field="status">
                                    <option value="">Select</option>
                                    <?php foreach (demand_employer_job_status_options() as $opt): ?>
                                        <option value="<?= esc($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" class="form-control form-control-sm text-end js-job-corrected js-autosave mt-1" data-field="corrected_open_position" value="<?= esc((string) ($job['corrected_open_position'] ?? '')) ?>" placeholder="Corrected">
                            </td>
                            <td style="min-width:180px;">
                                <select class="form-select form-select-sm js-job-remarks-group js-autosave" data-field="remarks_group_id">
                                    <option value="">Select</option>
                                    <?php foreach ($remarksGroups as $rg): ?>
                                        <option value="<?= (int) $rg['id'] ?>" <?= (int) ($job['remarks_group_id'] ?? 0) === (int) $rg['id'] ? 'selected' : '' ?>><?= esc((string) $rg['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <textarea class="form-control form-control-sm js-autosave mt-1" data-field="remarks" rows="2" placeholder="Remarks"><?= esc((string) ($job['remarks'] ?? '')) ?></textarea>
                            </td>
                            <td class="small text-muted">
                                <?= esc((string) ($job['task_owner_name'] ?? '')) ?>
                                <div class="small mt-1 js-row-save-status text-muted" style="min-height:1em;"></div>
                            </td>
                            <td><?= $renderApplicantCounts($job) ?></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td><?= (int) $job['job_id'] ?></td>
                            <td class="fw-semibold"><?= esc((string) ($job['jobtitle'] ?? '')) ?></td>
                            <td class="text-end"><?= (int) ($job['open_positions'] ?? 0) ?></td>
                            <td class="small text-muted"><?= esc((string) ($job['salary_type'] ?? '')) ?><br><?= esc((string) ($job['salary_slab'] ?? '')) ?></td>
                            <td class="small text-muted"><?= esc((string) ($job['qualificationcategory'] ?? '')) ?></td>
                            <td><?= esc(substr((string) ($job['posted_on'] ?? ''), 0, 10)) ?></td>
                            <td><?= esc((string) ($job['posted_by'] ?? '')) ?></td>
                            <td><?= esc(substr((string) ($job['expired_date'] ?? ''), 0, 10)) ?></td>
                            <td><?= render_status_chip($status) ?></td>
                            <td class="text-end"><?= esc((string) ($job['corrected_open_position'] ?? '')) ?></td>
                            <td><?= esc((string) ($job['remarks_group_name'] ?? '')) ?></td>
                            <td><?= nl2br(esc((string) ($job['remarks'] ?? ''))) ?></td>
                            <td class="small text-muted"><?= esc((string) ($job['task_owner_name'] ?? '')) ?></td>
                            <td><?= $renderApplicantCounts($job) ?></td>
                            <?php foreach (array_keys($extraJobCols) as $ecol): ?>
                                <?php $ev = (string) ($job[$ecol] ?? ''); ?>
                                <td class="small"><?= $ev === '' ? '<span class="text-muted">&mdash;</span>' : nl2br(esc($ev)) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-body pt-2 pb-3">
        <?php render_pagination($jobsPage, $jobsTotalPages, $jobsMatchingCount, $jobsPerPage, '/demand_side_employer_edit.php', $jobsPaginationBase, 'Employer Jobs pagination'); ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history text-primary me-1"></i>Employer Edit History</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr><th>When</th><th>Field</th><th>Old</th><th>New</th><th>Edited By</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($employerLogRows === []): ?>
                                <tr><td colspan="5" class="text-center text-muted">No employer edits yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($employerLogRows as $log): ?>
                                <tr>
                                    <td class="small text-muted"><?= esc((string) $log['edited_at']) ?></td>
                                    <td class="small"><?= esc((string) $log['field_name']) ?></td>
                                    <td class="small text-muted"><?= esc((string) ($log['old_value'] ?? '')) ?></td>
                                    <td class="small"><?= esc((string) ($log['new_value'] ?? '')) ?></td>
                                    <td class="small"><?= esc((string) ($log['editor_name'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-clock-history text-primary me-1"></i>Employer Jobs Edit History</span>
                <div class="d-flex align-items-center gap-2">
                    <span class="status-chip status-info"><?= number_format($histTotalCount) ?> entries</span>
                    <?php
                        // Per-page selector — GET form so we can carry all
                        // relevant scope params through without touching the
                        // Jobs table's own page state.
                        $histFormBase = array_filter([
                            'id' => $employerRowId,
                            'mode' => $mode,
                            'job_ids_search' => $jobIdSearch,
                            'job_title_search' => $jobTitleSearch,
                            'job_sort' => $jobSort,
                            'job_dir' => $jobDir,
                            'jobs_per_page' => $jobsPerPage,
                            'page' => $jobsPage,
                        ], static fn($v): bool => $v !== '' && $v !== null && $v !== 0);
                    ?>
                    <form method="get" class="d-inline">
                        <?php foreach ($histFormBase as $k => $v): ?>
                            <input type="hidden" name="<?= esc((string) $k) ?>" value="<?= esc((string) $v) ?>">
                        <?php endforeach; ?>
                        <input type="hidden" name="hist_page" value="1">
                        <select class="form-select form-select-sm" name="hist_per_page" onchange="this.form.submit()" style="width:auto;">
                            <?php foreach ($histPerPageAllowed as $pp): ?>
                                <option value="<?= (int) $pp ?>" <?= $histPerPage === $pp ? 'selected' : '' ?>><?= (int) $pp ?>/pg</option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr><th>When</th><th>Job ID</th><th>Field</th><th>Old</th><th>New</th><th>Edited By</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($jobLogRows === []): ?>
                                <tr><td colspan="6" class="text-center text-muted">No job edits yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($jobLogRows as $log): ?>
                                <tr>
                                    <td class="small text-muted"><?= esc((string) $log['edited_at']) ?></td>
                                    <td class="small"><?= (int) ($log['job_id'] ?? 0) ?></td>
                                    <td class="small"><?= esc((string) $log['field_name']) ?></td>
                                    <td class="small text-muted"><?= esc((string) ($log['old_value'] ?? '')) ?></td>
                                    <td class="small"><?= esc((string) ($log['new_value'] ?? '')) ?></td>
                                    <td class="small"><?= esc((string) ($log['editor_name'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($histTotalPages > 1): ?>
                <?php
                    $histLink = static function (int $p) use ($histFormBase, $histPerPage): string {
                        $params = $histFormBase;
                        $params['hist_per_page'] = $histPerPage;
                        $params['hist_page'] = $p;
                        return '/demand_side_employer_edit.php?' . http_build_query($params);
                    };
                    // Small window around the current page.
                    $windowStart = max(1, $histPage - 2);
                    $windowEnd = min($histTotalPages, $histPage + 2);
                ?>
                <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="small text-muted">
                        Showing <?= number_format(($histPage - 1) * $histPerPage + 1) ?>&ndash;<?= number_format(min($histPage * $histPerPage, $histTotalCount)) ?> of <?= number_format($histTotalCount) ?>
                    </div>
                    <nav aria-label="Employer Jobs Edit History pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $histPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $histPage <= 1 ? '#' : esc($histLink($histPage - 1)) ?>">Prev</a></li>
                            <?php if ($windowStart > 1): ?>
                                <li class="page-item"><a class="page-link" href="<?= esc($histLink(1)) ?>">1</a></li>
                                <?php if ($windowStart > 2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
                            <?php endif; ?>
                            <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                                <li class="page-item <?= $p === $histPage ? 'active' : '' ?>"><a class="page-link" href="<?= esc($histLink($p)) ?>"><?= $p ?></a></li>
                            <?php endfor; ?>
                            <?php if ($windowEnd < $histTotalPages): ?>
                                <?php if ($windowEnd < $histTotalPages - 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
                                <li class="page-item"><a class="page-link" href="<?= esc($histLink($histTotalPages)) ?>"><?= (int) $histTotalPages ?></a></li>
                            <?php endif; ?>
                            <li class="page-item <?= $histPage >= $histTotalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $histPage >= $histTotalPages ? '#' : esc($histLink($histPage + 1)) ?>">Next</a></li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
/* Employer-Jobs edit-row validation:
 *   Valid     -> disable corrected_open_position and remarks_group
 *   Invalid   -> disable corrected_open_position, enable remarks_group
 *   Corrected -> enable both
 *   (blank)   -> enable both
 * Disabled inputs are cleared and get a subtle muted look so the user sees
 * why the value they'd typed disappeared.
 */
(function () {
    function applyRule(sel) {
        const row = sel.closest('tr');
        if (!row) return;
        const corrected = row.querySelector('.js-job-corrected');
        const remarksGroup = row.querySelector('.js-job-remarks-group');
        const status = String(sel.value || '');
        const disableCorrected = (status === 'Valid' || status === 'Invalid');
        const disableRemarksGroup = (status === 'Valid');

        if (corrected) {
            corrected.disabled = disableCorrected;
            if (disableCorrected) { corrected.value = ''; }
            corrected.classList.toggle('bg-light', disableCorrected);
        }
        if (remarksGroup) {
            remarksGroup.disabled = disableRemarksGroup;
            if (disableRemarksGroup) { remarksGroup.value = ''; }
            remarksGroup.classList.toggle('bg-light', disableRemarksGroup);
        }
    }

    document.querySelectorAll('.js-job-status').forEach((sel) => {
        applyRule(sel);
        sel.addEventListener('change', () => applyRule(sel));
    });
})();

/* Auto-save every editable job field on change / blur via AJAX. Shows a
 * subtle Saving / Saved / error caption in the Task Owner cell. */
(function () {
    function autoSave(input) {
        const row = input.closest('tr');
        const rowId = row?.getAttribute('data-job-row-id');
        const field = input.getAttribute('data-field');
        const status = row?.querySelector('.js-row-save-status');
        if (!rowId || !field) return;
        if (input.disabled) return;
        const original = input.getAttribute('data-original');
        const current = input.value;
        if (original !== null && original === current) return;
        input.setAttribute('data-original', current);
        if (status) { status.textContent = 'Saving…'; status.className = 'small mt-1 js-row-save-status text-muted'; }
        const body = new URLSearchParams();
        body.append('action', 'save_job_field');
        body.append('job_row_id', rowId);
        body.append('field', field);
        body.append('value', current);
        fetch(window.location.pathname + '?id=<?= (int) $employerRowId ?>&mode=edit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
        .then((r) => r.json().catch(() => ({ ok: false, error: 'Bad response' })))
        .then((data) => {
            if (!status) return;
            if (data && data.ok) {
                status.textContent = 'Saved';
                status.className = 'small mt-1 js-row-save-status text-success';
                setTimeout(() => { if (status.textContent === 'Saved') status.textContent = ''; }, 2000);
            } else {
                status.textContent = (data && data.error) ? data.error : 'Save failed';
                status.className = 'small mt-1 js-row-save-status text-danger';
            }
        })
        .catch(() => {
            if (status) { status.textContent = 'Network error'; status.className = 'small mt-1 js-row-save-status text-danger'; }
        });
    }
    document.querySelectorAll('.js-autosave').forEach((el) => {
        el.setAttribute('data-original', el.value);
        const evt = (el.tagName === 'SELECT' || el.type === 'date' || el.type === 'checkbox') ? 'change' : 'blur';
        el.addEventListener(evt, () => autoSave(el));
        // When Status changes, the client rule may clear+disable other fields —
        // fire an autosave on those too so the server matches.
        if (el.classList.contains('js-job-status')) {
            el.addEventListener('change', () => {
                const row = el.closest('tr');
                ['.js-job-corrected', '.js-job-remarks-group'].forEach((sel) => {
                    const dep = row?.querySelector(sel);
                    if (dep) autoSave(dep);
                });
            });
        }
    });
})();

/* Bulk-update: header checkbox toggles all rows; every checkbox change
 * updates the enable state + count badge on the Bulk Update button. */
(function () {
    const selAll = document.getElementById('jobsSelectAll');
    const btn = document.getElementById('bulkUpdateBtn');
    const badge = document.getElementById('bulkUpdateCount');
    const rows = () => Array.from(document.querySelectorAll('.js-bulk-row'));
    function refresh() {
        const checked = rows().filter((cb) => cb.checked);
        if (badge) badge.textContent = String(checked.length);
        if (btn) btn.disabled = (checked.length === 0);
    }
    selAll?.addEventListener('change', () => {
        rows().forEach((cb) => { cb.checked = selAll.checked; });
        refresh();
    });
    rows().forEach((cb) => cb.addEventListener('change', refresh));
    refresh();

    // Attach via delegation on document — the bulk-update modal form is
    // defined LATER in the source than this script, so a direct
    // getElementById('bulkUpdateForm') here returns null and the submit
    // listener never fires (which produced the "no rows selected" flash
    // even when checkboxes were ticked, because the hidden job_ids[]
    // inputs weren't being injected). Delegation avoids the ordering
    // trap entirely.
    document.addEventListener('submit', (ev) => {
        const form = ev.target;
        if (!(form instanceof HTMLFormElement) || form.id !== 'bulkUpdateForm') return;
        // Purge any hidden job_ids[] left over from a previous submit
        // attempt so we don't accumulate stale ids across retries.
        form.querySelectorAll('input[type="hidden"][name="job_ids[]"]').forEach((n) => n.remove());
        const checked = rows().filter((cb) => cb.checked).map((cb) => cb.value);
        if (checked.length === 0) {
            ev.preventDefault();
            alert('Please select at least one job row before applying a bulk update.');
            return;
        }
        checked.forEach((v) => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'job_ids[]'; inp.value = v;
            form.appendChild(inp);
        });
    });
})();
</script>

<?php if ($isEdit): ?>
<!-- Bulk-update modal — appears when the user clicks the Bulk Update button
     with at least one row checked. Blank fields are skipped, so an update
     can touch only the columns the operator cares about. -->
<div class="modal fade" id="bulkUpdateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="bulkUpdateForm">
                <input type="hidden" name="action" value="bulk_update_jobs">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-list-check me-1"></i>Bulk update selected jobs</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Fields left blank are skipped, so you can update any subset. Same Valid/Invalid rules apply: <em>Valid</em> clears Corrected Open Position and Remarks Group on every selected row.</p>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="bulk_status">
                            <option value="">— leave unchanged —</option>
                            <?php foreach (demand_employer_job_status_options() as $opt): ?>
                                <option value="<?= esc($opt) ?>"><?= esc($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks Group</label>
                        <select class="form-select" name="bulk_remarks_group_id">
                            <option value="">— leave unchanged —</option>
                            <?php foreach ($remarksGroups as $rg): ?>
                                <option value="<?= (int) $rg['id'] ?>"><?= esc((string) $rg['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="bulk_remarks" rows="3" placeholder="Leave blank to keep existing remarks"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-check2-circle me-1"></i>Apply to selected rows</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Job details modal — one shared shell; body is populated client-side
     from the data-job JSON attribute on the clicked Job ID button. -->
<div class="modal fade" id="jobDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="jobDetailsTitle"><i class="bi bi-briefcase me-1"></i>Job details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="jobDetailsBody">
                <div class="text-muted">Loading…</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    // Field groups rendered as ordered sections inside the modal body.
    // Each section is a title + a 3-column grid of {label, key} pairs.
    // Order within a group is meaningful; adding a new column to
    // demand_employer_jobs later needs a matching entry here.
    const FIELD_GROUPS = [
        {title: 'Job basics', fields: [
            ['Job ID', 'job_id'], ['Job Title', 'jobtitle'], ['Employer ID', 'emp_id'],
            ['Employer Name', 'emp_name'], ['Job Category', 'job_category'], ['Job Sub Category', 'job_sub_category'],
        ]},
        {title: 'Vacancies', fields: [
            ['Open Positions', 'open_positions'], ['Corrected Open Position', 'corrected_open_position'],
            ['# Applications', 'number_of_applications'],
        ]},
        {title: 'Verification (in-app)', fields: [
            ['Status', 'status'], ['Remarks Group', 'remarks_group_name'], ['Remarks', 'remarks'],
        ]},
        {title: 'Applicant Funnel', fields: [
            ['Selected', 'selected'], ['Shortlisted', 'shortlisted'],
            ['On Hold', 'onhold'], ['Rejected', 'rejected'],
        ]},
        {title: 'Dates & Poster', fields: [
            ['Posted On', 'posted_on'], ['Posted By', 'posted_by'], ['Expired Date', 'expired_date'],
            ['Job Status data', 'job_status_data'],
        ]},
        {title: 'Location & Mode', fields: [
            ['Location', 'location'], ['Location Type', 'location_type'],
            ['Employment Mode', 'employment_mode'], ['Job Agency', 'job_agency'],
        ]},
        {title: 'Skills & Preferences', fields: [
            ['Domain Skills', 'domain_skills'], ['Soft Skills', 'soft_skills'], ['Specialization', 'specialization'],
            ['Courses', 'courses'], ['Academic Preference', 'academic_preference'], ['Qualification', 'qualificationcategory'],
            ['Min Experience', 'min_experience'], ['Max Experience', 'max_experience'], ['Age Preference', 'age_preference'],
            ['Gender Preference', 'gender_preference'], ['Job Sector', 'job_sector'],
        ]},
        {title: 'Salary', fields: [
            ['Salary Type', 'salary_type'], ['Salary Slab', 'salary_slab'],
        ]},
        {title: 'Job Fair Flags', fields: [
            ['Job Fair Only Job', 'jobfair_only_job'], ['Posted in Job Fair', 'posted_in_job_fair'],
            ['VK Flag', 'vk_flag'],
        ]},
        {title: 'Task Owner', fields: [
            ['Task Owner', 'task_owner_name'],
        ]},
    ];

    const escapeHtml = (s) => String(s).replace(/[<>&"']/g, (c) => ({
        '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;'
    }[c]));

    const isBlank = (v) => v === null || v === undefined || v === '' || (typeof v === 'string' && v.trim() === '');

    function renderCell(label, value) {
        const display = isBlank(value) ? '<span class="text-muted">&mdash;</span>' : escapeHtml(value).replace(/\n/g, '<br>');
        return `<div class="col-md-4">
            <div class="small text-muted">${escapeHtml(label)}</div>
            <div class="fw-semibold">${display}</div>
        </div>`;
    }

    function renderModalBody(d) {
        return FIELD_GROUPS.map((g) => {
            const cells = g.fields.map(([lbl, key]) => renderCell(lbl, d[key])).join('');
            return `<h6 class="mt-3 mb-2 text-primary text-uppercase small border-bottom pb-1">${escapeHtml(g.title)}</h6>
                <div class="row g-3">${cells}</div>`;
        }).join('');
    }

    const modalEl = document.getElementById('jobDetailsModal');
    if (!modalEl) return;
    modalEl.addEventListener('show.bs.modal', (ev) => {
        const trigger = ev.relatedTarget;
        if (!trigger) return;
        let payload = {};
        try {
            payload = JSON.parse(trigger.getAttribute('data-job') || '{}');
        } catch (e) {
            payload = {};
        }
        const title = document.getElementById('jobDetailsTitle');
        if (title) {
            const jt = payload.jobtitle ? ` · ${escapeHtml(payload.jobtitle)}` : '';
            title.innerHTML = `<i class="bi bi-briefcase me-1"></i>Job #${escapeHtml(payload.job_id || '')}${jt}`;
        }
        const body = document.getElementById('jobDetailsBody');
        if (body) body.innerHTML = renderModalBody(payload);
    });
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>

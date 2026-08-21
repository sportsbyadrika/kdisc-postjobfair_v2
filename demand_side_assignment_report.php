<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/demand_side_helpers.php';
require_admin();

$currentUser = current_user();
if (!is_manage_admin($currentUser)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock', 'subtitle' => 'Only Administrator or DSM Admin can view assignment reports.']);
    echo '<div class="alert alert-danger">Only Administrator and DSM Admin can view this report.</div>';
    echo '<a class="btn btn-primary" href="/demand_side_assignments.php"><i class="bi bi-arrow-left me-1"></i>Back to Assignments</a>';
    render_footer();
    exit;
}

demand_side_bootstrap();

/* Refined effective-jobs rule (matches the drill-down and Employer
   listing): specific-job assignments always narrow, even when the
   employer is also in the user's employer scope. The effective set is:
     - every specifically assigned job_id, PLUS
     - every job of an employer-scope employer where the user has NO
       overlapping specific-job assignment.
   This closure is reused by the summary loop below AND by the CSV
   export handler further up. */
$effectiveJobIdsFor = static function (array $empIds, array $jobIds): array {
    $result = $jobIds;
    if ($empIds === []) return array_values(array_unique($result));

    $empsWithOverlap = [];
    if ($jobIds !== []) {
        $ph = implode(',', array_fill(0, count($jobIds), '?'));
        $stmt = db()->prepare("SELECT DISTINCT emp_id FROM demand_employer_jobs WHERE job_id IN ($ph)");
        $stmt->execute($jobIds);
        $empsWithOverlap = array_map(static fn(array $r): int => (int) $r['emp_id'], $stmt->fetchAll());
    }
    $empsFullScope = array_values(array_diff($empIds, $empsWithOverlap));
    if ($empsFullScope !== []) {
        $ph = implode(',', array_fill(0, count($empsFullScope), '?'));
        $stmt = db()->prepare("SELECT job_id FROM demand_employer_jobs WHERE emp_id IN ($ph)");
        $stmt->execute($empsFullScope);
        foreach ($stmt->fetchAll() as $r) { $result[] = (int) $r['job_id']; }
    }
    return array_values(array_unique($result));
};

/* ------------------------------------------------------------------------- *
 * CSV export — user-wise assigned jobs with edit status, remarks and the
 * most recent editor. Streams the full set (no pagination). Called via
 * ?download=user_jobs on this page.
 * ------------------------------------------------------------------------- */
if (($_GET['download'] ?? '') === 'user_jobs') {
    @set_time_limit(0);
    ignore_user_abort(true);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="user_assigned_jobs_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Sl No', 'User', 'Role', 'Employer ID', 'Employer Name', 'Job ID', 'Job Title', 'Edited Status', 'Remarks', 'Last Edited User']);

    $usersForCsv = db()->query("SELECT u.id, u.name, u.role
        FROM users u
        WHERE u.id IN (
            SELECT DISTINCT user_id FROM demand_user_employer_assignments
            UNION SELECT DISTINCT user_id FROM demand_user_job_assignments
        )
        ORDER BY u.name ASC")->fetchAll();

    $slno = 1;
    foreach ($usersForCsv as $u) {
        $uid = (int) $u['id'];
        $empIds = demand_get_assigned_employer_ids($uid);
        $jobIds = demand_get_assigned_job_ids($uid);
        $effective = $effectiveJobIdsFor($empIds, $jobIds);
        if ($effective === []) continue;

        // Fetch job + employer info in one shot.
        $ph = implode(',', array_fill(0, count($effective), '?'));
        $sql = "SELECT e.employer_id, e.employer_name,
                    j.id AS job_row_id, j.job_id, j.jobtitle,
                    j.status, j.remarks
                FROM demand_employer_jobs j
                INNER JOIN demand_employers e ON e.employer_id = j.emp_id
                WHERE j.job_id IN ($ph)
                ORDER BY e.employer_id ASC, j.job_id ASC";
        $stmt = db()->prepare($sql);
        $stmt->execute($effective);
        $jobs = $stmt->fetchAll();
        if ($jobs === []) continue;

        // Last-edited user per job_row_id — one grouped query per batch.
        $jobRowIds = array_map(static fn(array $r): int => (int) $r['job_row_id'], $jobs);
        $lastByRow = [];
        if ($jobRowIds !== []) {
            $ph2 = implode(',', array_fill(0, count($jobRowIds), '?'));
            $leSql = "SELECT l.job_row_id, lu.name AS user_name
                FROM demand_employer_job_edit_log l
                INNER JOIN (
                    SELECT job_row_id, MAX(edited_at) AS max_at
                    FROM demand_employer_job_edit_log
                    WHERE job_row_id IN ($ph2)
                    GROUP BY job_row_id
                ) latest ON latest.job_row_id = l.job_row_id AND latest.max_at = l.edited_at
                LEFT JOIN users lu ON lu.id = l.edited_by";
            $leStmt = db()->prepare($leSql);
            $leStmt->execute($jobRowIds);
            foreach ($leStmt->fetchAll() as $le) {
                // If several fields were edited at the exact same second
                // the join can return more than one row — first name wins.
                $rid = (int) $le['job_row_id'];
                if (!isset($lastByRow[$rid])) {
                    $lastByRow[$rid] = (string) ($le['user_name'] ?? '');
                }
            }
        }

        foreach ($jobs as $j) {
            fputcsv($out, [
                $slno++,
                (string) $u['name'],
                role_label((string) $u['role']),
                (string) $j['employer_id'],
                (string) $j['employer_name'],
                (string) $j['job_id'],
                (string) ($j['jobtitle'] ?? ''),
                (string) ($j['status'] ?? ''),
                (string) ($j['remarks'] ?? ''),
                $lastByRow[(int) $j['job_row_id']] ?? '',
            ]);
        }
    }
    fclose($out);
    exit;
}

/* ------------------------------------------------------------------------- *
 * Table 1 — Per-user summary.
 * "Effective jobs" follows the visible-scope rule enforced on the Employer
 * view: if the user has ANY job assignments, those are the effective jobs;
 * otherwise all jobs of the user's assigned employers count. Completion %
 * = distinct effective jobs where the user has made ≥ 1 status edit in
 * demand_employer_job_edit_log, divided by effective_jobs.
 * ------------------------------------------------------------------------- */
$userSummary = [];

// Pull every user who has at least one row in either assignment table.
$userRows = db()->query("SELECT u.id, u.name, u.role
    FROM users u
    WHERE u.id IN (
        SELECT DISTINCT user_id FROM demand_user_employer_assignments
        UNION
        SELECT DISTINCT user_id FROM demand_user_job_assignments
    )
    ORDER BY u.name ASC")->fetchAll();

foreach ($userRows as $u) {
    $uid = (int) $u['id'];
    $empIds = demand_get_assigned_employer_ids($uid);
    $jobIds = demand_get_assigned_job_ids($uid);
    $effectiveJobCount = 0;
    $effectiveOpenings = 0;
    $effectiveJobIds = $effectiveJobIdsFor($empIds, $jobIds);
    if ($effectiveJobIds !== []) {
        $ph = implode(',', array_fill(0, count($effectiveJobIds), '?'));
        $stmt = db()->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(open_positions), 0) AS s FROM demand_employer_jobs WHERE job_id IN ($ph)");
        $stmt->execute($effectiveJobIds);
        $t = $stmt->fetch() ?: ['c' => 0, 's' => 0];
        $effectiveJobCount  = (int) $t['c'];
        $effectiveOpenings  = (int) $t['s'];
    }
    // Distinct jobs (from the effective set) where THIS user has made a
    // status edit ("user completion"), and the same count for edits by
    // ANY user ("actual completion" — what got done on this scope
    // regardless of who did the work). Both use an INNER JOIN so
    // pre-log-migration jobs never inflate the numerator.
    $statusEditsMade   = 0;   // edits made by THIS user
    $statusEditsAnyone = 0;   // distinct scoped jobs with ANY status edit
    if ($effectiveJobIds !== []) {
        $ph = implode(',', array_fill(0, count($effectiveJobIds), '?'));
        $sqlMine = "SELECT COUNT(DISTINCT j.job_id) AS c
            FROM demand_employer_job_edit_log l
            INNER JOIN demand_employer_jobs j ON j.id = l.job_row_id
            WHERE l.field_name = 'status'
              AND l.edited_by = ?
              AND j.job_id IN ($ph)";
        $stmt = db()->prepare($sqlMine);
        $stmt->execute([$uid, ...$effectiveJobIds]);
        $statusEditsMade = (int) ($stmt->fetch()['c'] ?? 0);

        $sqlAny = "SELECT COUNT(DISTINCT j.job_id) AS c
            FROM demand_employer_job_edit_log l
            INNER JOIN demand_employer_jobs j ON j.id = l.job_row_id
            WHERE l.field_name = 'status'
              AND j.job_id IN ($ph)";
        $stmt = db()->prepare($sqlAny);
        $stmt->execute($effectiveJobIds);
        $statusEditsAnyone = (int) ($stmt->fetch()['c'] ?? 0);
    }
    $completionPct       = $effectiveJobCount > 0 ? round(($statusEditsMade   / $effectiveJobCount) * 100, 1) : 0.0;
    $completionActualPct = $effectiveJobCount > 0 ? round(($statusEditsAnyone / $effectiveJobCount) * 100, 1) : 0.0;
    $userSummary[] = [
        'user_id'              => $uid,
        'user_name'            => (string) $u['name'],
        'user_role'            => (string) $u['role'],
        'employers_assigned'   => count($empIds),
        'jobs_assigned'        => count($jobIds),
        'effective_jobs'       => $effectiveJobCount,
        'effective_openings'   => $effectiveOpenings,
        'status_edits_made'    => $statusEditsMade,
        'status_edits_anyone'  => $statusEditsAnyone,
        'completion_pct'       => $completionPct,
        'completion_actual_pct' => $completionActualPct,
    ];
}

/* ------------------------------------------------------------------------- *
 * Table 2 — Job Agency pivot.
 * "Jobs assigned" = distinct jobs reached by at least one user, either
 * because the user has the employer in scope OR the specific job in scope.
 * "Effective vacancies" = SUM(open_positions) where status = 'Valid', plus
 * SUM(COALESCE(corrected_open_position, open_positions)) where status =
 * 'Corrected'. Invalid contributes zero. Not-Yet-Started rows also
 * contribute zero because no verdict has been rendered.
 * ------------------------------------------------------------------------- */
$agencySql = "SELECT
        COALESCE(NULLIF(TRIM(e.jobagency), ''), '(Unknown)') AS agency,
        COUNT(DISTINCT e.employer_id) AS total_employers,
        COUNT(DISTINCT j.job_id) AS total_jobs,
        COALESCE(SUM(j.open_positions), 0) AS total_vacancies,
        COUNT(DISTINCT CASE WHEN ea.employer_id IS NOT NULL OR ja.job_id IS NOT NULL THEN e.employer_id END) AS employers_assigned,
        COUNT(DISTINCT CASE WHEN ea.employer_id IS NOT NULL OR ja.job_id IS NOT NULL THEN j.job_id END) AS jobs_assigned,
        COUNT(DISTINCT CASE WHEN edj.job_id IS NOT NULL THEN j.job_id END) AS edit_completed_jobs,
        COALESCE(SUM(CASE
            WHEN j.status = 'Valid' THEN j.open_positions
            WHEN j.status = 'Corrected' THEN COALESCE(j.corrected_open_position, j.open_positions)
            ELSE 0
        END), 0) AS effective_vacancies
    FROM demand_employers e
    INNER JOIN demand_employer_jobs j ON j.emp_id = e.employer_id
    LEFT JOIN (SELECT DISTINCT employer_id FROM demand_user_employer_assignments) ea ON ea.employer_id = e.employer_id
    LEFT JOIN (SELECT DISTINCT job_id FROM demand_user_job_assignments) ja ON ja.job_id = j.job_id
    LEFT JOIN (
        SELECT DISTINCT jr.job_id
        FROM demand_employer_job_edit_log l
        INNER JOIN demand_employer_jobs jr ON jr.id = l.job_row_id
        WHERE l.field_name = 'status'
    ) edj ON edj.job_id = j.job_id
    GROUP BY agency
    ORDER BY agency ASC";
$agencyRows = db()->query($agencySql)->fetchAll();

// Totals row
$agencyTotals = ['total_employers' => 0, 'total_jobs' => 0, 'total_vacancies' => 0,
    'employers_assigned' => 0, 'jobs_assigned' => 0, 'edit_completed_jobs' => 0, 'effective_vacancies' => 0];
foreach ($agencyRows as $ar) {
    foreach (array_keys($agencyTotals) as $k) { $agencyTotals[$k] += (int) ($ar[$k] ?? 0); }
}

/* ------------------------------------------------------------------------- *
 * Table 3 — Active users who have NO rows in either assignment table yet.
 * Sorted by role then name so admins can spot gaps at a glance. Optional
 * role filter (?unassigned_role=...) narrows it further.
 * ------------------------------------------------------------------------- */
$unassignedRoleFilter = trim((string) ($_GET['unassigned_role'] ?? ''));
$unassignedParams = [];
$unassignedSql = "SELECT id, name, role
    FROM users
    WHERE active_status = 1
      AND id NOT IN (SELECT DISTINCT user_id FROM demand_user_employer_assignments)
      AND id NOT IN (SELECT DISTINCT user_id FROM demand_user_job_assignments)";
if ($unassignedRoleFilter !== '') {
    $unassignedSql .= ' AND role = ?';
    $unassignedParams[] = $unassignedRoleFilter;
}
$unassignedSql .= ' ORDER BY role ASC, name ASC';
$unassignedStmt = db()->prepare($unassignedSql);
$unassignedStmt->execute($unassignedParams);
$unassignedRows = $unassignedStmt->fetchAll();

// Role dropdown options for the unassigned filter — pulled from the same
// active-users pool so we only offer roles that actually exist.
$unassignedRoleOptions = array_map(
    static fn(array $r): string => (string) $r['role'],
    db()->query("SELECT DISTINCT role FROM users WHERE active_status = 1 AND role IS NOT NULL AND role <> '' ORDER BY role ASC")->fetchAll()
);

render_header('Assignment Report', ['main_container_class' => 'container-fluid']);
render_page_header('Demand Side · Assignment Report', [
    'icon' => 'bi-file-earmark-bar-graph',
    'subtitle' => 'Per-user progress plus a Job Agency pivot combining pool size, assignment coverage and edit completion.',
    'actions' => '<a class="btn btn-light me-1" href="/demand_side_employer_users_report.php"><i class="bi bi-building-check me-1"></i>Employer-wise Users</a>'
        . '<a class="btn btn-light" href="/demand_side_assignments.php"><i class="bi bi-arrow-left me-1"></i>Back to Assignments</a>',
]);
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-people text-primary me-1"></i>Per-user Assignment Summary</span>
        <div class="d-flex align-items-center gap-2">
            <a class="btn btn-sm btn-outline-primary" href="/demand_side_assignment_report.php?download=user_jobs"
               title="Download all users' effective assigned jobs with edit status, remarks and last editor">
                <i class="bi bi-download me-1"></i>Download user-wise jobs CSV
            </a>
            <span class="status-chip status-info"><?= number_format(count($userSummary)) ?> users with an assignment</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>User</th>
                    <th>Role</th>
                    <th class="text-end">Employers Assigned</th>
                    <th class="text-end">Jobs Assigned</th>
                    <th class="text-end">Effective Job Openings</th>
                    <th class="text-end" title="Distinct scoped jobs where THIS user has made a status edit / total effective jobs">Status Edits Made</th>
                    <th class="text-end" title="What THIS user personally completed on their scope">User Completion %</th>
                    <th class="text-end" title="What actually got completed on this user's scope by ANYONE (including edits by other users on the same jobs)">Actual Completion %</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($userSummary === []): ?>
                    <tr><td colspan="9"><div class="empty-state"><i class="bi bi-inbox"></i>No user assignments yet.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($userSummary as $s): ?>
                    <?php
                        $pct       = (float) $s['completion_pct'];
                        $pctActual = (float) $s['completion_actual_pct'];
                        $barTone       = $pct       >= 90 ? 'success' : ($pct       >= 50 ? 'warning' : 'danger');
                        $barToneActual = $pctActual >= 90 ? 'success' : ($pctActual >= 50 ? 'warning' : 'danger');
                    ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td class="fw-semibold"><?= esc((string) $s['user_name']) ?></td>
                        <td><span class="status-chip status-neutral"><?= esc(role_label((string) $s['user_role'])) ?></span></td>
                        <td class="text-end fw-bold"><?= number_format((int) $s['employers_assigned']) ?></td>
                        <td class="text-end fw-bold"><?= number_format((int) $s['jobs_assigned']) ?></td>
                        <td class="text-end fw-bold"><?= number_format((int) $s['effective_openings']) ?></td>
                        <td class="text-end"><?= number_format((int) $s['status_edits_made']) ?> / <?= number_format((int) $s['effective_jobs']) ?></td>
                        <td class="text-end" style="min-width:140px;">
                            <div class="d-flex align-items-center justify-content-end gap-2">
                                <div class="progress flex-grow-1" style="height:8px; max-width:100px;">
                                    <div class="progress-bar bg-<?= esc($barTone) ?>" role="progressbar" style="width: <?= (float) $pct ?>%;" aria-valuenow="<?= (float) $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <span class="fw-bold"><?= number_format($pct, 1) ?>%</span>
                            </div>
                        </td>
                        <td class="text-end" style="min-width:160px;">
                            <div class="d-flex align-items-center justify-content-end gap-2"
                                 title="<?= number_format((int) $s['status_edits_anyone']) ?> of <?= number_format((int) $s['effective_jobs']) ?> scoped job(s) have a status edit by anyone">
                                <div class="progress flex-grow-1" style="height:8px; max-width:100px;">
                                    <div class="progress-bar bg-<?= esc($barToneActual) ?>" role="progressbar" style="width: <?= (float) $pctActual ?>%;" aria-valuenow="<?= (float) $pctActual ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <span class="fw-bold"><?= number_format($pctActual, 1) ?>%</span>
                            </div>
                            <?php if ((int) $s['status_edits_anyone'] > (int) $s['status_edits_made']): ?>
                                <div class="small text-muted mt-1">
                                    <i class="bi bi-people me-1"></i>+<?= number_format((int) $s['status_edits_anyone'] - (int) $s['status_edits_made']) ?> by others
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($userSummary !== []): ?>
                    <?php
                        // Totals row — plain sums for the count columns; the
                        // two completion percentages are weighted averages
                        // (aggregate edits / aggregate effective jobs) so
                        // they still read as a true "overall completion".
                        $totEmp   = 0; $totJobs = 0; $totOpen = 0;
                        $totEff   = 0; $totEditsMine = 0; $totEditsAny = 0;
                        foreach ($userSummary as $s) {
                            $totEmp        += (int) $s['employers_assigned'];
                            $totJobs       += (int) $s['jobs_assigned'];
                            $totOpen       += (int) $s['effective_openings'];
                            $totEff        += (int) $s['effective_jobs'];
                            $totEditsMine  += (int) $s['status_edits_made'];
                            $totEditsAny   += (int) $s['status_edits_anyone'];
                        }
                        $totPctMine   = $totEff > 0 ? round(($totEditsMine / $totEff) * 100, 1) : 0.0;
                        $totPctAny    = $totEff > 0 ? round(($totEditsAny  / $totEff) * 100, 1) : 0.0;
                        $totToneMine  = $totPctMine >= 90 ? 'success' : ($totPctMine >= 50 ? 'warning' : 'danger');
                        $totToneAny   = $totPctAny  >= 90 ? 'success' : ($totPctAny  >= 50 ? 'warning' : 'danger');
                    ?>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="3">Total <span class="text-muted small ms-1">(<?= number_format(count($userSummary)) ?> user<?= count($userSummary) === 1 ? '' : 's' ?>)</span></td>
                        <td class="text-end"><?= number_format($totEmp) ?></td>
                        <td class="text-end"><?= number_format($totJobs) ?></td>
                        <td class="text-end"><?= number_format($totOpen) ?></td>
                        <td class="text-end"><?= number_format($totEditsMine) ?> / <?= number_format($totEff) ?></td>
                        <td class="text-end" style="min-width:140px;">
                            <div class="d-flex align-items-center justify-content-end gap-2" title="Weighted across all users: <?= number_format($totEditsMine) ?> of <?= number_format($totEff) ?> effective job(s)">
                                <div class="progress flex-grow-1" style="height:8px; max-width:100px;">
                                    <div class="progress-bar bg-<?= esc($totToneMine) ?>" role="progressbar" style="width: <?= (float) $totPctMine ?>%;" aria-valuenow="<?= (float) $totPctMine ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <span class="fw-bold"><?= number_format($totPctMine, 1) ?>%</span>
                            </div>
                        </td>
                        <td class="text-end" style="min-width:160px;">
                            <div class="d-flex align-items-center justify-content-end gap-2" title="Weighted across all users: <?= number_format($totEditsAny) ?> of <?= number_format($totEff) ?> effective job(s)">
                                <div class="progress flex-grow-1" style="height:8px; max-width:100px;">
                                    <div class="progress-bar bg-<?= esc($totToneAny) ?>" role="progressbar" style="width: <?= (float) $totPctAny ?>%;" aria-valuenow="<?= (float) $totPctAny ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <span class="fw-bold"><?= number_format($totPctAny, 1) ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        <strong>User Completion %</strong> = status edits made <em>by this user</em> on their scoped jobs &divide; effective jobs.
        <strong>Actual Completion %</strong> = distinct scoped jobs with <em>any</em> status edit (regardless of who made it) &divide; effective jobs — so if another user closed some of this user's jobs, both figures diverge and the gap surfaces here.
        The <strong>Total</strong> row's percentages are weighted across every user: aggregate edits &divide; aggregate effective jobs, not a simple average of each row.
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-diagram-3 text-primary me-1"></i>Job Agency wise Coverage</span>
        <span class="status-chip status-info"><?= number_format(count($agencyRows)) ?> agencies</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Job Agency</th>
                    <th class="text-end">Total Employers</th>
                    <th class="text-end">Total Jobs</th>
                    <th class="text-end">Total Vacancies</th>
                    <th class="text-end">Employers Assigned</th>
                    <th class="text-end">Jobs Assigned</th>
                    <th class="text-end">Edit Completed Jobs</th>
                    <th class="text-end">Effective Vacancies</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($agencyRows === []): ?>
                    <tr><td colspan="9"><div class="empty-state"><i class="bi bi-inbox"></i>No employer/job data available.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($agencyRows as $ar): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td class="fw-semibold"><?= esc((string) $ar['agency']) ?></td>
                        <td class="text-end fw-bold"><?= number_format((int) $ar['total_employers']) ?></td>
                        <td class="text-end fw-bold"><?= number_format((int) $ar['total_jobs']) ?></td>
                        <td class="text-end fw-bold"><?= number_format((int) $ar['total_vacancies']) ?></td>
                        <td class="text-end"><?= number_format((int) $ar['employers_assigned']) ?></td>
                        <td class="text-end"><?= number_format((int) $ar['jobs_assigned']) ?></td>
                        <td class="text-end"><?= number_format((int) $ar['edit_completed_jobs']) ?></td>
                        <td class="text-end text-success fw-bold"><?= number_format((int) $ar['effective_vacancies']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($agencyRows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="2">Total</td>
                        <td class="text-end"><?= number_format((int) $agencyTotals['total_employers']) ?></td>
                        <td class="text-end"><?= number_format((int) $agencyTotals['total_jobs']) ?></td>
                        <td class="text-end"><?= number_format((int) $agencyTotals['total_vacancies']) ?></td>
                        <td class="text-end"><?= number_format((int) $agencyTotals['employers_assigned']) ?></td>
                        <td class="text-end"><?= number_format((int) $agencyTotals['jobs_assigned']) ?></td>
                        <td class="text-end"><?= number_format((int) $agencyTotals['edit_completed_jobs']) ?></td>
                        <td class="text-end text-success"><?= number_format((int) $agencyTotals['effective_vacancies']) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        <strong>Definitions:</strong>
        <em>Jobs Assigned</em> = distinct jobs reached by at least one user (either via that user's employer scope OR their job scope).
        <em>Edit Completed Jobs</em> = distinct jobs with at least one <code>status</code> field change in the edit log.
        <em>Effective Vacancies</em> = <code>open_positions</code> where status = <strong>Valid</strong>, plus <code>COALESCE(corrected_open_position, open_positions)</code> where status = <strong>Corrected</strong>. Invalid and Not-Yet-Started rows contribute zero.
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-person-slash text-primary me-1"></i>Users with no assignment yet</span>
        <form method="get" class="d-inline-flex align-items-center gap-2">
            <label class="form-label small mb-0" for="unassigned_role">Filter by role</label>
            <select class="form-select form-select-sm" name="unassigned_role" id="unassigned_role" onchange="this.form.submit()" style="width:auto;">
                <option value="">All roles</option>
                <?php foreach ($unassignedRoleOptions as $r): ?>
                    <option value="<?= esc($r) ?>" <?= $unassignedRoleFilter === $r ? 'selected' : '' ?>><?= esc(role_label($r)) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="status-chip status-info"><?= number_format(count($unassignedRows)) ?> users</span>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>User</th>
                    <th>Role</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($unassignedRows === []): ?>
                    <tr><td colspan="4"><div class="empty-state"><i class="bi bi-check-circle-fill text-success"></i>Every active user has at least one assignment. 🎉</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($unassignedRows as $u): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td class="fw-semibold"><?= esc((string) $u['name']) ?></td>
                        <td><span class="status-chip status-neutral"><?= esc(role_label((string) $u['role'])) ?></span></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="/demand_side_assignments.php?user_id=<?= (int) $u['id'] ?>"><i class="bi bi-plus-lg me-1"></i>Assign now</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        These active users have zero rows in both <code>demand_user_employer_assignments</code> and <code>demand_user_job_assignments</code>. Use the Assign now button to open the Assign Employers form pre-scoped to the user.
    </div>
</div>

<?php render_footer(); ?>

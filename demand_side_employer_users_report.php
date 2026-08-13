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

$agencyFilter    = trim((string) ($_GET['jobagency']    ?? ''));
$employerFilter  = trim((string) ($_GET['employer_id']  ?? ''));
$showAll         = (($_GET['show_all'] ?? '') === '1');
$page            = max(1, (int) ($_GET['page'] ?? 1));
$perPageAllowed  = [25, 50, 100, 200];
$perPage         = (int) ($_GET['per_page'] ?? 25);
if (!in_array($perPage, $perPageAllowed, true)) { $perPage = 25; }

// Users multi-select filter — accepts users[]=1&users[]=2 style params.
// Only numeric ids are kept; unknown ids fall through to an empty match.
$rawUsers = $_GET['users'] ?? [];
if (!is_array($rawUsers)) { $rawUsers = [$rawUsers]; }
$userFilter = [];
foreach ($rawUsers as $rv) {
    $rv = trim((string) $rv);
    if ($rv !== '' && ctype_digit($rv)) { $userFilter[] = (int) $rv; }
}
$userFilter = array_values(array_unique($userFilter));

// Distinct agencies for the dropdown.
$agencyOptions = array_map(
    static fn(array $r): string => (string) $r['value'],
    db()->query("SELECT DISTINCT jobagency AS value FROM demand_employers WHERE jobagency IS NOT NULL AND TRIM(jobagency) <> '' ORDER BY value ASC")->fetchAll()
);

// User list for the multi-select — only users who have at least one
// assignment (any type) show up, so the filter can't produce empty results
// by accident. Sorted by role then name for scannability.
$userOptions = db()->query("SELECT u.id, u.name, u.role
    FROM users u
    WHERE u.id IN (
        SELECT DISTINCT user_id FROM demand_user_employer_assignments
        UNION
        SELECT DISTINCT user_id FROM demand_user_job_assignments
    )
    ORDER BY u.role ASC, u.name ASC")->fetchAll();

// Common WHERE for the employer set we're reporting on.
$conds = ['1=1'];
$params = [];
if ($agencyFilter !== '') {
    $conds[] = 'e.jobagency = ?';
    $params[] = $agencyFilter;
}
if ($employerFilter !== '' && ctype_digit($employerFilter)) {
    $conds[] = 'e.employer_id = ?';
    $params[] = (int) $employerFilter;
}
if ($userFilter !== []) {
    // Restrict to employers assigned to ANY of the selected users, either
    // via direct employer-scope or via job-scope on any job of the
    // employer.
    $ph = implode(',', array_fill(0, count($userFilter), '?'));
    $conds[] = "(e.employer_id IN (
                    SELECT DISTINCT employer_id FROM demand_user_employer_assignments
                    WHERE user_id IN ($ph))
                 OR e.employer_id IN (
                    SELECT DISTINCT j.emp_id
                    FROM demand_user_job_assignments ja
                    INNER JOIN demand_employer_jobs j ON j.job_id = ja.job_id
                    WHERE ja.user_id IN ($ph)))";
    foreach ($userFilter as $uid) { $params[] = $uid; }
    foreach ($userFilter as $uid) { $params[] = $uid; }
} elseif (!$showAll) {
    // No user filter and "show all" is off — restrict to employers that
    // have at least one user assignment (direct employer scope OR job-scope
    // on any of the employer's jobs).
    $conds[] = "(e.employer_id IN (SELECT DISTINCT employer_id FROM demand_user_employer_assignments)
                 OR e.employer_id IN (SELECT DISTINCT j.emp_id
                     FROM demand_user_job_assignments ja
                     INNER JOIN demand_employer_jobs j ON j.job_id = ja.job_id))";
}
$whereSql = 'WHERE ' . implode(' AND ', $conds);

$fromSql = "FROM demand_employers e
    LEFT JOIN (
        SELECT emp_id, COUNT(*) AS jobs_count, COALESCE(SUM(open_positions), 0) AS total_openings
        FROM demand_employer_jobs
        GROUP BY emp_id
    ) j ON j.emp_id = e.employer_id
    $whereSql";

$countStmt = db()->prepare("SELECT COUNT(*) $fromSql");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages   = max(1, (int) ceil($totalRecords / $perPage));
$page         = min($page, $totalPages);
$offset       = ($page - 1) * $perPage;

$listStmt = db()->prepare("SELECT e.employer_id, e.employer_name, e.jobagency,
        COALESCE(j.jobs_count, 0) AS jobs_count,
        COALESCE(j.total_openings, 0) AS total_openings
    $fromSql
    ORDER BY e.employer_name ASC, e.employer_id ASC
    LIMIT ? OFFSET ?");
$listStmt->execute([...$params, $perPage, $offset]);
$rows = $listStmt->fetchAll();

// Batch-fetch the assignment breakdown for only the employers on this page,
// so the query cost scales with page size rather than total employers.
$empIds = array_map(static fn(array $r): int => (int) $r['employer_id'], $rows);
$empAssignments = []; // employer_id => [ [user_id, user_name, role, scope, jobs_count] ]
if ($empIds !== []) {
    $ph = implode(',', array_fill(0, count($empIds), '?'));

    // Direct employer-scope assignments.
    $eaSql = "SELECT ea.employer_id, u.id AS user_id, u.name AS user_name, u.role AS user_role
        FROM demand_user_employer_assignments ea
        INNER JOIN users u ON u.id = ea.user_id
        WHERE ea.employer_id IN ($ph)
        ORDER BY u.name ASC";
    $eaStmt = db()->prepare($eaSql);
    $eaStmt->execute($empIds);
    foreach ($eaStmt->fetchAll() as $r) {
        $eid = (int) $r['employer_id'];
        $empAssignments[$eid][] = [
            'user_id'    => (int) $r['user_id'],
            'user_name'  => (string) $r['user_name'],
            'user_role'  => (string) $r['user_role'],
            'scope'      => 'employer',
            'jobs_count' => 0,
        ];
    }

    // Job-scope assignments — grouped per (employer, user) so we can show
    // "X of Y jobs assigned" instead of listing every job.
    $jaSql = "SELECT j.emp_id AS employer_id, u.id AS user_id, u.name AS user_name, u.role AS user_role,
                COUNT(*) AS jobs_count
        FROM demand_user_job_assignments ja
        INNER JOIN demand_employer_jobs j ON j.job_id = ja.job_id
        INNER JOIN users u ON u.id = ja.user_id
        WHERE j.emp_id IN ($ph)
        GROUP BY j.emp_id, u.id, u.name, u.role
        ORDER BY u.name ASC";
    $jaStmt = db()->prepare($jaSql);
    $jaStmt->execute($empIds);
    foreach ($jaStmt->fetchAll() as $r) {
        $eid = (int) $r['employer_id'];
        $uid = (int) $r['user_id'];
        // If the user already has a direct employer-scope entry, mark the
        // job-scope count on that entry instead of duplicating the row —
        // employer scope trumps because "all jobs" already implies these.
        $mergedIntoEmployerScope = false;
        if (isset($empAssignments[$eid])) {
            foreach ($empAssignments[$eid] as &$existing) {
                if ($existing['user_id'] === $uid && $existing['scope'] === 'employer') {
                    // Attach the job count as informational — user has both
                    // types on this employer.
                    $existing['jobs_count'] = (int) $r['jobs_count'];
                    $mergedIntoEmployerScope = true;
                    break;
                }
            }
            unset($existing);
        }
        if (!$mergedIntoEmployerScope) {
            $empAssignments[$eid][] = [
                'user_id'    => $uid,
                'user_name'  => (string) $r['user_name'],
                'user_role'  => (string) $r['user_role'],
                'scope'      => 'jobs',
                'jobs_count' => (int) $r['jobs_count'],
            ];
        }
    }
}

$baseParams = $_GET;
unset($baseParams['page']);

render_header('Employer-wise Assigned Users', ['main_container_class' => 'container-fluid']);
render_page_header('Demand Side · Employer-wise Assigned Users', [
    'icon' => 'bi-diagram-3',
    'subtitle' => 'Per-employer view of who owns which employer. Includes both direct employer-scope assignments and users reached via job-scope on any job of the employer.',
    'actions' => '<a class="btn btn-light me-1" href="/demand_side_assignment_report.php"><i class="bi bi-file-earmark-bar-graph me-1"></i>User Report</a>'
        . '<a class="btn btn-light" href="/demand_side_assignments.php"><i class="bi bi-arrow-left me-1"></i>Back to Assignments</a>',
]);
?>

<form method="get" class="card mb-3">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Job Agency</label>
                <select class="form-select" name="jobagency">
                    <option value="">All agencies</option>
                    <?php foreach ($agencyOptions as $opt): ?>
                        <option value="<?= esc($opt) ?>" <?= $agencyFilter === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Employer ID</label>
                <input type="text" class="form-control" name="employer_id" value="<?= esc($employerFilter) ?>" placeholder="numeric id">
            </div>
            <div class="col-md-4">
                <label class="form-label d-flex justify-content-between align-items-center">
                    <span>Users <span class="small text-muted">(hold Ctrl / Cmd to pick multiple)</span></span>
                    <?php if ($userFilter !== []): ?>
                        <span class="badge text-bg-primary"><?= count($userFilter) ?> selected</span>
                    <?php endif; ?>
                </label>
                <input type="text" class="form-control form-control-sm mb-1" id="userFilterSearch" placeholder="Type to filter the list below…">
                <select class="form-select" name="users[]" id="userFilterSelect" multiple size="6">
                    <?php foreach ($userOptions as $u): ?>
                        <?php $selected = in_array((int) $u['id'], $userFilter, true); ?>
                        <option value="<?= (int) $u['id'] ?>"
                                data-role="<?= esc((string) ($u['role'] ?? '')) ?>"
                                <?= $selected ? 'selected' : '' ?>>
                            <?= esc((string) $u['name']) ?> · <?= esc(role_label((string) ($u['role'] ?? ''))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="small text-muted mt-1">Employers assigned to any selected user (via employer- or job-scope) will be listed.</div>
            </div>
            <div class="col-md-2">
                <label class="form-label">Rows / page</label>
                <select class="form-select" name="per_page">
                    <?php foreach ($perPageAllowed as $pp): ?>
                        <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="show_all" name="show_all" value="1" <?= $showAll ? 'checked' : '' ?> <?= $userFilter !== [] ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="show_all">
                        Show all employers (include those with no assignment)
                    </label>
                    <?php if ($userFilter !== []): ?>
                        <div class="small text-muted">Ignored while a Users filter is active.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
                <a class="btn btn-light" href="/demand_side_employer_users_report.php">Reset</a>
            </div>
        </div>
    </div>
    <script>
    (function () {
        const search = document.getElementById('userFilterSearch');
        const sel    = document.getElementById('userFilterSelect');
        if (!search || !sel) return;
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            Array.from(sel.options).forEach((opt) => {
                opt.hidden = q !== '' && !opt.text.toLowerCase().includes(q);
            });
        });
    })();
    </script>
</form>

<?php render_pagination($page, $totalPages, $totalRecords, $perPage, '/demand_side_employer_users_report.php', $baseParams, 'Employer report pagination'); ?>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-building text-primary me-1"></i>Employer-wise Assigned Users</span>
        <div class="d-flex gap-2 flex-wrap">
            <span class="status-chip status-info"><?= number_format($totalRecords) ?> employers</span>
            <?php if ($agencyFilter !== ''): ?>
                <span class="status-chip status-neutral">Agency: <?= esc($agencyFilter) ?></span>
            <?php endif; ?>
            <?php if ($userFilter !== []): ?>
                <?php
                    $userNameById = [];
                    foreach ($userOptions as $uo) { $userNameById[(int) $uo['id']] = (string) $uo['name']; }
                    $userChips = [];
                    foreach ($userFilter as $uid) {
                        $userChips[] = $userNameById[$uid] ?? ('#' . $uid);
                    }
                ?>
                <span class="status-chip status-warning" title="<?= esc(implode(', ', $userChips)) ?>">
                    Users: <?= esc(implode(', ', array_slice($userChips, 0, 3))) ?><?= count($userChips) > 3 ? ' +' . (count($userChips) - 3) . ' more' : '' ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Employer ID</th>
                    <th>Employer</th>
                    <th>Job Agency</th>
                    <th class="text-end">Jobs</th>
                    <th class="text-end">Open Positions</th>
                    <th style="min-width:320px;">Users Assigned</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No employers match the filters.</div></td></tr>
                <?php endif; ?>
                <?php $idx = $offset + 1; foreach ($rows as $row): ?>
                    <?php
                        $eid = (int) $row['employer_id'];
                        $users = $empAssignments[$eid] ?? [];
                        $jobsCount = (int) $row['jobs_count'];
                    ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td><?= esc((string) $row['employer_id']) ?></td>
                        <td class="fw-semibold">
                            <a href="/demand_side_employer_edit.php?id=<?= $eid ?>&mode=view" class="text-decoration-none">
                                <?= esc((string) $row['employer_name']) ?>
                            </a>
                        </td>
                        <td><?= esc((string) ($row['jobagency'] ?? '')) ?></td>
                        <td class="text-end"><?= number_format($jobsCount) ?></td>
                        <td class="text-end"><?= number_format((int) $row['total_openings']) ?></td>
                        <td>
                            <?php if ($users === []): ?>
                                <span class="text-muted small"><i class="bi bi-dash-circle me-1"></i>No user assigned</span>
                            <?php else: ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($users as $u): ?>
                                        <?php
                                            $tone = $u['scope'] === 'employer' ? 'primary' : 'warning';
                                            $badge = $u['scope'] === 'employer'
                                                ? ($u['jobs_count'] > 0
                                                    ? sprintf('Employer + %d job(s)', (int) $u['jobs_count'])
                                                    : 'Employer scope')
                                                : sprintf('%d of %d job(s)', (int) $u['jobs_count'], $jobsCount);
                                            $tip = $u['scope'] === 'employer'
                                                ? 'Sees every job of this employer'
                                                : 'Sees only the specific job_ids assigned';
                                            $isSelected = ($userFilter !== [] && in_array((int) $u['user_id'], $userFilter, true));
                                            $chipCls    = 'assignee-chip text-decoration-none border rounded px-2 py-1 small d-inline-flex align-items-center gap-1';
                                            $chipCls   .= $isSelected ? ' bg-warning-subtle border-warning' : ' bg-body-tertiary';
                                        ?>
                                        <a class="<?= esc($chipCls) ?>"
                                           href="/demand_side_assignments.php?user_id=<?= (int) $u['user_id'] ?>"
                                           title="<?= esc($tip) ?>">
                                            <i class="bi <?= $isSelected ? 'bi-check-circle-fill text-warning' : 'bi-person-circle' ?>"></i>
                                            <span class="fw-semibold"><?= esc((string) $u['user_name']) ?></span>
                                            <span class="text-muted">·</span>
                                            <span class="small text-muted"><?= esc(role_label((string) $u['user_role'])) ?></span>
                                            <span class="badge text-bg-<?= esc($tone) ?> ms-1"><?= esc($badge) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        <strong>Users Assigned:</strong>
        <span class="badge text-bg-primary">Employer scope</span> — user was assigned this employer directly and sees every job on it.
        <span class="badge text-bg-warning ms-2">N of M job(s)</span> — user was assigned specific job_ids on this employer and sees only those jobs. A user with both types shows the combined badge.
    </div>
</div>

<?php render_pagination($page, $totalPages, $totalRecords, $perPage, '/demand_side_employer_users_report.php', $baseParams, 'Employer report pagination'); ?>

<?php render_footer(); ?>

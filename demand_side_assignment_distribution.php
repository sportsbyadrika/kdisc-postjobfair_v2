<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/demand_side_helpers.php';
require_admin();

$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
if (!is_manage_admin($currentUser)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock', 'subtitle' => 'Only Administrator or DSM Admin can plan employer assignments.']);
    echo '<div class="alert alert-danger">Only Administrator and DSM Admin can access the assignment distribution planner.</div>';
    echo '<a class="btn btn-primary" href="/demand_side_assignments.php"><i class="bi bi-arrow-left me-1"></i>Back to Assignments</a>';
    render_footer();
    exit;
}

demand_side_bootstrap();

/* Read filters — roles + optional category + explicit user pick + split unit. */
$selectedRoles = $_GET['role'] ?? $_POST['role'] ?? [];
if (!is_array($selectedRoles)) {
    $selectedRoles = $selectedRoles === '' ? [] : [$selectedRoles];
}
$selectedRoles = array_values(array_unique(array_filter(array_map('strval', $selectedRoles), static fn(string $v): bool => $v !== '')));
$categoryFilter = trim((string) ($_GET['category'] ?? $_POST['category'] ?? ''));
$categories = demand_get_categories();
$categoryByName = [];
foreach ($categories as $c) { $categoryByName[(string) $c['name']] = $c; }

$splitBy = (string) ($_GET['split_by'] ?? $_POST['split_by'] ?? 'employer');
if (!in_array($splitBy, ['employer', 'job'], true)) { $splitBy = 'employer'; }

// Optional filter: restrict the participating users to those who have NO
// rows in either assignment table yet. Useful for a first-pass split that
// only touches operators who haven't been given any work yet.
$onlyUnassigned = (($_GET['only_unassigned'] ?? $_POST['only_unassigned'] ?? '') === '1');

// Explicit picked user IDs. Empty = "use all matched users" (first pass).
$pickedUserIds = $_GET['user_id'] ?? $_POST['user_id'] ?? [];
if (!is_array($pickedUserIds)) { $pickedUserIds = [$pickedUserIds]; }
$pickedUserIds = array_values(array_unique(array_map('intval', $pickedUserIds)));
$pickedUserIds = array_values(array_filter($pickedUserIds, static fn(int $v): bool => $v > 0));
$hasExplicitPick = ($pickedUserIds !== []) || (($_GET['picked'] ?? $_POST['picked'] ?? '') === '1');

/* Distinct roles across active users, so the multi-select always reflects
   who's actually assignable. */
$roleOptions = array_map(
    static fn(array $r): string => (string) $r['role'],
    db()->query("SELECT DISTINCT role FROM users WHERE active_status = 1 AND role IS NOT NULL AND role <> '' ORDER BY role ASC")->fetchAll()
);

/* Users matching the selected roles. */
$matchingUsers = [];
if ($selectedRoles !== []) {
    $ph = implode(',', array_fill(0, count($selectedRoles), '?'));
    $stmt = db()->prepare("SELECT id, name, role FROM users WHERE active_status = 1 AND role IN ($ph) ORDER BY name ASC");
    $stmt->execute($selectedRoles);
    $matchingUsers = $stmt->fetchAll();
}

// Optional pre-picker narrowing: keep only users who have zero rows in
// either assignment table. This runs BEFORE the picker so the "Users to
// include" checkbox list only shows the eligible operators.
$excludedForHavingAssignments = 0;
if ($onlyUnassigned && $matchingUsers !== []) {
    $assignedUserIds = [];
    foreach (db()->query('SELECT DISTINCT user_id FROM demand_user_employer_assignments')->fetchAll() as $r) {
        $assignedUserIds[(int) $r['user_id']] = true;
    }
    foreach (db()->query('SELECT DISTINCT user_id FROM demand_user_job_assignments')->fetchAll() as $r) {
        $assignedUserIds[(int) $r['user_id']] = true;
    }
    $before = count($matchingUsers);
    $matchingUsers = array_values(array_filter(
        $matchingUsers,
        static fn(array $u): bool => !isset($assignedUserIds[(int) $u['id']])
    ));
    $excludedForHavingAssignments = $before - count($matchingUsers);
}

// Restrict participating users to the explicit pick if the admin has
// confirmed one. Before confirmation, all matched users participate so the
// initial preview still shows something sensible.
$participatingUsers = $matchingUsers;
if ($hasExplicitPick) {
    $participatingUsers = array_values(array_filter(
        $matchingUsers,
        static fn(array $u): bool => in_array((int) $u['id'], $pickedUserIds, true)
    ));
}

/* Global pool. Two shapes: employer pool (each row is a whole employer +
   its jobs) or job pool (each row is one job carrying open_positions).
   Category filter narrows the pool to employers whose SUM(open_positions)
   sits in the chosen range. */
// Distribute only non-assigned units: employers already sitting in
// demand_user_employer_assignments (or jobs in demand_user_job_assignments
// when split_by=job) are excluded from the pool. Prevents re-assigning
// something that already has an owner and keeps the fair-split meaningful.
if ($splitBy === 'job') {
    // Two exclusions:
    //   (a) the individual job is already in demand_user_job_assignments,
    //   (b) the job's parent employer is already in
    //       demand_user_employer_assignments — assigning the employer to
    //       someone means every job of that employer is implicitly theirs,
    //       so those jobs shouldn't be re-handed out one at a time.
    // Net effect: the pool is every job of every UN-assigned employer that
    // also has no per-job owner — which matches the requested "if employer
    // 1040 is not yet assigned and has 630 jobs, distribute those 630".
    $poolSql = "SELECT j.job_id, j.emp_id, e.employer_name, j.jobtitle, COALESCE(j.open_positions, 0) AS positions_count
        FROM demand_employer_jobs j
        INNER JOIN demand_employers e ON e.employer_id = j.emp_id
        WHERE j.job_id NOT IN (SELECT job_id FROM demand_user_job_assignments)
          AND j.emp_id NOT IN (SELECT employer_id FROM demand_user_employer_assignments)";
    if ($categoryFilter !== '' && isset($categoryByName[$categoryFilter])) {
        $c = $categoryByName[$categoryFilter];
        $poolSql .= " AND j.emp_id IN (
            SELECT emp_id FROM (
                SELECT emp_id, SUM(open_positions) AS s FROM demand_employer_jobs GROUP BY emp_id
            ) t WHERE t.s BETWEEN " . (int) $c['min_positions'] . ' AND ' . (int) $c['max_positions'] . "
        )";
    }
    $poolSql .= " ORDER BY positions_count DESC, j.job_id ASC";
    $poolRows = db()->query($poolSql)->fetchAll();
} else {
    $poolSql = "SELECT e.employer_id, e.employer_name, COUNT(j.id) AS jobs_count, COALESCE(SUM(j.open_positions), 0) AS positions_count
        FROM demand_employers e
        INNER JOIN demand_employer_jobs j ON j.emp_id = e.employer_id
        WHERE e.employer_id NOT IN (SELECT employer_id FROM demand_user_employer_assignments)
        GROUP BY e.employer_id, e.employer_name";
    if ($categoryFilter !== '' && isset($categoryByName[$categoryFilter])) {
        $c = $categoryByName[$categoryFilter];
        $poolSql .= " HAVING positions_count BETWEEN " . (int) $c['min_positions'] . ' AND ' . (int) $c['max_positions'];
    }
    $poolSql .= ' ORDER BY jobs_count DESC, e.employer_id ASC';
    $poolRows = db()->query($poolSql)->fetchAll();
}

$totalJobsInPool = 0;
$totalPositionsInPool = 0;
$totalEmployerIdsInPool = [];
if ($splitBy === 'job') {
    $totalJobsInPool = count($poolRows);
    foreach ($poolRows as $pr) {
        $totalPositionsInPool += (int) $pr['positions_count'];
        $totalEmployerIdsInPool[(int) $pr['emp_id']] = true;
    }
    $totalEmployersInPool = count($totalEmployerIdsInPool);
} else {
    $totalEmployersInPool = count($poolRows);
    foreach ($poolRows as $pr) {
        $totalJobsInPool += (int) $pr['jobs_count'];
        $totalPositionsInPool += (int) $pr['positions_count'];
    }
}

/* Distribution: greedy fair split. Sort pool rows by jobs_count / positions
   DESC (already done above), then assign each item to the user with the
   smallest running load. Whole units (employer OR job) stay with a single
   user, which keeps the scope join clean downstream. */
$distribution = [];
foreach ($participatingUsers as $u) {
    $distribution[(int) $u['id']] = [
        'user_id'     => (int) $u['id'],
        'user_name'   => (string) $u['name'],
        'user_role'   => (string) $u['role'],
        'jobs_count'  => 0,
        'employer_ids' => [],
        'job_ids'      => [],
    ];
}
if ($distribution !== [] && $poolRows !== []) {
    foreach ($poolRows as $pr) {
        $chosen = null;
        foreach ($distribution as $uid => $slot) {
            $chosenSlot = $chosen !== null ? $distribution[$chosen] : null;
            if ($chosen === null
                || $slot['jobs_count'] < $chosenSlot['jobs_count']
                || ($slot['jobs_count'] === $chosenSlot['jobs_count']
                    && count($slot['employer_ids']) + count($slot['job_ids']) < count($chosenSlot['employer_ids']) + count($chosenSlot['job_ids']))
            ) {
                $chosen = $uid;
            }
        }
        if ($chosen === null) { continue; }
        if ($splitBy === 'job') {
            $distribution[$chosen]['jobs_count']  += 1;
            $distribution[$chosen]['job_ids'][]    = (int) $pr['job_id'];
        } else {
            $distribution[$chosen]['jobs_count']   += (int) $pr['jobs_count'];
            $distribution[$chosen]['employer_ids'][] = (int) $pr['employer_id'];
        }
    }
}
$distributionRows = array_values($distribution);

/* Optional apply: persist the computed distribution as assignments.
   Replaces the target users' existing rows so the split is idempotent. */
$flashMessage = null;
$flashType = 'success';
if (is_post() && ($_POST['action'] ?? '') === 'apply' && $distributionRows !== []) {
    // Append-only apply. Existing assignments (manual OR automated) are
    // never touched — only the newly split rows are inserted. Duplicates
    // are silently ignored via INSERT IGNORE on the UNIQUE (user_id, *_id)
    // key so nothing collides with prior state.
    //
    // Perf: batched multi-VALUE INSERT IGNORE inside a single transaction,
    // 500 rows per batch. A per-row loop was hitting the web server's
    // connection timeout at scale.
    @set_time_limit(0);
    ignore_user_abort(true);

    $table = $splitBy === 'job' ? 'demand_user_job_assignments' : 'demand_user_employer_assignments';
    $col   = $splitBy === 'job' ? 'job_id' : 'employer_id';
    $key   = $splitBy === 'job' ? 'job_ids' : 'employer_ids';

    // Flatten the distribution into a single (user_id, target_id) pair list.
    $pairs = [];
    foreach ($distributionRows as $slot) {
        foreach ($slot[$key] as $tid) { $pairs[] = [(int) $slot['user_id'], (int) $tid]; }
    }
    $inserted = 0; $skippedExisting = 0; $batchSize = 500; $totalPairs = count($pairs);

    if ($totalPairs > 0) {
        db()->query('START TRANSACTION');
        try {
            foreach (array_chunk($pairs, $batchSize) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?, ?, NOW())'));
                $params = [];
                foreach ($chunk as $pair) {
                    $params[] = $pair[0];
                    $params[] = $pair[1];
                    $params[] = $userId;
                }
                $sql = "INSERT IGNORE INTO $table (user_id, $col, assigned_by, assigned_at) VALUES $placeholders";
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
                $newRows = (int) $stmt->affectedRows();
                $inserted        += $newRows;
                $skippedExisting += (count($chunk) - $newRows);
            }
            db()->query('COMMIT');
            $noun = $splitBy === 'job' ? 'job' : 'employer';
            $flashMessage = "Distribution applied (append only): $inserted new $noun assignment(s) written across "
                . count($distributionRows) . ' user(s). Prior assignments were untouched'
                . ($skippedExisting > 0 ? "; $skippedExisting duplicate row(s) skipped." : '.');
        } catch (Throwable $e) {
            db()->query('ROLLBACK');
            $flashMessage = 'Distribution failed while writing rows: ' . $e->getMessage();
            $flashType = 'danger';
        }
    } else {
        $flashMessage = 'Nothing to apply — the pool was empty.';
        $flashType = 'warning';
    }
}

/* Fair-share reference (informational — what the ideal even split looks
   like before greedy quantisation). */
$userCount = count($participatingUsers);
$fairJobsShare = $userCount > 0 ? $totalJobsInPool / $userCount : 0.0;
$fairEmployersShare = $userCount > 0 ? $totalEmployersInPool / $userCount : 0.0;
$fairPositionsShare = $userCount > 0 ? $totalPositionsInPool / $userCount : 0.0;

render_header('Assignment Distribution', ['main_container_class' => 'container-fluid']);
render_page_header('Demand Side · Assignment Distribution Planner', [
    'icon' => 'bi-diagram-3',
    'subtitle' => 'Pick one or more User Types to see the matching users and a fair split of the current Job pool (whole employers kept together, so emp_id → employer_id scope stays clean).',
    'actions' => '<a class="btn btn-light" href="/demand_side_assignments.php"><i class="bi bi-arrow-left me-1"></i>Back to Assignments</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<form method="get" class="card mb-3">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-funnel text-primary me-1"></i>User Types</h2>
        <div class="d-flex flex-wrap gap-3">
            <?php if ($roleOptions === []): ?>
                <div class="text-muted small">No active users to distribute to.</div>
            <?php endif; ?>
            <?php foreach ($roleOptions as $role): $cid = 'role-' . md5($role); ?>
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" name="role[]" value="<?= esc($role) ?>" id="<?= esc($cid) ?>" <?= in_array($role, $selectedRoles, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="<?= esc($cid) ?>"><?= esc(role_label($role)) ?></label>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="row g-3 mt-1">
            <div class="col-md-4">
                <label class="form-label small mb-1">Category (optional — narrows the pool)</label>
                <select class="form-select form-select-sm" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= esc((string) $c['name']) ?>" <?= $categoryFilter === (string) $c['name'] ? 'selected' : '' ?>><?= esc((string) $c['name']) ?> (<?= number_format((int) $c['min_positions']) ?>–<?= number_format((int) $c['max_positions']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Split by</label>
                <div class="btn-group" role="group">
                    <input type="radio" class="btn-check" name="split_by" id="split_employer" value="employer" <?= $splitBy === 'employer' ? 'checked' : '' ?>>
                    <label class="btn btn-sm btn-outline-primary" for="split_employer"><i class="bi bi-building me-1"></i>Whole employers</label>
                    <input type="radio" class="btn-check" name="split_by" id="split_job" value="job" <?= $splitBy === 'job' ? 'checked' : '' ?>>
                    <label class="btn btn-sm btn-outline-primary" for="split_job"><i class="bi bi-briefcase me-1"></i>Individual jobs</label>
                </div>
                <div class="small text-muted mt-1">Employer split keeps whole employers with one user; Job split divides at the job level. <strong>Only unassigned units are distributed</strong> — anything already sitting in an existing assignment is skipped.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Users filter</label>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="only_unassigned" id="only_unassigned" value="1" <?= $onlyUnassigned ? 'checked' : '' ?>>
                    <label class="form-check-label" for="only_unassigned">Only users with <strong>no existing</strong> employer / job assignments</label>
                </div>
                <div class="small text-muted mt-1">When ticked, users who already have any assignment row are removed from the picker below and from the split. Useful for a first-pass distribution to new operators only.</div>
            </div>
        </div>
        <?php if ($onlyUnassigned && $excludedForHavingAssignments > 0): ?>
            <div class="alert alert-info py-2 px-3 mt-3 mb-0 small">
                <i class="bi bi-info-circle-fill me-1"></i>
                Filter <strong>Only users with no existing assignments</strong> removed <strong><?= number_format($excludedForHavingAssignments) ?></strong> already-assigned user(s) from the picker.
            </div>
        <?php endif; ?>
        <?php if ($matchingUsers !== []): ?>
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <label class="form-label small mb-1">Users to include (<?= count($matchingUsers) ?> matched)</label>
                    <div class="border rounded p-2 bg-light-subtle" style="max-height:180px; overflow-y:auto;">
                        <div class="mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="userPickAll">Select all</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="userPickNone">Clear</button>
                        </div>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($matchingUsers as $u): ?>
                                <?php
                                    $uid = (int) $u['id'];
                                    $cid = 'upick-' . $uid;
                                    $checked = !$hasExplicitPick || in_array($uid, $pickedUserIds, true);
                                ?>
                                <div class="form-check">
                                    <input class="form-check-input js-user-pick" type="checkbox" name="user_id[]" value="<?= $uid ?>" id="<?= esc($cid) ?>" <?= $checked ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="<?= esc($cid) ?>"><?= esc((string) $u['name']) ?> · <span class="small text-muted"><?= esc(role_label((string) $u['role'])) ?></span></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="small text-muted mt-1">Uncheck anyone who shouldn't be part of the split (e.g. users who don't cover this category).</div>
                    <input type="hidden" name="picked" value="1">
                </div>
            </div>
        <?php endif; ?>
        <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-people me-1"></i>Show distribution</button>
            <a class="btn btn-light" href="/demand_side_assignment_distribution.php">Reset</a>
        </div>
    </div>
</form>
<script>
(function () {
    const all = document.getElementById('userPickAll');
    const none = document.getElementById('userPickNone');
    const boxes = () => Array.from(document.querySelectorAll('.js-user-pick'));
    all?.addEventListener('click', () => boxes().forEach((cb) => cb.checked = true));
    none?.addEventListener('click', () => boxes().forEach((cb) => cb.checked = false));
})();
</script>

<?php if ($selectedRoles === []): ?>
    <div class="alert alert-info">Pick one or more User Types above to preview the split.</div>
<?php else: ?>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card card-stat accent-primary h-100">
                <div class="card-body">
                    <p class="stat-label">Users matched</p>
                    <p class="stat-value"><?= number_format($userCount) ?></p>
                    <span class="data-meta"><?= esc(implode(', ', array_map('role_label', $selectedRoles))) ?></span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat accent-info h-100">
                <div class="card-body">
                    <p class="stat-label">Employers in pool</p>
                    <p class="stat-value"><?= number_format($totalEmployersInPool) ?></p>
                    <span class="data-meta">Only employers that have jobs</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat accent-success h-100">
                <div class="card-body">
                    <p class="stat-label">Jobs in pool</p>
                    <p class="stat-value"><?= number_format($totalJobsInPool) ?></p>
                    <span class="data-meta">All demand_employer_jobs rows</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat accent-warning h-100">
                <div class="card-body">
                    <p class="stat-label">Fair share / user</p>
                    <p class="stat-value"><?= number_format($fairJobsShare, 1) ?></p>
                    <span class="data-meta"><?= number_format($fairEmployersShare, 1) ?> employers · <?= number_format($fairPositionsShare, 1) ?> positions each (ideal)</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-diagram-3 text-primary me-1"></i>Distribution</span>
            <span class="status-chip status-info"><?= number_format($userCount) ?> users · greedy fair split</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sl No</th>
                        <th>User</th>
                        <th>Role</th>
                        <th class="text-end"><?= $splitBy === 'job' ? 'Employers reached' : 'Employers assigned' ?></th>
                        <th class="text-end"><?= $splitBy === 'job' ? 'Jobs assigned' : 'Jobs (via employers)' ?></th>
                        <th class="text-end">Share of jobs</th>
                        <th>Assigned IDs</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($distributionRows === []): ?>
                        <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No users matched the selected user types.</div></td></tr>
                    <?php endif; ?>
                    <?php $i = 1; foreach ($distributionRows as $slot): ?>
                        <?php
                            $share = $totalJobsInPool > 0 ? (int) $slot['jobs_count'] / $totalJobsInPool * 100 : 0;
                            $ids = $splitBy === 'job' ? $slot['job_ids'] : $slot['employer_ids'];
                            $empReached = $splitBy === 'job' ? count(array_unique(array_map(static fn($jid) => null, $slot['job_ids']))) : count($slot['employer_ids']);
                        ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td class="fw-semibold"><?= esc((string) $slot['user_name']) ?></td>
                            <td><span class="status-chip status-neutral"><?= esc(role_label((string) $slot['user_role'])) ?></span></td>
                            <td class="text-end fw-bold"><?= number_format($splitBy === 'job' ? 0 : count($slot['employer_ids'])) ?></td>
                            <td class="text-end fw-bold"><?= number_format((int) $slot['jobs_count']) ?></td>
                            <td class="text-end"><?= number_format($share, 1) ?>%</td>
                            <td class="small text-muted" style="max-width:380px;">
                                <div class="text-truncate" title="<?= esc(implode(', ', $ids)) ?>">
                                    <?= esc(implode(', ', $ids)) ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($distributionRows !== [] && $totalEmployersInPool > 0): ?>
            <div class="card-body border-top">
                <form method="post" onsubmit="return confirm('Apply this distribution to <?= count($distributionRows) ?> user(s)? Existing assignments (manual or automated) will be KEPT — only the newly split rows are added.');">
                    <?php foreach ($selectedRoles as $sr): ?>
                        <input type="hidden" name="role[]" value="<?= esc($sr) ?>">
                    <?php endforeach; ?>
                    <?php if ($categoryFilter !== ''): ?>
                        <input type="hidden" name="category" value="<?= esc($categoryFilter) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="split_by" value="<?= esc($splitBy) ?>">
                    <?php if ($onlyUnassigned): ?>
                        <input type="hidden" name="only_unassigned" value="1">
                    <?php endif; ?>
                    <input type="hidden" name="picked" value="1">
                    <?php foreach ($participatingUsers as $u): ?>
                        <input type="hidden" name="user_id[]" value="<?= (int) $u['id'] ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="action" value="apply">
                    <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Apply as assignments</button>
                    <a href="/demand_side_assignments.php" class="btn btn-light">Go to Assignments list</a>
                </form>
                <p class="small text-muted mt-2 mb-0">
                    Whole employers are kept together (each employer_id sits with exactly one user), so the emp_id → employer_id foreign key still scopes the Employer listing cleanly.
                    The greedy algorithm sorts employers by job count DESC and assigns each to the user with the fewest running jobs, which tends to keep every user within ±1 employer of the ideal split.
                    <br><strong>Append only</strong> — Apply never deletes existing assignments (manual or automated); it just inserts the newly split rows. Duplicates (already-assigned user + id pairs) are silently skipped.
                </p>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php render_footer(); ?>

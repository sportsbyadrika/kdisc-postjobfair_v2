<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/demand_side_helpers.php';
require_admin();

$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
if (($currentUser['role'] ?? '') !== 'administrator') {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock', 'subtitle' => 'Only the Administrator role can plan employer assignments.']);
    echo '<div class="alert alert-danger">Only Administrator can access the assignment distribution planner.</div>';
    echo '<a class="btn btn-primary" href="/demand_side_assignments.php"><i class="bi bi-arrow-left me-1"></i>Back to Assignments</a>';
    render_footer();
    exit;
}

demand_side_bootstrap();

/* Read filter — one or more roles. */
$selectedRoles = $_GET['role'] ?? $_POST['role'] ?? [];
if (!is_array($selectedRoles)) {
    $selectedRoles = $selectedRoles === '' ? [] : [$selectedRoles];
}
$selectedRoles = array_values(array_unique(array_filter(array_map('strval', $selectedRoles), static fn(string $v): bool => $v !== '')));

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

/* Global pool: every employer that owns at least one job, with that
   employer's job count. Employers with zero jobs are excluded because
   they add no measurable workload. */
$poolRows = db()->query("SELECT e.employer_id, e.employer_name, COUNT(j.id) AS jobs_count
    FROM demand_employers e
    INNER JOIN demand_employer_jobs j ON j.emp_id = e.employer_id
    GROUP BY e.employer_id, e.employer_name
    ORDER BY jobs_count DESC, e.employer_id ASC")->fetchAll();

$totalEmployersInPool = count($poolRows);
$totalJobsInPool = 0;
foreach ($poolRows as $pr) { $totalJobsInPool += (int) $pr['jobs_count']; }

/* Distribution: greedy fair split. Sort employers by jobs_count DESC
   (already done above), then repeatedly assign the next employer to the
   user with the smallest running job count. Keeps whole employers
   together, which is what makes the emp_id -> employer_id scope useful
   downstream. */
$distribution = [];
foreach ($matchingUsers as $u) {
    $distribution[(int) $u['id']] = [
        'user_id'     => (int) $u['id'],
        'user_name'   => (string) $u['name'],
        'user_role'   => (string) $u['role'],
        'jobs_count'  => 0,
        'employer_ids' => [],
    ];
}
if ($distribution !== [] && $poolRows !== []) {
    foreach ($poolRows as $pr) {
        // Pick the user with the smallest running jobs_count; ties broken
        // by whoever has fewer employers so we spread reach evenly.
        $chosen = null;
        foreach ($distribution as $uid => $slot) {
            if ($chosen === null
                || $slot['jobs_count'] < $distribution[$chosen]['jobs_count']
                || ($slot['jobs_count'] === $distribution[$chosen]['jobs_count']
                    && count($slot['employer_ids']) < count($distribution[$chosen]['employer_ids']))
            ) {
                $chosen = $uid;
            }
        }
        if ($chosen !== null) {
            $distribution[$chosen]['jobs_count']  += (int) $pr['jobs_count'];
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
    $inserted = 0; $replaced = 0;
    $del = db()->prepare('DELETE FROM demand_user_employer_assignments WHERE user_id = ?');
    $ins = db()->prepare('INSERT INTO demand_user_employer_assignments (user_id, employer_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())');
    foreach ($distributionRows as $slot) {
        $del->execute([$slot['user_id']]);
        $replaced += $del->affectedRows();
        foreach ($slot['employer_ids'] as $eid) {
            try { $ins->execute([$slot['user_id'], $eid, $userId]); $inserted++; } catch (Throwable $e) { /* dup/FK ignore */ }
        }
    }
    $flashMessage = "Distribution applied: $inserted employer assignment(s) written across " . count($distributionRows) . ' user(s). (' . $replaced . ' prior assignment row(s) were replaced.)';
}

/* Fair-share reference (informational — what the ideal even split looks
   like before greedy quantisation). */
$userCount = count($matchingUsers);
$fairJobsShare = $userCount > 0 ? $totalJobsInPool / $userCount : 0.0;
$fairEmployersShare = $userCount > 0 ? $totalEmployersInPool / $userCount : 0.0;

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
        <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-people me-1"></i>Show distribution</button>
            <a class="btn btn-light" href="/demand_side_assignment_distribution.php">Reset</a>
        </div>
    </div>
</form>

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
                    <span class="data-meta"><?= number_format($fairEmployersShare, 1) ?> employers each (ideal split)</span>
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
                        <th class="text-end">Employers assigned</th>
                        <th class="text-end">Jobs assigned</th>
                        <th class="text-end">Share of jobs</th>
                        <th>Assigned Employer IDs</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($distributionRows === []): ?>
                        <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No users matched the selected user types.</div></td></tr>
                    <?php endif; ?>
                    <?php $i = 1; foreach ($distributionRows as $slot): ?>
                        <?php $share = $totalJobsInPool > 0 ? (int) $slot['jobs_count'] / $totalJobsInPool * 100 : 0; ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td class="fw-semibold"><?= esc((string) $slot['user_name']) ?></td>
                            <td><span class="status-chip status-neutral"><?= esc(role_label((string) $slot['user_role'])) ?></span></td>
                            <td class="text-end fw-bold"><?= number_format(count($slot['employer_ids'])) ?></td>
                            <td class="text-end fw-bold"><?= number_format((int) $slot['jobs_count']) ?></td>
                            <td class="text-end"><?= number_format($share, 1) ?>%</td>
                            <td class="small text-muted" style="max-width:380px;">
                                <div class="text-truncate" title="<?= esc(implode(', ', $slot['employer_ids'])) ?>">
                                    <?= esc(implode(', ', $slot['employer_ids'])) ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($distributionRows !== [] && $totalEmployersInPool > 0): ?>
            <div class="card-body border-top">
                <form method="post" onsubmit="return confirm('Apply this distribution? Existing employer assignments for the <?= count($distributionRows) ?> matched user(s) will be REPLACED with the new split.');">
                    <?php foreach ($selectedRoles as $sr): ?>
                        <input type="hidden" name="role[]" value="<?= esc($sr) ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="action" value="apply">
                    <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Apply as assignments</button>
                    <a href="/demand_side_assignments.php" class="btn btn-light">Go to Assignments list</a>
                </form>
                <p class="small text-muted mt-2 mb-0">
                    Whole employers are kept together (each employer_id sits with exactly one user), so the emp_id → employer_id foreign key still scopes the Employer listing cleanly.
                    The greedy algorithm sorts employers by job count DESC and assigns each to the user with the fewest running jobs, which tends to keep every user within ±1 employer of the ideal split.
                </p>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php render_footer(); ?>

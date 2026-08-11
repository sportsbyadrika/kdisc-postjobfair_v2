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
    render_page_header('Access denied', ['icon' => 'bi-shield-lock', 'subtitle' => 'Only the Administrator role can assign employers.']);
    echo '<div class="alert alert-danger">Only Administrator can manage employer assignments.</div>';
    echo '<a class="btn btn-primary" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>';
    render_footer();
    exit;
}

demand_side_bootstrap();

$flashMessage = null;
$flashType = 'success';

$editUserId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$scopeType  = (string) ($_GET['scope_type'] ?? $_POST['scope_type'] ?? 'employer');
if (!in_array($scopeType, ['employer', 'job'], true)) { $scopeType = 'employer'; }
$action = (string) ($_POST['action'] ?? '');
$submittedIds = (string) ($_POST['employer_ids'] ?? $_POST['job_ids'] ?? '');

/* Preview + Save share the same parse step. */
$formUserId = $editUserId;
$formIdsRaw = '';
$previewLoaded = false;
$previewFoundIds = [];
$previewMissingIds = [];
$previewEmployerCount = 0;
$previewJobCount = 0;
$previewOpenPositions = 0;

if (is_post() && ($action === 'preview' || $action === 'save')) {
    $formUserId = (int) ($_POST['user_id'] ?? 0);
    $formIdsRaw = $submittedIds;
    $parsedIds = demand_parse_employer_id_list($formIdsRaw);
    $idLabel = $scopeType === 'job' ? 'job_id' : 'employer_id';
    if ($formUserId <= 0) {
        $flashMessage = 'Please select a user.';
        $flashType = 'danger';
    } elseif ($parsedIds === []) {
        $flashMessage = "Enter at least one numeric $idLabel.";
        $flashType = 'danger';
    } else {
        $placeholders = implode(',', array_fill(0, count($parsedIds), '?'));
        if ($scopeType === 'job') {
            // Match parsed IDs against demand_employer_jobs. Preview counts:
            // Jobs matched, distinct Employers reached (via emp_id FK), and
            // total open positions across those jobs.
            $foundStmt = db()->prepare("SELECT job_id FROM demand_employer_jobs WHERE job_id IN ($placeholders)");
            $foundStmt->execute($parsedIds);
            $previewFoundIds = array_map(static fn(array $r): int => (int) $r['job_id'], $foundStmt->fetchAll());
            $previewMissingIds = array_values(array_diff($parsedIds, $previewFoundIds));
            $previewJobCount = count($previewFoundIds);
            if ($previewFoundIds !== []) {
                $ph2 = implode(',', array_fill(0, count($previewFoundIds), '?'));
                $totalsStmt = db()->prepare("SELECT COUNT(DISTINCT emp_id) AS employers, COALESCE(SUM(open_positions), 0) AS positions
                    FROM demand_employer_jobs WHERE job_id IN ($ph2)");
                $totalsStmt->execute($previewFoundIds);
                $tot = $totalsStmt->fetch();
                $previewEmployerCount = (int) ($tot['employers'] ?? 0);
                $previewOpenPositions = (int) ($tot['positions'] ?? 0);
            }
        } else {
            // Employer-scope path (unchanged from before).
            $foundStmt = db()->prepare("SELECT employer_id FROM demand_employers WHERE employer_id IN ($placeholders)");
            $foundStmt->execute($parsedIds);
            $previewFoundIds = array_map(static fn(array $r): int => (int) $r['employer_id'], $foundStmt->fetchAll());
            $previewMissingIds = array_values(array_diff($parsedIds, $previewFoundIds));
            $previewEmployerCount = count($previewFoundIds);
            if ($previewFoundIds !== []) {
                $ph2 = implode(',', array_fill(0, count($previewFoundIds), '?'));
                $totalsStmt = db()->prepare("SELECT COUNT(*) AS jobs, COALESCE(SUM(open_positions), 0) AS positions
                    FROM demand_employer_jobs WHERE emp_id IN ($ph2)");
                $totalsStmt->execute($previewFoundIds);
                $tot = $totalsStmt->fetch();
                $previewJobCount = (int) ($tot['jobs'] ?? 0);
                $previewOpenPositions = (int) ($tot['positions'] ?? 0);
            }
        }
        $previewLoaded = true;

        if ($action === 'save') {
            if ($scopeType === 'job') {
                db()->prepare('DELETE FROM demand_user_job_assignments WHERE user_id = ?')->execute([$formUserId]);
                if ($previewFoundIds !== []) {
                    $ins = db()->prepare('INSERT INTO demand_user_job_assignments (user_id, job_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())');
                    foreach ($previewFoundIds as $jid) {
                        try { $ins->execute([$formUserId, $jid, $userId]); } catch (Throwable $e) { /* dup ignore */ }
                    }
                }
                $flashMessage = sprintf(
                    'Saved: %d job(s) assigned to user %d (%d skipped as not found).',
                    count($previewFoundIds), $formUserId, count($previewMissingIds)
                );
            } else {
                db()->prepare('DELETE FROM demand_user_employer_assignments WHERE user_id = ?')->execute([$formUserId]);
                if ($previewFoundIds !== []) {
                    $ins = db()->prepare('INSERT INTO demand_user_employer_assignments (user_id, employer_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())');
                    foreach ($previewFoundIds as $eid) {
                        try { $ins->execute([$formUserId, $eid, $userId]); } catch (Throwable $e) { /* dup ignore */ }
                    }
                }
                $flashMessage = sprintf(
                    'Saved: %d employer(s) assigned to user %d (%d skipped as not found).',
                    count($previewFoundIds), $formUserId, count($previewMissingIds)
                );
            }
            $editUserId = 0; $formUserId = 0; $formIdsRaw = '';
            $previewLoaded = false; $previewFoundIds = []; $previewMissingIds = [];
            $previewEmployerCount = 0; $previewJobCount = 0; $previewOpenPositions = 0;
        }
    }
} elseif (is_post() && $action === 'delete') {
    $delUserId = (int) ($_POST['user_id'] ?? 0);
    if ($delUserId > 0) {
        $del = db()->prepare('DELETE FROM demand_user_employer_assignments WHERE user_id = ?');
        $del->execute([$delUserId]);
        $delJ = db()->prepare('DELETE FROM demand_user_job_assignments WHERE user_id = ?');
        $delJ->execute([$delUserId]);
        $flashMessage = 'Removed ' . ($del->affectedRows() + $delJ->affectedRows()) . ' assignment(s) for user ' . $delUserId . '.';
    }
    $editUserId = 0;
} elseif ($editUserId > 0 && !is_post()) {
    // Prefill the form for an existing user's assignments — use whichever
    // scope type is currently selected.
    if ($scopeType === 'job') {
        $existing = demand_get_assigned_job_ids($editUserId);
    } else {
        $existing = demand_get_assigned_employer_ids($editUserId);
    }
    $formIdsRaw = implode(', ', $existing);
    $formUserId = $editUserId;
}

/* User list for the dropdown. Administrator users are included too — their
   assignments narrow what they see on the Employer listing exactly the same
   way as non-admin users. */
$userOptions = db()->query("SELECT id, name, role FROM users WHERE active_status = 1 ORDER BY name ASC")->fetchAll();
$userRoleOptions = [];
foreach ($userOptions as $u) {
    $r = (string) ($u['role'] ?? '');
    if ($r !== '' && !in_array($r, $userRoleOptions, true)) {
        $userRoleOptions[] = $r;
    }
}
sort($userRoleOptions);
$filterRoleForEdit = '';
if ($editUserId > 0) {
    foreach ($userOptions as $u) {
        if ((int) $u['id'] === $editUserId) { $filterRoleForEdit = (string) $u['role']; break; }
    }
}

/* Assignments summary: one row per user, joining BOTH employer-scope and
   job-scope assignments so admins see the full picture at a glance. */
$summarySql = "SELECT
        u.id AS user_id,
        u.name AS user_name,
        u.role AS user_role,
        COALESCE(ea.employer_count, 0) AS employer_count,
        ea.employer_ids AS employer_ids,
        COALESCE(ja.job_count, 0) AS job_count,
        ja.job_ids AS job_ids,
        COALESCE(ea.jobs_from_employers, 0) + COALESCE(ja.job_count, 0) AS jobs_total_effective,
        COALESCE(ea.positions_from_employers, 0) + COALESCE(ja.positions_from_jobs, 0) AS positions_total
    FROM users u
    LEFT JOIN (
        SELECT a.user_id,
            COUNT(DISTINCT a.employer_id) AS employer_count,
            GROUP_CONCAT(DISTINCT a.employer_id ORDER BY a.employer_id) AS employer_ids,
            COALESCE(SUM(j.jobs_count), 0) AS jobs_from_employers,
            COALESCE(SUM(j.positions_count), 0) AS positions_from_employers
        FROM demand_user_employer_assignments a
        LEFT JOIN (
            SELECT emp_id, COUNT(*) AS jobs_count, SUM(open_positions) AS positions_count
            FROM demand_employer_jobs GROUP BY emp_id
        ) j ON j.emp_id = a.employer_id
        GROUP BY a.user_id
    ) ea ON ea.user_id = u.id
    LEFT JOIN (
        SELECT a.user_id,
            COUNT(*) AS job_count,
            GROUP_CONCAT(DISTINCT a.job_id ORDER BY a.job_id) AS job_ids,
            COALESCE(SUM(j.open_positions), 0) AS positions_from_jobs
        FROM demand_user_job_assignments a
        LEFT JOIN demand_employer_jobs j ON j.job_id = a.job_id
        GROUP BY a.user_id
    ) ja ON ja.user_id = u.id
    WHERE (ea.employer_count IS NOT NULL AND ea.employer_count > 0)
       OR (ja.job_count IS NOT NULL AND ja.job_count > 0)
    ORDER BY u.name ASC";
$assignmentRows = db()->query($summarySql)->fetchAll();

render_header('Assign Employers to Users', ['main_container_class' => 'container-fluid']);
render_page_header('Demand Side · Assign Employers to Users', [
    'icon' => 'bi-people-arrows',
    'subtitle' => 'Restrict what the Employer listing shows for a given user. Administrators can also be scoped through this page.',
    'actions' => '<a class="btn btn-light me-1" href="/demand_side_assignment_distribution.php"><i class="bi bi-diagram-3 me-1"></i>Distribution Planner</a>'
        . '<a class="btn btn-light" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-person-plus text-primary me-1"></i>
        <?= $editUserId > 0 ? 'Edit assignment' : 'New assignment' ?>
    </div>
    <div class="card-body">
        <form method="post" class="row g-3" id="assignmentForm">
            <input type="hidden" name="action" value="preview">
            <div class="col-12">
                <label class="form-label small mb-1">Scope by</label>
                <div class="btn-group" role="group" aria-label="Scope by">
                    <input type="radio" class="btn-check" name="scope_type" id="scope_employer" value="employer" <?= $scopeType === 'employer' ? 'checked' : '' ?>>
                    <label class="btn btn-sm btn-outline-primary" for="scope_employer"><i class="bi bi-building me-1"></i>Employer IDs</label>
                    <input type="radio" class="btn-check" name="scope_type" id="scope_job" value="job" <?= $scopeType === 'job' ? 'checked' : '' ?>>
                    <label class="btn btn-sm btn-outline-primary" for="scope_job"><i class="bi bi-briefcase me-1"></i>Job IDs</label>
                </div>
                <div class="small text-muted mt-1">
                    Choose whether the IDs below are Employer IDs (whole employer + all its jobs go to the user) or Job IDs (individual jobs; their employers are made visible too, but the Jobs list on the Employer view is filtered to the assigned jobs).
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">User Type</label>
                <select class="form-select" id="userRoleFilter" <?= $editUserId > 0 ? 'disabled' : '' ?>>
                    <option value="">All User Types</option>
                    <?php foreach ($userRoleOptions as $r): ?>
                        <option value="<?= esc($r) ?>" <?= $filterRoleForEdit === $r ? 'selected' : '' ?>><?= esc(role_label($r)) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="small text-muted mt-1">Filters the User dropdown to that role.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">User</label>
                <select class="form-select" name="user_id" id="userSelect" required <?= $editUserId > 0 ? 'disabled' : '' ?>>
                    <option value="">Select user…</option>
                    <?php foreach ($userOptions as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" data-role="<?= esc((string) ($u['role'] ?? '')) ?>" <?= (int) $u['id'] === $formUserId ? 'selected' : '' ?>>
                            <?= esc((string) $u['name']) ?> · <?= esc(role_label((string) $u['role'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($editUserId > 0): ?>
                    <input type="hidden" name="user_id" value="<?= $editUserId ?>">
                    <div class="small text-muted mt-1">Locked — editing an existing assignment. <a href="/demand_side_assignments.php">Start new</a>.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-5">
                <?php $idsLabel = $scopeType === 'job' ? 'Job IDs' : 'Employer IDs'; ?>
                <label class="form-label"><?= esc($idsLabel) ?> <span class="small text-muted">(comma / space / newline separated)</span></label>
                <textarea class="form-control" name="<?= $scopeType === 'job' ? 'job_ids' : 'employer_ids' ?>" rows="4" placeholder="e.g. 1012, 1234, 4567&#10;1890 1901 2001"><?= esc($formIdsRaw) ?></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Preview totals</button>
                <a class="btn btn-light" href="/demand_side_assignments.php">Reset form</a>
            </div>
        </form>
        <script>
        (function () {
            const roleSel = document.getElementById('userRoleFilter');
            const userSel = document.getElementById('userSelect');
            if (!roleSel || !userSel) return;
            const applyFilter = () => {
                const wanted = String(roleSel.value || '');
                let selectedStillVisible = false;
                Array.from(userSel.options).forEach((opt) => {
                    if (!opt.value) { opt.hidden = false; return; }
                    const role = opt.getAttribute('data-role') || '';
                    const show = (wanted === '' || role === wanted);
                    opt.hidden = !show;
                    if (show && opt.selected) selectedStillVisible = true;
                });
                if (!selectedStillVisible) userSel.value = '';
            };
            roleSel.addEventListener('change', applyFilter);
            applyFilter();
        })();
        </script>

        <?php if ($previewLoaded): ?>
            <hr>
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="border rounded p-2 text-center bg-light-subtle">
                        <div class="small text-muted">Employers matched</div>
                        <div class="h4 mb-0 fw-bold text-primary"><?= number_format($previewEmployerCount) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-2 text-center bg-light-subtle">
                        <div class="small text-muted">Jobs (via emp_id)</div>
                        <div class="h4 mb-0 fw-bold text-primary"><?= number_format($previewJobCount) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-2 text-center bg-light-subtle">
                        <div class="small text-muted">Open positions</div>
                        <div class="h4 mb-0 fw-bold text-success"><?= number_format($previewOpenPositions) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-2 text-center bg-warning-subtle">
                        <div class="small text-muted">IDs not found</div>
                        <div class="h4 mb-0 fw-bold text-warning"><?= number_format(count($previewMissingIds)) ?></div>
                    </div>
                </div>
            </div>
            <?php if ($previewMissingIds !== []): ?>
                <div class="alert alert-warning mt-3 mb-0 small">
                    <strong>Skipped IDs (not in Employers):</strong>
                    <?= esc(implode(', ', array_slice($previewMissingIds, 0, 100))) ?>
                    <?= count($previewMissingIds) > 100 ? ' …' : '' ?>
                </div>
            <?php endif; ?>
            <?php if ($previewFoundIds !== []): ?>
                <form method="post" class="mt-3" onsubmit="return confirm('Save this assignment? Existing <?= $scopeType === 'job' ? 'job' : 'employer' ?> assignments for the selected user will be replaced.');">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="scope_type" value="<?= esc($scopeType) ?>">
                    <input type="hidden" name="user_id" value="<?= (int) $formUserId ?>">
                    <input type="hidden" name="<?= $scopeType === 'job' ? 'job_ids' : 'employer_ids' ?>" value="<?= esc($formIdsRaw) ?>">
                    <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Save assignment</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people text-primary me-1"></i>Current Assignments</span>
        <span class="status-chip status-info"><?= number_format(count($assignmentRows)) ?> users</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>User</th>
                    <th>Role</th>
                    <th class="text-end">Employer scope</th>
                    <th class="text-end">Job scope</th>
                    <th class="text-end">Effective jobs</th>
                    <th class="text-end">Open Positions</th>
                    <th>Assigned IDs</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($assignmentRows === []): ?>
                    <tr><td colspan="9"><div class="empty-state"><i class="bi bi-inbox"></i>No assignments yet. Assigning employers or jobs to a user filters their Employer listing to that set.</div></td></tr>
                <?php endif; ?>
                <?php $idx = 1; foreach ($assignmentRows as $ar): ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td class="fw-semibold"><?= esc((string) $ar['user_name']) ?></td>
                        <td><span class="status-chip status-neutral"><?= esc(role_label((string) $ar['user_role'])) ?></span></td>
                        <td class="text-end fw-bold"><?= number_format((int) $ar['employer_count']) ?></td>
                        <td class="text-end fw-bold"><?= number_format((int) $ar['job_count']) ?></td>
                        <td class="text-end fw-bold"><?= number_format((int) $ar['jobs_total_effective']) ?></td>
                        <td class="text-end fw-bold"><?= number_format((int) $ar['positions_total']) ?></td>
                        <td class="small text-muted" style="max-width:380px;">
                            <?php if (!empty($ar['employer_ids'])): ?>
                                <div class="text-truncate" title="Employers: <?= esc((string) $ar['employer_ids']) ?>"><i class="bi bi-building me-1"></i><?= esc((string) $ar['employer_ids']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($ar['job_ids'])): ?>
                                <div class="text-truncate" title="Jobs: <?= esc((string) $ar['job_ids']) ?>"><i class="bi bi-briefcase me-1"></i><?= esc((string) $ar['job_ids']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a class="btn btn-sm btn-outline-primary" href="/demand_side_assignments.php?user_id=<?= (int) $ar['user_id'] ?>&scope_type=employer"><i class="bi bi-building"></i></a>
                                <a class="btn btn-sm btn-outline-primary" href="/demand_side_assignments.php?user_id=<?= (int) $ar['user_id'] ?>&scope_type=job"><i class="bi bi-briefcase"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Remove ALL assignments (both scopes) for <?= esc((string) $ar['user_name']) ?>?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= (int) $ar['user_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>

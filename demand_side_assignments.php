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
    render_page_header('Access denied', ['icon' => 'bi-shield-lock', 'subtitle' => 'Only Administrator or DSM Admin can assign employers.']);
    echo '<div class="alert alert-danger">Only Administrator and DSM Admin can manage employer assignments.</div>';
    echo '<a class="btn btn-primary" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>';
    render_footer();
    exit;
}

demand_side_bootstrap();

$flashMessage = null;
$flashType = 'success';

$editUserId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

/* Two textboxes on the same form:
 *   Employer IDs (mandatory) — which employers the user can see
 *   Job IDs      (optional)  — restricts the Jobs list on those employers;
 *                              empty means "all jobs of the assigned employers".
 * Job IDs that don't belong to the resolved Employer set are treated as
 * out-of-scope and reported to the operator. */
$formUserId = $editUserId;
$formEmployerIdsRaw = '';
$formJobIdsRaw = '';
$previewLoaded = false;
$previewFoundEmployerIds = [];
$previewMissingEmployerIds = [];
$previewFoundJobIds = [];
$previewMissingJobIds = [];
$previewOutOfScopeJobIds = [];
$previewJobCount = 0;
$previewOpenPositions = 0;

if (is_post() && ($action === 'preview' || $action === 'save')) {
    $formUserId          = (int) ($_POST['user_id'] ?? 0);
    $formEmployerIdsRaw  = (string) ($_POST['employer_ids'] ?? '');
    $formJobIdsRaw       = (string) ($_POST['job_ids'] ?? '');
    $parsedEmployerIds   = demand_parse_employer_id_list($formEmployerIdsRaw);
    $parsedJobIds        = demand_parse_employer_id_list($formJobIdsRaw);

    if ($formUserId <= 0) {
        $flashMessage = 'Please select a user.';
        $flashType = 'danger';
    } elseif ($parsedEmployerIds === []) {
        $flashMessage = 'Employer IDs is mandatory. Enter at least one numeric employer_id.';
        $flashType = 'danger';
    } else {
        // Resolve employers.
        $ph = implode(',', array_fill(0, count($parsedEmployerIds), '?'));
        $foundStmt = db()->prepare("SELECT employer_id FROM demand_employers WHERE employer_id IN ($ph)");
        $foundStmt->execute($parsedEmployerIds);
        $previewFoundEmployerIds = array_map(static fn(array $r): int => (int) $r['employer_id'], $foundStmt->fetchAll());
        $previewMissingEmployerIds = array_values(array_diff($parsedEmployerIds, $previewFoundEmployerIds));

        // Resolve jobs (if any) AND check they belong to the resolved employers.
        if ($parsedJobIds !== [] && $previewFoundEmployerIds !== []) {
            $ph2 = implode(',', array_fill(0, count($parsedJobIds), '?'));
            $ph3 = implode(',', array_fill(0, count($previewFoundEmployerIds), '?'));
            $jStmt = db()->prepare("SELECT job_id, emp_id FROM demand_employer_jobs WHERE job_id IN ($ph2)");
            $jStmt->execute($parsedJobIds);
            $inScope = []; $outScope = [];
            $foundJobsAny = [];
            foreach ($jStmt->fetchAll() as $jr) {
                $jid = (int) $jr['job_id']; $eid = (int) $jr['emp_id'];
                $foundJobsAny[] = $jid;
                if (in_array($eid, $previewFoundEmployerIds, true)) { $inScope[] = $jid; }
                else { $outScope[] = $jid; }
            }
            $previewFoundJobIds       = $inScope;
            $previewOutOfScopeJobIds  = $outScope;
            $previewMissingJobIds     = array_values(array_diff($parsedJobIds, $foundJobsAny));
        } elseif ($parsedJobIds !== []) {
            $previewMissingJobIds = $parsedJobIds;
        }

        // Effective preview counts. Job scope narrows the visible jobs to
        // in-scope job_ids; blank job textbox means "all jobs of assigned
        // employers".
        if ($previewFoundJobIds !== []) {
            $ph2 = implode(',', array_fill(0, count($previewFoundJobIds), '?'));
            $tStmt = db()->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(open_positions), 0) AS p
                FROM demand_employer_jobs WHERE job_id IN ($ph2)");
            $tStmt->execute($previewFoundJobIds);
            $t = $tStmt->fetch();
            $previewJobCount = (int) ($t['c'] ?? 0);
            $previewOpenPositions = (int) ($t['p'] ?? 0);
        } elseif ($previewFoundEmployerIds !== []) {
            $ph2 = implode(',', array_fill(0, count($previewFoundEmployerIds), '?'));
            $tStmt = db()->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(open_positions), 0) AS p
                FROM demand_employer_jobs WHERE emp_id IN ($ph2)");
            $tStmt->execute($previewFoundEmployerIds);
            $t = $tStmt->fetch();
            $previewJobCount = (int) ($t['c'] ?? 0);
            $previewOpenPositions = (int) ($t['p'] ?? 0);
        }
        $previewLoaded = true;

        if ($action === 'save' && $previewFoundEmployerIds !== []) {
            // Append-only save. Existing employer AND job assignments (manual
            // or automated) are never touched — only the newly entered IDs
            // are inserted. Duplicates (already-assigned user + id pairs)
            // are silently skipped via INSERT IGNORE on the UNIQUE key.
            $newEmployerRows = 0; $dupEmployerRows = 0;
            $newJobRows      = 0; $dupJobRows      = 0;
            $insE = db()->prepare('INSERT IGNORE INTO demand_user_employer_assignments (user_id, employer_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())');
            foreach ($previewFoundEmployerIds as $eid) {
                try {
                    $insE->execute([$formUserId, $eid, $userId]);
                    if ($insE->affectedRows() > 0) { $newEmployerRows++; } else { $dupEmployerRows++; }
                } catch (Throwable $e) { /* FK issue — skip */ }
            }
            if ($previewFoundJobIds !== []) {
                $insJ = db()->prepare('INSERT IGNORE INTO demand_user_job_assignments (user_id, job_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())');
                foreach ($previewFoundJobIds as $jid) {
                    try {
                        $insJ->execute([$formUserId, $jid, $userId]);
                        if ($insJ->affectedRows() > 0) { $newJobRows++; } else { $dupJobRows++; }
                    } catch (Throwable $e) { /* FK issue — skip */ }
                }
            }
            $flashMessage = sprintf(
                'Saved (append only): %d new employer + %d new job assignment(s) added for user %d. Prior assignments were kept%s.%s%s',
                $newEmployerRows,
                $newJobRows,
                $formUserId,
                ($dupEmployerRows + $dupJobRows) > 0
                    ? " (skipped $dupEmployerRows duplicate employer row(s) and $dupJobRows duplicate job row(s) already assigned)"
                    : '',
                $previewMissingEmployerIds === [] ? '' : ' Missing employer_ids skipped: ' . implode(', ', $previewMissingEmployerIds) . '.',
                $previewOutOfScopeJobIds  === [] ? '' : ' Job IDs not owned by the assigned employers skipped: ' . implode(', ', $previewOutOfScopeJobIds) . '.'
            );
            $editUserId = 0; $formUserId = 0; $formEmployerIdsRaw = ''; $formJobIdsRaw = '';
            $previewLoaded = false;
            $previewFoundEmployerIds = $previewMissingEmployerIds = [];
            $previewFoundJobIds = $previewMissingJobIds = $previewOutOfScopeJobIds = [];
            $previewJobCount = 0; $previewOpenPositions = 0;
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
    // Prefill BOTH textboxes for an existing user.
    $formEmployerIdsRaw = implode(', ', demand_get_assigned_employer_ids($editUserId));
    $formJobIdsRaw      = implode(', ', demand_get_assigned_job_ids($editUserId));
    $formUserId         = $editUserId;
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
                <label class="form-label" for="employer_ids">
                    <i class="bi bi-building me-1"></i>Employer IDs
                    <span class="text-danger">*</span>
                    <span class="small text-muted">(comma / space / newline)</span>
                </label>
                <textarea class="form-control" id="employer_ids" name="employer_ids" rows="4" placeholder="e.g. 1012, 1234, 4567" required><?= esc($formEmployerIdsRaw) ?></textarea>
                <div class="small text-muted mt-1">Mandatory — these employers become visible to the user.</div>
            </div>
            <div class="col-md-12">
                <label class="form-label" for="job_ids">
                    <i class="bi bi-briefcase me-1"></i>Job IDs
                    <span class="small text-muted">(optional — comma / space / newline)</span>
                </label>
                <textarea class="form-control" id="job_ids" name="job_ids" rows="3" placeholder="Leave blank to give the user ALL jobs of the employers above. Populate to restrict the Jobs list to only these specific job_ids."><?= esc($formJobIdsRaw) ?></textarea>
                <div class="small text-muted mt-1">Any job_id here that doesn't belong to an assigned employer is rejected and reported in the preview.</div>
                <div class="small text-info mt-2"><i class="bi bi-info-circle-fill me-1"></i><strong>Append only:</strong> Save adds the entered IDs to the user's existing scope; nothing is ever deleted here. Use the trash icon in the Assignments list below to remove all scope for a user.</div>
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
                        <div class="h4 mb-0 fw-bold text-primary"><?= number_format(count($previewFoundEmployerIds)) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-2 text-center bg-light-subtle">
                        <div class="small text-muted"><?= $previewFoundJobIds === [] && $previewMissingJobIds === [] && $previewOutOfScopeJobIds === [] ? 'Jobs (all of assigned employers)' : 'Jobs matched (in scope)' ?></div>
                        <div class="h4 mb-0 fw-bold text-primary"><?= number_format($previewFoundJobIds !== [] ? count($previewFoundJobIds) : $previewJobCount) ?></div>
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
                        <div class="small text-muted">Rejected IDs</div>
                        <div class="h4 mb-0 fw-bold text-warning"><?= number_format(count($previewMissingEmployerIds) + count($previewMissingJobIds) + count($previewOutOfScopeJobIds)) ?></div>
                    </div>
                </div>
            </div>
            <?php if ($previewMissingEmployerIds !== []): ?>
                <div class="alert alert-warning mt-3 mb-0 small">
                    <strong>Employer IDs not in Employers table:</strong>
                    <?= esc(implode(', ', array_slice($previewMissingEmployerIds, 0, 100))) ?>
                    <?= count($previewMissingEmployerIds) > 100 ? ' …' : '' ?>
                </div>
            <?php endif; ?>
            <?php if ($previewMissingJobIds !== []): ?>
                <div class="alert alert-warning mt-3 mb-0 small">
                    <strong>Job IDs not in Employer Jobs table:</strong>
                    <?= esc(implode(', ', array_slice($previewMissingJobIds, 0, 100))) ?>
                    <?= count($previewMissingJobIds) > 100 ? ' …' : '' ?>
                </div>
            <?php endif; ?>
            <?php if ($previewOutOfScopeJobIds !== []): ?>
                <div class="alert alert-danger mt-3 mb-0 small">
                    <strong>Job IDs not owned by the assigned employers (rejected):</strong>
                    <?= esc(implode(', ', array_slice($previewOutOfScopeJobIds, 0, 100))) ?>
                    <?= count($previewOutOfScopeJobIds) > 100 ? ' …' : '' ?>
                </div>
            <?php endif; ?>
            <?php if ($previewFoundEmployerIds !== []): ?>
                <form method="post" class="mt-3" onsubmit="return confirm('Save this assignment? Any prior assignments for this user will be KEPT — only the new IDs entered above are added. Duplicates are silently skipped.');">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="user_id" value="<?= (int) $formUserId ?>">
                    <input type="hidden" name="employer_ids" value="<?= esc($formEmployerIdsRaw) ?>">
                    <input type="hidden" name="job_ids" value="<?= esc($formJobIdsRaw) ?>">
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
                            <div class="d-inline-flex gap-1 align-items-center">
                                <a class="btn btn-sm btn-outline-primary position-relative" href="/demand_side_assignments.php?user_id=<?= (int) $ar['user_id'] ?>#employer_ids" title="Edit — Employer scope"><i class="bi bi-building"></i>
                                    <?php if ((int) $ar['employer_count'] > 0): ?>
                                        <span class="badge bg-primary ms-1"><?= number_format((int) $ar['employer_count']) ?></span>
                                    <?php endif; ?>
                                </a>
                                <a class="btn btn-sm btn-outline-primary" href="/demand_side_assignments.php?user_id=<?= (int) $ar['user_id'] ?>#job_ids" title="Edit — Job scope"><i class="bi bi-briefcase"></i>
                                    <?php if ((int) $ar['job_count'] > 0): ?>
                                        <span class="badge bg-primary ms-1"><?= number_format((int) $ar['job_count']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary ms-1">0</span>
                                    <?php endif; ?>
                                </a>
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

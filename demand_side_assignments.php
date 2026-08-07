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
$action = (string) ($_POST['action'] ?? '');
$submittedIds = (string) ($_POST['employer_ids'] ?? '');

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
    if ($formUserId <= 0) {
        $flashMessage = 'Please select a user.';
        $flashType = 'danger';
    } elseif ($parsedIds === []) {
        $flashMessage = 'Enter at least one numeric employer_id.';
        $flashType = 'danger';
    } else {
        // Match parsed IDs against demand_employers (via the emp_id -> employer_id link)
        // to compute the preview totals and the "not found" set.
        $placeholders = implode(',', array_fill(0, count($parsedIds), '?'));
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
        $previewLoaded = true;

        if ($action === 'save') {
            // Wipe existing rows for this user, then re-insert the resolved
            // set. Simple full-rewrite makes edits idempotent.
            db()->prepare('DELETE FROM demand_user_employer_assignments WHERE user_id = ?')->execute([$formUserId]);
            if ($previewFoundIds !== []) {
                $ins = db()->prepare('INSERT INTO demand_user_employer_assignments (user_id, employer_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())');
                foreach ($previewFoundIds as $eid) {
                    try { $ins->execute([$formUserId, $eid, $userId]); } catch (Throwable $e) { /* dup or FK issue — skip */ }
                }
            }
            $flashMessage = sprintf(
                'Saved: %d employer(s) assigned to user %d (%d skipped as not found).',
                count($previewFoundIds), $formUserId, count($previewMissingIds)
            );
            // Reset the edit context so the user goes back to the fresh list.
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
        $flashMessage = 'Removed ' . $del->affectedRows() . ' assignment(s) for user ' . $delUserId . '.';
    }
    $editUserId = 0;
} elseif ($editUserId > 0 && !is_post()) {
    // Prefill the form for an existing user's assignments.
    $existing = demand_get_assigned_employer_ids($editUserId);
    $formIdsRaw = implode(', ', $existing);
    $formUserId = $editUserId;
}

/* User list for the dropdown. */
$userOptions = db()->query("SELECT id, name, role FROM users WHERE active_status = 1 AND role <> 'administrator' ORDER BY name ASC")->fetchAll();

/* Existing assignments summary. */
$summarySql = "SELECT
        u.id AS user_id,
        u.name AS user_name,
        u.role AS user_role,
        COUNT(DISTINCT a.employer_id) AS employer_count,
        GROUP_CONCAT(DISTINCT a.employer_id ORDER BY a.employer_id) AS employer_ids,
        COALESCE(SUM(j.jobs_count), 0) AS jobs_count,
        COALESCE(SUM(j.total_open_positions), 0) AS total_open_positions
    FROM demand_user_employer_assignments a
    INNER JOIN users u ON u.id = a.user_id
    INNER JOIN demand_employers e ON e.employer_id = a.employer_id
    LEFT JOIN (
        SELECT emp_id, COUNT(*) AS jobs_count, SUM(open_positions) AS total_open_positions
        FROM demand_employer_jobs
        GROUP BY emp_id
    ) j ON j.emp_id = a.employer_id
    GROUP BY u.id, u.name, u.role
    ORDER BY u.name ASC";
$assignmentRows = db()->query($summarySql)->fetchAll();

render_header('Assign Employers to Users', ['main_container_class' => 'container-fluid']);
render_page_header('Demand Side · Assign Employers to Users', [
    'icon' => 'bi-people-arrows',
    'subtitle' => 'Restrict what the Employer listing shows for a given user. Administrators always see all employers.',
    'actions' => '<a class="btn btn-light" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>',
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
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="preview">
            <div class="col-md-4">
                <label class="form-label">User</label>
                <select class="form-select" name="user_id" required <?= $editUserId > 0 ? 'disabled' : '' ?>>
                    <option value="">Select user…</option>
                    <?php foreach ($userOptions as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $formUserId ? 'selected' : '' ?>>
                            <?= esc((string) $u['name']) ?> · <?= esc(role_label((string) $u['role'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($editUserId > 0): ?>
                    <input type="hidden" name="user_id" value="<?= $editUserId ?>">
                    <div class="small text-muted mt-1">Locked — editing an existing assignment. <a href="/demand_side_assignments.php">Start new</a>.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-8">
                <label class="form-label">Employer IDs <span class="small text-muted">(comma / space / newline separated)</span></label>
                <textarea class="form-control" name="employer_ids" rows="4" placeholder="e.g. 1012, 1234, 4567&#10;1890 1901 2001"><?= esc($formIdsRaw) ?></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Preview totals</button>
                <a class="btn btn-light" href="/demand_side_assignments.php">Reset form</a>
            </div>
        </form>

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
                <form method="post" class="mt-3" onsubmit="return confirm('Save this assignment? Existing assignments for the selected user will be replaced.');">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="user_id" value="<?= (int) $formUserId ?>">
                    <input type="hidden" name="employer_ids" value="<?= esc($formIdsRaw) ?>">
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
                    <th class="text-end">Employers</th>
                    <th class="text-end">Jobs</th>
                    <th class="text-end">Open Positions</th>
                    <th>Assigned Employer IDs</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($assignmentRows === []): ?>
                    <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No employer assignments yet. Assigning employers to a user filters their Employer listing to that set.</div></td></tr>
                <?php endif; ?>
                <?php $idx = 1; foreach ($assignmentRows as $ar): ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td class="fw-semibold"><?= esc((string) $ar['user_name']) ?></td>
                        <td><span class="status-chip status-neutral"><?= esc(role_label((string) $ar['user_role'])) ?></span></td>
                        <td class="text-end fw-bold"><?= number_format((int) $ar['employer_count']) ?></td>
                        <td class="text-end fw-bold"><?= number_format((int) $ar['jobs_count']) ?></td>
                        <td class="text-end fw-bold"><?= number_format((int) $ar['total_open_positions']) ?></td>
                        <td class="small text-muted" style="max-width:380px;">
                            <div class="text-truncate" title="<?= esc((string) $ar['employer_ids']) ?>"><?= esc((string) $ar['employer_ids']) ?></div>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a class="btn btn-sm btn-outline-primary" href="/demand_side_assignments.php?user_id=<?= (int) $ar['user_id'] ?>"><i class="bi bi-pencil-square"></i> Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Remove all employer assignments for <?= esc((string) $ar['user_name']) ?>?');">
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

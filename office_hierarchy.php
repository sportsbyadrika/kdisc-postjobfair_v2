<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/office_hierarchy_helpers.php';
require_auth();

$viewer = current_user() ?? [];
if (!is_manage_admin($viewer)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only Administrator and DSM Admin can manage Office Hierarchy.</div>';
    render_footer();
    exit;
}
office_hierarchy_bootstrap();

$viewerId = (int) $viewer['id'];

$flashMessage = null;
$flashType    = 'success';

$nodeId = (int) ($_GET['node'] ?? $_POST['node_id'] ?? 0);
$node   = $nodeId > 0 ? office_hierarchy_get_node($nodeId) : null;
if ($nodeId > 0 && $node === null) {
    // Stale ID — fall back to root.
    $nodeId = 0;
}

/* -------------------------------------------------------------------- *
 * POST handlers
 *
 * Every successful mutation redirects (Post-Redirect-Get) so the URL
 * carries the clean state (no ?form=add stuck on the URL) and a browser
 * refresh doesn't re-submit the form. The redirect target is the newly-
 * created / edited node so the operator lands on their record.
 *
 * The flash message survives the redirect via the session.
 * -------------------------------------------------------------------- */
$action = (string) ($_POST['action'] ?? '');

$sendFlashAndRedirect = static function (string $url, string $msg, string $type = 'success'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['office_hierarchy_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: ' . $url);
    exit;
};

// Pick up a flash left by a previous PRG cycle.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!empty($_SESSION['office_hierarchy_flash']) && is_array($_SESSION['office_hierarchy_flash'])) {
    $flashMessage = (string) ($_SESSION['office_hierarchy_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['office_hierarchy_flash']['type'] ?? 'success');
    unset($_SESSION['office_hierarchy_flash']);
}

if (is_post() && $action === 'save') {
    // Save (INSERT or UPDATE) a node. Parent is implied by the current
    // selection: a "save" from the root list creates an Office; from an
    // Office it creates a Division; and so on. If we're editing an
    // existing node, the id > 0 branch UPDATEs in place.
    $editId       = (int) ($_POST['edit_id'] ?? 0);
    $parentId     = (int) ($_POST['parent_id'] ?? 0);
    $levelType    = (string) ($_POST['level_type'] ?? '');
    $name         = trim((string) ($_POST['name'] ?? ''));
    $details      = trim((string) ($_POST['details'] ?? ''));
    $location     = trim((string) ($_POST['location'] ?? ''));
    $seatNumber   = trim((string) ($_POST['seat_number'] ?? ''));
    $designation  = trim((string) ($_POST['designation'] ?? ''));
    $sortOrder    = (int) ($_POST['sort_order'] ?? 0);

    if (!in_array($levelType, ['office', 'division', 'section', 'seat'], true)) {
        $flashMessage = 'Invalid level type.';
        $flashType = 'danger';
    } elseif ($name === '') {
        $flashMessage = 'Name is required.';
        $flashType = 'danger';
    } else {
        try {
            if ($editId > 0) {
                $u = db()->prepare('UPDATE office_hierarchy_nodes
                    SET name = ?, details = ?, location = ?, seat_number = ?, designation = ?,
                        sort_order = ?, updated_at = NOW(), updated_by = ?
                    WHERE id = ?');
                $u->execute([
                    $name, $details === '' ? null : $details, $location === '' ? null : $location,
                    $seatNumber === '' ? null : $seatNumber, $designation === '' ? null : $designation,
                    $sortOrder, $viewerId, $editId,
                ]);
                $sendFlashAndRedirect(
                    '/office_hierarchy.php?node=' . $editId,
                    office_hierarchy_level_label($levelType) . ' updated.'
                );
            } else {
                $ins = db()->prepare('INSERT INTO office_hierarchy_nodes
                    (parent_id, level_type, name, details, location, seat_number, designation,
                     sort_order, active_status, created_at, updated_at, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), ?, ?)');
                $ins->execute([
                    $parentId > 0 ? $parentId : null,
                    $levelType, $name,
                    $details === '' ? null : $details,
                    $location === '' ? null : $location,
                    $seatNumber === '' ? null : $seatNumber,
                    $designation === '' ? null : $designation,
                    $sortOrder, $viewerId, $viewerId,
                ]);
                $newId = (int) db()->lastInsertId();
                $sendFlashAndRedirect(
                    '/office_hierarchy.php?node=' . $newId,
                    office_hierarchy_level_label($levelType) . ' added.'
                );
            }
        } catch (Throwable $e) {
            // Surface the actual DB error to the operator instead of
            // silently landing on an empty form — the previous version's
            // catch swallowed the message and the user thought "nothing
            // saved".
            $flashMessage = 'Save failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
} elseif (is_post() && $action === 'toggle_active') {
    $tid = (int) ($_POST['id'] ?? 0);
    if ($tid > 0) {
        try {
            db()->prepare('UPDATE office_hierarchy_nodes
                SET active_status = 1 - active_status, updated_at = NOW(), updated_by = ?
                WHERE id = ?')->execute([$viewerId, $tid]);
            $sendFlashAndRedirect('/office_hierarchy.php?node=' . $tid, 'Status toggled.');
        } catch (Throwable $e) {
            $flashMessage = 'Toggle failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
} elseif (is_post() && $action === 'assign_officer') {
    // Assign a responsible officer. If the node already has one, we log
    // the old assignment as unassigned first, then insert a new active
    // history row. The node's responsible_officer_id / designation
    // reflect the currently-assigned officer.
    $tid           = (int) ($_POST['id'] ?? 0);
    $officerId     = (int) ($_POST['officer_id'] ?? 0);
    $newDesignation = trim((string) ($_POST['designation'] ?? ''));
    $reason         = trim((string) ($_POST['reason'] ?? ''));
    if ($tid <= 0 || $officerId <= 0) {
        $flashMessage = 'Pick an officer before saving.';
        $flashType = 'danger';
    } else {
        $ok = false;
        db()->query('START TRANSACTION');
        try {
            // Snapshot the officer's name at assign time so the history
            // row keeps a readable label even if the user is later
            // renamed or their account is deleted (LEFT JOIN would show
            // NULL otherwise).
            $u = db()->prepare('SELECT name FROM users WHERE id = ?');
            $u->execute([$officerId]);
            $officerName = (string) ($u->fetchColumn() ?: '');

            // Close any open history rows for this node.
            db()->prepare('UPDATE office_hierarchy_officer_history
                SET unassigned_at = NOW(), unassigned_by = ?, unassign_reason = ?
                WHERE node_id = ? AND unassigned_at IS NULL')
                ->execute([$viewerId, 'Replaced by new officer', $tid]);

            // Insert the new active row.
            db()->prepare('INSERT INTO office_hierarchy_officer_history
                (node_id, officer_id, officer_name_snapshot, designation, assigned_at, assigned_by, assign_reason)
                VALUES (?, ?, ?, ?, NOW(), ?, ?)')
                ->execute([$tid, $officerId, $officerName,
                    $newDesignation === '' ? null : $newDesignation,
                    $viewerId, $reason === '' ? null : $reason]);

            // Update the node's canonical current-officer fields.
            db()->prepare('UPDATE office_hierarchy_nodes
                SET responsible_officer_id = ?, designation = ?, updated_at = NOW(), updated_by = ?
                WHERE id = ?')
                ->execute([$officerId, $newDesignation === '' ? null : $newDesignation, $viewerId, $tid]);
            db()->query('COMMIT');
            $ok = true;
        } catch (Throwable $e) {
            db()->query('ROLLBACK');
            $flashMessage = 'Assign failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
        if ($ok) {
            $sendFlashAndRedirect('/office_hierarchy.php?node=' . $tid, 'Officer assigned.');
        }
    }
} elseif (is_post() && $action === 'unassign_officer') {
    $tid    = (int) ($_POST['id'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if ($tid <= 0) {
        $flashMessage = 'Invalid node.';
        $flashType = 'danger';
    } else {
        db()->query('START TRANSACTION');
        try {
            db()->prepare('UPDATE office_hierarchy_officer_history
                SET unassigned_at = NOW(), unassigned_by = ?, unassign_reason = ?
                WHERE node_id = ? AND unassigned_at IS NULL')
                ->execute([$viewerId, $reason === '' ? null : $reason, $tid]);
            db()->prepare('UPDATE office_hierarchy_nodes
                SET responsible_officer_id = NULL, designation = NULL, updated_at = NOW(), updated_by = ?
                WHERE id = ?')
                ->execute([$viewerId, $tid]);
            db()->query('COMMIT');
            $sendFlashAndRedirect('/office_hierarchy.php?node=' . $tid, 'Officer removed. The transfer is logged in the history.');
        } catch (Throwable $e) {
            db()->query('ROLLBACK');
            $flashMessage = 'Unassign failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

/* -------------------------------------------------------------------- *
 * Data for the render.
 * -------------------------------------------------------------------- */
$rootNodes  = office_hierarchy_get_children(null);
$ancestors  = $node ? office_hierarchy_get_ancestors((int) $node['id']) : [];
$children   = $node ? office_hierarchy_get_children((int) $node['id']) : $rootNodes;
$history    = $node ? office_hierarchy_officer_history((int) $node['id']) : [];

// Level of the record we'd create from a "New" click on the right panel.
$parentLevelForNew = $node ? (string) $node['level_type'] : null;
$newLevel = office_hierarchy_child_level($parentLevelForNew);

// Deciding what the right panel shows: view existing (default) OR the
// add / edit form (opened by ?form=add or ?form=edit).
$formMode = (string) ($_GET['form'] ?? '');   // '', 'add', 'edit'
$editingRow = null;
if ($formMode === 'edit' && $node !== null) {
    $editingRow = $node;
} elseif ($formMode === 'add' && $newLevel === null) {
    // Trying to add under a Seat — nothing to add.
    $formMode = '';
}

/* Recursive tree render — highlights the current selection and dims
 * inactive nodes. Kept as a nested closure so the render stays close
 * to the mark-up. */
$renderTree = function (array $nodes, ?int $selectedId) use (&$renderTree): void {
    if ($nodes === []) return;
    echo '<ul class="list-unstyled ms-3 mb-0" style="border-left:1px dashed var(--bs-border-color); padding-left:.5rem;">';
    foreach ($nodes as $n) {
        $id        = (int) $n['id'];
        $isActive  = ((int) $n['active_status']) === 1;
        $isCurrent = $id === $selectedId;
        $level     = (string) $n['level_type'];
        $icon      = office_hierarchy_level_icon($level);
        $labelCls  = 'small' . ($isCurrent ? ' fw-bold text-primary' : '') . ($isActive ? '' : ' text-muted text-decoration-line-through');
        echo '<li class="mb-1">';
        echo '<a href="/office_hierarchy.php?node=' . $id . '" class="' . $labelCls . ' text-decoration-none">';
        echo '<i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . ' me-1"></i>';
        echo htmlspecialchars((string) $n['name'], ENT_QUOTES, 'UTF-8');
        echo '</a>';
        $kids = office_hierarchy_get_children($id);
        if ($kids !== []) {
            $renderTree($kids, $selectedId);
        }
        echo '</li>';
    }
    echo '</ul>';
};

render_header('Administration · Office Hierarchy', ['main_container_class' => 'container-fluid']);
render_page_header('Administration · Office Hierarchy', [
    'icon'     => 'bi-diagram-3',
    'subtitle' => 'Office → Division → Section → Seat structure with a responsible officer and full transfer history on every node.',
    'actions'  => '<a class="btn btn-light" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="row g-3">
    <!-- Left: tree -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-diagram-3 text-primary me-1"></i>Hierarchy</span>
                <a class="btn btn-sm btn-primary" href="/office_hierarchy.php?form=add"><i class="bi bi-plus-lg me-1"></i>New Office</a>
            </div>
            <div class="card-body">
                <?php if ($rootNodes === []): ?>
                    <div class="empty-state small"><i class="bi bi-inbox"></i>No offices yet. Click "New Office" to start the structure.</div>
                <?php else: ?>
                    <a class="small text-decoration-none <?= $nodeId === 0 ? 'fw-bold text-primary' : '' ?>" href="/office_hierarchy.php">
                        <i class="bi bi-diagram-3 me-1"></i>All offices
                    </a>
                    <?php $renderTree($rootNodes, $nodeId); ?>
                <?php endif; ?>
            </div>
            <div class="card-footer small text-muted">
                <i class="bi bi-info-circle me-1"></i>Deactivated nodes appear <span class="text-decoration-line-through">struck through</span>. Click a node to open its details on the right.
            </div>
        </div>
    </div>

    <!-- Right: detail + list + form -->
    <div class="col-lg-8">
        <?php if ($formMode === 'add' || $formMode === 'edit'): ?>
            <?php
                if ($formMode === 'edit') {
                    $formLevel  = (string) $node['level_type'];
                    $formParent = (int) ($node['parent_id'] ?? 0);
                    $formTitle  = 'Edit ' . office_hierarchy_level_label($formLevel);
                } else {
                    $formLevel  = $newLevel ?? 'office';
                    $formParent = $node ? (int) $node['id'] : 0;
                    $formTitle  = 'Add ' . office_hierarchy_level_label($formLevel)
                        . ($node ? ' under "' . esc((string) $node['name']) . '"' : '');
                }
            ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-pencil-square text-primary me-1"></i><?= $formTitle ?></span>
                    <a class="btn btn-sm btn-light" href="/office_hierarchy.php?node=<?= (int) $nodeId ?>">Cancel</a>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="edit_id" value="<?= (int) ($editingRow['id'] ?? 0) ?>">
                        <input type="hidden" name="parent_id" value="<?= (int) $formParent ?>">
                        <input type="hidden" name="level_type" value="<?= esc($formLevel) ?>">
                        <div class="col-md-6">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required value="<?= esc((string) ($editingRow['name'] ?? '')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sort order</label>
                            <input type="number" class="form-control" name="sort_order" value="<?= (int) ($editingRow['sort_order'] ?? 0) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Level</label>
                            <input type="text" class="form-control" readonly value="<?= esc(office_hierarchy_level_label($formLevel)) ?>">
                        </div>
                        <?php if ($formLevel === 'seat'): ?>
                            <div class="col-md-6">
                                <label class="form-label">Seat number / label</label>
                                <input type="text" class="form-control" name="seat_number" value="<?= esc((string) ($editingRow['seat_number'] ?? '')) ?>">
                            </div>
                        <?php endif; ?>
                        <div class="col-md-<?= $formLevel === 'seat' ? '6' : '12' ?>">
                            <label class="form-label"><?= $formLevel === 'office' ? 'Location' : 'Location / Building' ?></label>
                            <input type="text" class="form-control" name="location" value="<?= esc((string) ($editingRow['location'] ?? '')) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Details</label>
                            <textarea class="form-control" name="details" rows="2"><?= esc((string) ($editingRow['details'] ?? '')) ?></textarea>
                        </div>
                        <div class="col-12 d-flex justify-content-end gap-2">
                            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?= $editingRow ? 'Save changes' : 'Add' ?></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <?php if ($node !== null): ?>
                <?php $childLevel = office_hierarchy_child_level((string) $node['level_type']); ?>
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>
                            <i class="bi <?= esc(office_hierarchy_level_icon((string) $node['level_type'])) ?> text-primary me-1"></i>
                            <?= esc((string) $node['name']) ?>
                            <span class="badge text-bg-light border ms-1"><?= esc(office_hierarchy_level_label((string) $node['level_type'])) ?></span>
                            <?php if ((int) $node['active_status'] === 0): ?>
                                <span class="badge text-bg-secondary ms-1">Inactive</span>
                            <?php endif; ?>
                        </span>
                        <div class="d-flex gap-1">
                            <a class="btn btn-sm btn-outline-primary" href="/office_hierarchy.php?node=<?= (int) $node['id'] ?>&form=edit"><i class="bi bi-pencil me-1"></i>Edit</a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Toggle active status for this record?');">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= (int) $node['id'] ?>">
                                <button class="btn btn-sm <?= (int) $node['active_status'] === 1 ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                    <i class="bi <?= (int) $node['active_status'] === 1 ? 'bi-slash-circle' : 'bi-check2-circle' ?> me-1"></i>
                                    <?= (int) $node['active_status'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </form>
                            <?php if ($childLevel !== null): ?>
                                <a class="btn btn-sm btn-primary" href="/office_hierarchy.php?node=<?= (int) $node['id'] ?>&form=add"><i class="bi bi-plus-lg me-1"></i>Add <?= esc(office_hierarchy_level_label($childLevel)) ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($ancestors !== []): ?>
                            <nav aria-label="breadcrumb" class="mb-2 small">
                                <ol class="breadcrumb small mb-0">
                                    <li class="breadcrumb-item"><a href="/office_hierarchy.php">All</a></li>
                                    <?php foreach ($ancestors as $a): ?>
                                        <li class="breadcrumb-item"><a href="/office_hierarchy.php?node=<?= (int) $a['id'] ?>"><?= esc((string) $a['name']) ?></a></li>
                                    <?php endforeach; ?>
                                    <li class="breadcrumb-item active" aria-current="page"><?= esc((string) $node['name']) ?></li>
                                </ol>
                            </nav>
                        <?php endif; ?>
                        <div class="row g-3">
                            <div class="col-md-4"><div class="small text-muted">Level</div><div class="fw-semibold"><?= esc(office_hierarchy_level_label((string) $node['level_type'])) ?></div></div>
                            <div class="col-md-4"><div class="small text-muted">Location<?= (string) $node['level_type'] === 'office' ? '' : ' / Building' ?></div><div class="fw-semibold"><?= esc((string) ($node['location'] ?? '')) ?: '—' ?></div></div>
                            <?php if ((string) $node['level_type'] === 'seat'): ?>
                                <div class="col-md-4"><div class="small text-muted">Seat number</div><div class="fw-semibold"><?= esc((string) ($node['seat_number'] ?? '')) ?: '—' ?></div></div>
                            <?php endif; ?>
                            <div class="col-md-4"><div class="small text-muted">Responsible Officer</div>
                                <div class="fw-semibold"><?= esc((string) ($node['officer_name'] ?? '')) ?: '<span class="text-muted">— none —</span>' ?></div>
                                <?php if (!empty($node['designation'])): ?>
                                    <div class="small text-muted"><?= esc((string) $node['designation']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4"><div class="small text-muted">Status</div>
                                <?php if ((int) $node['active_status'] === 1): ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-12"><div class="small text-muted">Details</div><div><?= nl2br(esc((string) ($node['details'] ?? ''))) ?: '<span class="text-muted">—</span>' ?></div></div>
                        </div>

                        <hr>
                        <h6 class="mt-3">Responsible Officer</h6>
                        <form method="post" class="row g-2 align-items-end">
                            <input type="hidden" name="action" value="assign_officer">
                            <input type="hidden" name="id" value="<?= (int) $node['id'] ?>">
                            <input type="hidden" name="officer_id" id="pickedOfficerId" value="<?= (int) ($node['responsible_officer_id'] ?? 0) ?>">
                            <div class="col-md-5">
                                <label class="form-label">Officer</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="pickedOfficerName" readonly value="<?= esc((string) ($node['officer_name'] ?? '')) ?>">
                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#officerPickerModal"><i class="bi bi-search me-1"></i>Search</button>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Designation</label>
                                <input type="text" class="form-control" name="designation" value="<?= esc((string) ($node['designation'] ?? '')) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Reason / note</label>
                                <input type="text" class="form-control" name="reason" placeholder="Optional">
                            </div>
                            <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                                <?php if (!empty($node['responsible_officer_id'])): ?>
                                    <button type="submit" formaction="/office_hierarchy.php?node=<?= (int) $node['id'] ?>" formmethod="post"
                                            name="action" value="unassign_officer"
                                            class="btn btn-outline-warning"
                                            onclick="return confirm('Remove the current officer? The transfer is logged in the history.');">
                                        <i class="bi bi-person-dash me-1"></i>Remove current officer
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-primary"><i class="bi bi-person-check me-1"></i>Assign / Update Officer</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-clock-history text-primary me-1"></i>Officer Transfer History</span>
                        <span class="status-chip status-info"><?= number_format(count($history)) ?> event<?= count($history) === 1 ? '' : 's' ?></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr>
                                <th>Sl No</th><th>Officer</th><th>Designation</th><th>Assigned</th><th>Unassigned</th><th>Notes</th>
                            </tr></thead>
                            <tbody>
                                <?php if ($history === []): ?>
                                    <tr><td colspan="6"><div class="empty-state"><i class="bi bi-clock"></i>No transfer events yet.</div></td></tr>
                                <?php endif; ?>
                                <?php $i = 1; foreach ($history as $h): ?>
                                    <?php $current = $h['unassigned_at'] === null; ?>
                                    <tr class="<?= $current ? 'table-primary' : '' ?>">
                                        <td><?= $i++ ?></td>
                                        <td class="fw-semibold">
                                            <?= esc((string) ($h['officer_name_snapshot'] ?? '')) ?>
                                            <?php if ($current): ?><span class="badge text-bg-primary ms-1">Current</span><?php endif; ?>
                                        </td>
                                        <td><?= esc((string) ($h['designation'] ?? '')) ?></td>
                                        <td class="small">
                                            <?= esc((string) $h['assigned_at']) ?>
                                            <?php if (!empty($h['assigned_by_name'])): ?><div class="small text-muted">by <?= esc((string) $h['assigned_by_name']) ?></div><?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <?= $current ? '<span class="text-muted">—</span>' : esc((string) $h['unassigned_at']) ?>
                                            <?php if (!$current && !empty($h['unassigned_by_name'])): ?><div class="small text-muted">by <?= esc((string) $h['unassigned_by_name']) ?></div><?php endif; ?>
                                        </td>
                                        <td class="small text-muted">
                                            <?php if (!empty($h['assign_reason'])): ?><div><em>Assign:</em> <?= esc((string) $h['assign_reason']) ?></div><?php endif; ?>
                                            <?php if (!empty($h['unassign_reason'])): ?><div><em>Unassign:</em> <?= esc((string) $h['unassign_reason']) ?></div><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-list-ul text-primary me-1"></i>
                        <?= $node ? ($newLevel ? esc(office_hierarchy_level_label($newLevel)) . ' list' : 'Children') : 'All Offices' ?>
                    </span>
                    <?php if ($newLevel !== null): ?>
                        <a class="btn btn-sm btn-primary" href="/office_hierarchy.php?node=<?= (int) $nodeId ?>&form=add"><i class="bi bi-plus-lg me-1"></i>Add <?= esc(office_hierarchy_level_label($newLevel)) ?></a>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Sl No</th><th>Name</th><th>Level</th><th>Location</th><th>Officer</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <?php if ($children === []): ?>
                                <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No records under this node yet.</div></td></tr>
                            <?php endif; ?>
                            <?php $i = 1; foreach ($children as $c): ?>
                                <?php $isActive = ((int) $c['active_status']) === 1; ?>
                                <tr class="<?= $isActive ? '' : 'text-muted' ?>">
                                    <td><?= $i++ ?></td>
                                    <td class="fw-semibold"><a href="/office_hierarchy.php?node=<?= (int) $c['id'] ?>" class="text-decoration-none"><?= esc((string) $c['name']) ?></a></td>
                                    <td><span class="badge text-bg-light border"><?= esc(office_hierarchy_level_label((string) $c['level_type'])) ?></span></td>
                                    <td class="small"><?= esc((string) ($c['location'] ?? '')) ?: '—' ?></td>
                                    <td class="small"><?= esc((string) ($c['officer_name'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                                    <td>
                                        <?php if ($isActive): ?><span class="badge text-bg-success">Active</span><?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="/office_hierarchy.php?node=<?= (int) $c['id'] ?>&form=edit"><i class="bi bi-pencil"></i></a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Toggle status?');">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                            <button class="btn btn-sm <?= $isActive ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                                <i class="bi <?= $isActive ? 'bi-slash-circle' : 'bi-check2-circle' ?>"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Officer picker modal -->
<div class="modal fade" id="officerPickerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-lines-fill me-1"></i>Search User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-3">
                    <input type="text" class="form-control" id="officerSearchInput" placeholder="Name, email or mobile…">
                    <button class="btn btn-primary" id="officerSearchBtn" type="button"><i class="bi bi-search"></i></button>
                </div>
                <div class="table-responsive" style="max-height:60vh; overflow-y:auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light" style="position:sticky; top:0;">
                            <tr><th>Name</th><th>Role</th><th>Contact</th><th></th></tr>
                        </thead>
                        <tbody id="officerSearchBody">
                            <tr><td colspan="4" class="text-center text-muted py-3"><i class="bi bi-search me-1"></i>Type a name or contact and press Enter.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const input = document.getElementById('officerSearchInput');
    const btn   = document.getElementById('officerSearchBtn');
    const body  = document.getElementById('officerSearchBody');
    const modalEl = document.getElementById('officerPickerModal');
    if (!input || !btn || !body || !modalEl) return;

    const search = async () => {
        const q = input.value.trim();
        body.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm me-1"></span>Loading&hellip;</td></tr>';
        try {
            const url = '/office_hierarchy_ajax_users.php?q=' + encodeURIComponent(q);
            const res = await fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}});
            body.innerHTML = res.ok ? await res.text() : '<tr><td colspan="4" class="text-danger">HTTP ' + res.status + '</td></tr>';
        } catch (err) {
            body.innerHTML = '<tr><td colspan="4" class="text-danger">' + String(err) + '</td></tr>';
        }
    };
    btn.addEventListener('click', search);
    input.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); search(); } });
    // Pre-load a short list on modal open so the operator sees users immediately.
    modalEl.addEventListener('shown.bs.modal', () => { input.focus(); search(); });

    // Delegated click on a Select button — sets the hidden officer_id and
    // display input, then closes the modal.
    body.addEventListener('click', (ev) => {
        const t = ev.target.closest('.js-pick-officer');
        if (!t) return;
        const id = t.getAttribute('data-id');
        const nm = t.getAttribute('data-name');
        const idInput = document.getElementById('pickedOfficerId');
        const nmInput = document.getElementById('pickedOfficerName');
        if (idInput) idInput.value = id;
        if (nmInput) nmInput.value = nm;
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
    });
})();
</script>

<?php render_footer(); ?>

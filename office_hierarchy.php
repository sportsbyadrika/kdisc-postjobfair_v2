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
    $nodeId = 0;
}

/* -------------------------------------------------------------------- *
 * POST handlers — same as before (PRG on every successful mutation).
 * -------------------------------------------------------------------- */
$action = (string) ($_POST['action'] ?? '');

$sendFlashAndRedirect = static function (string $url, string $msg, string $type = 'success'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['office_hierarchy_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: ' . $url);
    exit;
};

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!empty($_SESSION['office_hierarchy_flash']) && is_array($_SESSION['office_hierarchy_flash'])) {
    $flashMessage = (string) ($_SESSION['office_hierarchy_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['office_hierarchy_flash']['type'] ?? 'success');
    unset($_SESSION['office_hierarchy_flash']);
}

if (is_post() && $action === 'save') {
    $editId       = (int) ($_POST['edit_id'] ?? 0);
    $parentId     = (int) ($_POST['parent_id'] ?? 0);
    $levelType    = (string) ($_POST['level_type'] ?? '');
    $name         = trim((string) ($_POST['name'] ?? ''));
    $details      = trim((string) ($_POST['details'] ?? ''));
    $location     = trim((string) ($_POST['location'] ?? ''));
    $seatNumber   = trim((string) ($_POST['seat_number'] ?? ''));
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
                    SET name = ?, details = ?, location = ?, seat_number = ?,
                        sort_order = ?, updated_at = NOW(), updated_by = ?
                    WHERE id = ?');
                $u->execute([
                    $name, $details === '' ? null : $details, $location === '' ? null : $location,
                    $seatNumber === '' ? null : $seatNumber,
                    $sortOrder, $viewerId, $editId,
                ]);
                $sendFlashAndRedirect(
                    '/office_hierarchy.php?node=' . $editId,
                    office_hierarchy_level_label($levelType) . ' updated.'
                );
            } else {
                $ins = db()->prepare('INSERT INTO office_hierarchy_nodes
                    (parent_id, level_type, name, details, location, seat_number,
                     sort_order, active_status, created_at, updated_at, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), ?, ?)');
                $ins->execute([
                    $parentId > 0 ? $parentId : null,
                    $levelType, $name,
                    $details === '' ? null : $details,
                    $location === '' ? null : $location,
                    $seatNumber === '' ? null : $seatNumber,
                    $sortOrder, $viewerId, $viewerId,
                ]);
                $newId = (int) db()->lastInsertId();
                // For a root Office we redirect to root (no node param) so
                // the operator sees the office in the tree AND the offices
                // list. For a child, we redirect to the parent so its
                // children table shows the new row.
                $target = $parentId > 0 ? ('/office_hierarchy.php?node=' . $parentId) : '/office_hierarchy.php';
                $sendFlashAndRedirect(
                    $target,
                    office_hierarchy_level_label($levelType) . ' added.'
                );
            }
        } catch (Throwable $e) {
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
            // Land back on the parent so the toggled row stays visible in
            // its children table.
            $row = office_hierarchy_get_node($tid);
            $parentTarget = $row && !empty($row['parent_id']) ? ('/office_hierarchy.php?node=' . (int) $row['parent_id']) : '/office_hierarchy.php';
            $sendFlashAndRedirect($parentTarget, 'Status toggled.');
        } catch (Throwable $e) {
            $flashMessage = 'Toggle failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
} elseif (is_post() && $action === 'assign_officer') {
    $tid            = (int) ($_POST['id'] ?? 0);
    $officerId      = (int) ($_POST['officer_id'] ?? 0);
    $newDesignation = trim((string) ($_POST['designation'] ?? ''));
    $reason         = trim((string) ($_POST['reason'] ?? ''));
    if ($tid <= 0 || $officerId <= 0) {
        $flashMessage = 'Pick an officer before saving.';
        $flashType = 'danger';
    } else {
        $ok = false;
        db()->query('START TRANSACTION');
        try {
            $u = db()->prepare('SELECT name FROM users WHERE id = ?');
            $u->execute([$officerId]);
            $officerName = (string) ($u->fetchColumn() ?: '');
            db()->prepare('UPDATE office_hierarchy_officer_history
                SET unassigned_at = NOW(), unassigned_by = ?, unassign_reason = ?
                WHERE node_id = ? AND unassigned_at IS NULL')
                ->execute([$viewerId, 'Replaced by new officer', $tid]);
            db()->prepare('INSERT INTO office_hierarchy_officer_history
                (node_id, officer_id, officer_name_snapshot, designation, assigned_at, assigned_by, assign_reason)
                VALUES (?, ?, ?, ?, NOW(), ?, ?)')
                ->execute([$tid, $officerId, $officerName,
                    $newDesignation === '' ? null : $newDesignation,
                    $viewerId, $reason === '' ? null : $reason]);
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

// Level of the record we'd create from a "New" button click.
$parentLevelForNew = $node ? (string) $node['level_type'] : null;
$newLevel = office_hierarchy_child_level($parentLevelForNew);

/* -------------------------------------------------------------------- *
 * Encode every node into a JSON blob the modal JS can hydrate from —
 * avoids an AJAX round-trip on Edit / View. Officer history is only
 * fetched on-demand when the View modal opens.
 * -------------------------------------------------------------------- */
$rowPayload = static function (array $r): string {
    return htmlspecialchars(json_encode([
        'id'                     => (int) $r['id'],
        'parent_id'              => (int) ($r['parent_id'] ?? 0),
        'level_type'             => (string) $r['level_type'],
        'name'                   => (string) $r['name'],
        'details'                => (string) ($r['details'] ?? ''),
        'location'               => (string) ($r['location'] ?? ''),
        'seat_number'            => (string) ($r['seat_number'] ?? ''),
        'designation'            => (string) ($r['designation'] ?? ''),
        'responsible_officer_id' => (int) ($r['responsible_officer_id'] ?? 0),
        'officer_name'           => (string) ($r['officer_name'] ?? ''),
        'active_status'          => (int) $r['active_status'],
        'sort_order'             => (int) $r['sort_order'],
        'updated_at'             => (string) ($r['updated_at'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
};

/* Recursive tree render — kept the same, clicking a node updates the
 * URL to select it and refresh the right-side table. */
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
    <!-- Column A — hierarchy tree -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-diagram-3 text-primary me-1"></i>Hierarchy</span>
                <button type="button" class="btn btn-sm btn-primary js-new-node"
                        data-parent-id="0"
                        data-parent-name=""
                        data-child-level="office"
                        data-bs-toggle="modal" data-bs-target="#editModal">
                    <i class="bi bi-plus-lg me-1"></i>New Office
                </button>
            </div>
            <div class="card-body">
                <?php if ($rootNodes === []): ?>
                    <div class="empty-state small"><i class="bi bi-inbox"></i>No offices yet. Click "New Office" to start.</div>
                <?php else: ?>
                    <div>
                        <a class="small text-decoration-none <?= $nodeId === 0 ? 'fw-bold text-primary' : '' ?>" href="/office_hierarchy.php">
                            <i class="bi bi-diagram-3 me-1"></i>All offices
                        </a>
                    </div>
                    <?php $renderTree($rootNodes, $nodeId); ?>
                <?php endif; ?>
            </div>
            <div class="card-footer small text-muted">
                <i class="bi bi-info-circle me-1"></i>Click a node to load its children on the right. Deactivated nodes appear <span class="text-decoration-line-through">struck through</span>.
            </div>
        </div>
    </div>

    <!-- Column B — records table for the selected node -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>
                    <?php if ($node !== null): ?>
                        <i class="bi <?= esc(office_hierarchy_level_icon((string) $node['level_type'])) ?> text-primary me-1"></i>
                        Records under <strong><?= esc((string) $node['name']) ?></strong>
                    <?php else: ?>
                        <i class="bi bi-list-ul text-primary me-1"></i>All Offices
                    <?php endif; ?>
                </span>
                <?php if ($newLevel !== null): ?>
                    <button type="button" class="btn btn-sm btn-primary js-new-node"
                            data-parent-id="<?= (int) $nodeId ?>"
                            data-parent-name="<?= esc($node ? (string) $node['name'] : '') ?>"
                            data-child-level="<?= esc($newLevel) ?>"
                            data-bs-toggle="modal" data-bs-target="#editModal">
                        <i class="bi bi-plus-lg me-1"></i>New <?= esc(office_hierarchy_level_label($newLevel)) ?>
                    </button>
                <?php endif; ?>
            </div>
            <?php if ($node !== null && $ancestors !== []): ?>
                <div class="card-body py-2 border-bottom">
                    <nav class="mb-0"><ol class="breadcrumb small mb-0">
                        <li class="breadcrumb-item"><a href="/office_hierarchy.php">All</a></li>
                        <?php foreach ($ancestors as $a): ?>
                            <li class="breadcrumb-item"><a href="/office_hierarchy.php?node=<?= (int) $a['id'] ?>"><?= esc((string) $a['name']) ?></a></li>
                        <?php endforeach; ?>
                        <li class="breadcrumb-item active" aria-current="page"><?= esc((string) $node['name']) ?></li>
                    </ol></nav>
                </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:56px;">Sl no</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            // Show the selected node itself as the first row so
                            // the operator can View / Edit / Deactivate it
                            // without navigating away; then its children below.
                            $rowsForTable = $node !== null ? array_merge([$node], $children) : $children;
                        ?>
                        <?php if ($rowsForTable === []): ?>
                            <tr><td colspan="5"><div class="empty-state"><i class="bi bi-inbox"></i>No records yet — click the "New <?= $node ? esc(office_hierarchy_level_label($newLevel ?? 'record')) : 'Office' ?>" button above.</div></td></tr>
                        <?php endif; ?>
                        <?php $i = 1; foreach ($rowsForTable as $r): ?>
                            <?php
                                $rActive = ((int) ($r['active_status'] ?? 1)) === 1;
                                $isSelf  = $node !== null && (int) $r['id'] === (int) $node['id'];
                                $payload = $rowPayload($r);
                            ?>
                            <tr class="<?= $isSelf ? 'table-primary' : '' ?><?= $rActive ? '' : ' text-muted' ?>">
                                <td><?= $i++ ?></td>
                                <td class="fw-semibold">
                                    <a href="/office_hierarchy.php?node=<?= (int) $r['id'] ?>" class="text-decoration-none">
                                        <i class="bi <?= esc(office_hierarchy_level_icon((string) $r['level_type'])) ?> me-1"></i>
                                        <?= esc((string) $r['name']) ?>
                                        <?php if ($isSelf): ?><span class="badge text-bg-primary ms-1">Selected</span><?php endif; ?>
                                    </a>
                                    <?php if (!empty($r['officer_name'])): ?>
                                        <div class="small text-muted"><i class="bi bi-person-check me-1"></i><?= esc((string) $r['officer_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge text-bg-light border"><?= esc(office_hierarchy_level_label((string) $r['level_type'])) ?></span></td>
                                <td>
                                    <?php if ($rActive): ?>
                                        <span class="badge text-bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-outline-secondary js-view-node"
                                                data-payload="<?= $payload ?>"
                                                data-bs-toggle="modal" data-bs-target="#viewModal"
                                                title="View">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-primary js-edit-node"
                                                data-payload="<?= $payload ?>"
                                                data-bs-toggle="modal" data-bs-target="#editModal"
                                                title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm('<?= $rActive ? 'Deactivate' : 'Reactivate' ?> \'<?= esc((string) $r['name']) ?>\'?');">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                            <button class="btn btn-sm <?= $rActive ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                                    title="<?= $rActive ? 'Deactivate' : 'Reactivate' ?>">
                                                <i class="bi <?= $rActive ? 'bi-trash' : 'bi-arrow-counterclockwise' ?>"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer small text-muted">
                <strong>View</strong> opens details + officer history · <strong>Edit</strong> opens the entry form · <strong>Delete</strong> deactivates the record (no data is lost).
            </div>
        </div>
    </div>
</div>

<!-- ================================================================== -->
<!-- Modal C-1: View — read-only detail + officer + history               -->
<!-- ================================================================== -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewModalTitle"><i class="bi bi-info-circle me-1"></i>Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <div class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Loading&hellip;</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-warning" id="viewModalRemoveOfficer" style="display:none;">
                    <i class="bi bi-person-dash me-1"></i>Remove current officer
                </button>
                <button type="button" class="btn btn-primary" id="viewModalChangeOfficer">
                    <i class="bi bi-person-check me-1"></i>Assign / Change Officer
                </button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ================================================================== -->
<!-- Modal C-2: Edit (also handles Add)                                  -->
<!-- ================================================================== -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="editModalForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="edit_id"    id="editModalEditId"    value="0">
                <input type="hidden" name="parent_id"  id="editModalParentId"  value="0">
                <input type="hidden" name="level_type" id="editModalLevelType" value="office">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalTitle"><i class="bi bi-pencil-square me-1"></i>Add / Edit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Type</label>
                            <input type="text" class="form-control" id="editModalLevelLabel" readonly>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Under</label>
                            <input type="text" class="form-control" id="editModalParentLabel" readonly>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="editModalName">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="editModalName" name="name" required maxlength="255">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="editModalSortOrder">Sort order</label>
                            <input type="number" class="form-control" id="editModalSortOrder" name="sort_order" value="0">
                        </div>
                        <div class="col-md-6" id="editModalLocationWrap">
                            <label class="form-label" for="editModalLocation">Location / Building</label>
                            <input type="text" class="form-control" id="editModalLocation" name="location" maxlength="500">
                        </div>
                        <div class="col-md-6" id="editModalSeatWrap" style="display:none;">
                            <label class="form-label" for="editModalSeatNumber">Seat number / label</label>
                            <input type="text" class="form-control" id="editModalSeatNumber" name="seat_number" maxlength="120">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="editModalDetails">Details</label>
                            <textarea class="form-control" id="editModalDetails" name="details" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================================================================== -->
<!-- Modal C-3: Assign officer (form + picker)                           -->
<!-- ================================================================== -->
<div class="modal fade" id="assignOfficerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="assignOfficerForm">
                <input type="hidden" name="action" value="assign_officer">
                <input type="hidden" name="id"          id="assignOfficerNodeId" value="0">
                <input type="hidden" name="officer_id"  id="assignOfficerId"     value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="assignOfficerTitle"><i class="bi bi-person-check me-1"></i>Assign officer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Officer</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="assignOfficerName" readonly>
                                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#officerPickerModal">
                                    <i class="bi bi-search me-1"></i>Search
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Designation</label>
                            <input type="text" class="form-control" name="designation" id="assignOfficerDesignation" maxlength="255">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reason / note</label>
                            <input type="text" class="form-control" name="reason" id="assignOfficerReason" placeholder="Optional — for the audit log">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-person-check me-1"></i>Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================================================================== -->
<!-- Modal C-4: Officer picker (nested in Assign / used by picker btn)   -->
<!-- ================================================================== -->
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

<!-- ================================================================== -->
<!-- Unassign officer — hidden helper form fired by the View modal's     -->
<!-- Remove-current-officer button (via JS submit).                      -->
<!-- ================================================================== -->
<form id="unassignOfficerForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="unassign_officer">
    <input type="hidden" name="id" id="unassignOfficerNodeId" value="0">
</form>

<script>
(function () {
    const levelLabel = (l) => ({office: 'Office', division: 'Division', section: 'Section', seat: 'Seat'})[l] || l;
    const childLevel = (l) => ({office: 'division', division: 'section', section: 'seat', seat: null})[l ?? null] ?? 'office';

    // -------------------- New / Edit modal --------------------
    const editModalEl   = document.getElementById('editModal');
    const editTitle     = document.getElementById('editModalTitle');
    const editId        = document.getElementById('editModalEditId');
    const editParentId  = document.getElementById('editModalParentId');
    const editLevelType = document.getElementById('editModalLevelType');
    const editLevelLbl  = document.getElementById('editModalLevelLabel');
    const editParentLbl = document.getElementById('editModalParentLabel');
    const editName      = document.getElementById('editModalName');
    const editSortOrder = document.getElementById('editModalSortOrder');
    const editLocation  = document.getElementById('editModalLocation');
    const editSeatWrap  = document.getElementById('editModalSeatWrap');
    const editSeatNo    = document.getElementById('editModalSeatNumber');
    const editDetails   = document.getElementById('editModalDetails');

    const showSeatField = (level) => {
        editSeatWrap.style.display = (level === 'seat') ? '' : 'none';
    };

    editModalEl?.addEventListener('show.bs.modal', (ev) => {
        const trigger = ev.relatedTarget;
        if (!trigger) return;
        if (trigger.classList.contains('js-new-node')) {
            // New
            const parentId    = trigger.getAttribute('data-parent-id')    || '0';
            const parentName  = trigger.getAttribute('data-parent-name')  || '';
            const childLvl    = trigger.getAttribute('data-child-level')  || 'office';
            editTitle.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add ' + levelLabel(childLvl) + (parentName ? ' under ' + parentName : '');
            editId.value        = '0';
            editParentId.value  = parentId;
            editLevelType.value = childLvl;
            editLevelLbl.value  = levelLabel(childLvl);
            editParentLbl.value = parentName || '— (root)';
            editName.value      = '';
            editSortOrder.value = '0';
            editLocation.value  = '';
            editSeatNo.value    = '';
            editDetails.value   = '';
            showSeatField(childLvl);
        } else if (trigger.classList.contains('js-edit-node')) {
            let data = {};
            try { data = JSON.parse(trigger.getAttribute('data-payload') || '{}'); } catch (e) { data = {}; }
            editTitle.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit ' + levelLabel(data.level_type);
            editId.value        = data.id;
            editParentId.value  = data.parent_id;
            editLevelType.value = data.level_type;
            editLevelLbl.value  = levelLabel(data.level_type);
            editParentLbl.value = data.parent_id === 0 ? '— (root)' : 'Parent';
            editName.value      = data.name || '';
            editSortOrder.value = data.sort_order || 0;
            editLocation.value  = data.location || '';
            editSeatNo.value    = data.seat_number || '';
            editDetails.value   = data.details || '';
            showSeatField(data.level_type);
        }
    });

    // -------------------- View modal --------------------
    const viewModalEl = document.getElementById('viewModal');
    const viewBody    = document.getElementById('viewModalBody');
    const viewTitle   = document.getElementById('viewModalTitle');
    const removeBtn   = document.getElementById('viewModalRemoveOfficer');
    const changeBtn   = document.getElementById('viewModalChangeOfficer');
    let currentViewData = null;

    const esc = (s) => String(s ?? '').replace(/[<>&"']/g, (c) => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[c]));
    const nl2br = (s) => esc(s).replace(/\n/g, '<br>');

    viewModalEl?.addEventListener('show.bs.modal', (ev) => {
        const trigger = ev.relatedTarget;
        if (!trigger) return;
        let data = {};
        try { data = JSON.parse(trigger.getAttribute('data-payload') || '{}'); } catch (e) { data = {}; }
        currentViewData = data;
        viewTitle.innerHTML = '<i class="bi bi-info-circle me-1"></i>' + esc(levelLabel(data.level_type)) + ' — ' + esc(data.name || '');
        // Header block with node fields, then AJAX-load officer history below.
        viewBody.innerHTML =
            '<div class="row g-3">' +
                '<div class="col-md-4"><div class="small text-muted">Type</div><div class="fw-semibold">' + esc(levelLabel(data.level_type)) + '</div></div>' +
                '<div class="col-md-4"><div class="small text-muted">Status</div><div class="fw-semibold">' + (data.active_status ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>') + '</div></div>' +
                '<div class="col-md-4"><div class="small text-muted">Last updated</div><div class="fw-semibold">' + esc(data.updated_at || '—') + '</div></div>' +
                '<div class="col-md-8"><div class="small text-muted">Location / Building</div><div class="fw-semibold">' + (data.location ? esc(data.location) : '<span class="text-muted">—</span>') + '</div></div>' +
                (data.level_type === 'seat' ? '<div class="col-md-4"><div class="small text-muted">Seat number</div><div class="fw-semibold">' + (data.seat_number ? esc(data.seat_number) : '<span class="text-muted">—</span>') + '</div></div>' : '') +
                '<div class="col-md-8"><div class="small text-muted">Responsible officer</div><div class="fw-semibold">' + (data.officer_name ? esc(data.officer_name) : '<span class="text-muted">— none —</span>') + (data.designation ? ' · <span class="text-muted small">' + esc(data.designation) + '</span>' : '') + '</div></div>' +
                '<div class="col-12"><div class="small text-muted">Details</div><div>' + (data.details ? nl2br(data.details) : '<span class="text-muted">—</span>') + '</div></div>' +
            '</div>' +
            '<hr>' +
            '<h6 class="mt-2 mb-2">Officer Transfer History</h6>' +
            '<div id="viewHistoryPanel" class="small text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Loading history&hellip;</div>';
        // Toggle "Remove current officer" button based on whether one is set.
        if (removeBtn) removeBtn.style.display = data.responsible_officer_id ? '' : 'none';

        // Load history via AJAX so the page doesn't ship it for every row.
        fetch('/office_hierarchy_ajax_history.php?node=' + encodeURIComponent(data.id), {
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
        }).then((r) => r.ok ? r.text() : Promise.reject(new Error('HTTP ' + r.status)))
          .then((html) => { const el = document.getElementById('viewHistoryPanel'); if (el) el.innerHTML = html; })
          .catch((err) => { const el = document.getElementById('viewHistoryPanel'); if (el) el.innerHTML = '<div class="alert alert-danger m-0">Could not load history: ' + esc(String(err)) + '</div>'; });
    });

    removeBtn?.addEventListener('click', () => {
        if (!currentViewData || !currentViewData.id) return;
        if (!confirm('Remove the current officer? The transfer is logged in the history.')) return;
        document.getElementById('unassignOfficerNodeId').value = currentViewData.id;
        document.getElementById('unassignOfficerForm').submit();
    });

    changeBtn?.addEventListener('click', () => {
        if (!currentViewData || !currentViewData.id) return;
        // Prep Assign modal with current node id + designation
        document.getElementById('assignOfficerNodeId').value    = currentViewData.id;
        document.getElementById('assignOfficerId').value        = currentViewData.responsible_officer_id || 0;
        document.getElementById('assignOfficerName').value      = currentViewData.officer_name || '';
        document.getElementById('assignOfficerDesignation').value = currentViewData.designation || '';
        document.getElementById('assignOfficerReason').value    = '';
        document.getElementById('assignOfficerTitle').innerHTML = '<i class="bi bi-person-check me-1"></i>Assign officer for ' + esc(currentViewData.name || '');
        // Close view modal, open assign modal.
        bootstrap.Modal.getInstance(viewModalEl)?.hide();
        setTimeout(() => new bootstrap.Modal(document.getElementById('assignOfficerModal')).show(), 200);
    });

    // -------------------- Officer picker --------------------
    const pickerBody  = document.getElementById('officerSearchBody');
    const pickerInput = document.getElementById('officerSearchInput');
    const pickerBtn   = document.getElementById('officerSearchBtn');
    const pickerEl    = document.getElementById('officerPickerModal');

    const runSearch = async () => {
        const q = pickerInput.value.trim();
        pickerBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm me-1"></span>Loading&hellip;</td></tr>';
        try {
            const res = await fetch('/office_hierarchy_ajax_users.php?q=' + encodeURIComponent(q), {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}});
            pickerBody.innerHTML = res.ok ? await res.text() : '<tr><td colspan="4" class="text-danger">HTTP ' + res.status + '</td></tr>';
        } catch (err) {
            pickerBody.innerHTML = '<tr><td colspan="4" class="text-danger">' + esc(String(err)) + '</td></tr>';
        }
    };
    pickerBtn?.addEventListener('click', runSearch);
    pickerInput?.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); runSearch(); } });
    pickerEl?.addEventListener('shown.bs.modal', () => { pickerInput.focus(); runSearch(); });

    pickerBody?.addEventListener('click', (ev) => {
        const t = ev.target.closest('.js-pick-officer');
        if (!t) return;
        const id = t.getAttribute('data-id');
        const nm = t.getAttribute('data-name');
        document.getElementById('assignOfficerId').value   = id;
        document.getElementById('assignOfficerName').value = nm;
        bootstrap.Modal.getInstance(pickerEl)?.hide();
    });
})();
</script>

<?php render_footer(); ?>

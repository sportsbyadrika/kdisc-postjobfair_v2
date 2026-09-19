<?php
/**
 * Office Hierarchy · Trash.
 *
 * Lists deactivated hierarchy nodes and offers a permanent-delete
 * action per row. Deletion is safe-guarded:
 *
 *   - Only nodes where active_status = 0 are visible here.
 *   - A node is delete-blocked when
 *       (a) it still has ANY children in the tree (active or not),
 *           or
 *       (b) any task_assignment references it (past or present).
 *     The button shows a tooltip explaining which condition is still
 *     open. Operators must remove references first — the trash is
 *     deliberately not a "cascade nuke everything" button.
 *
 *   - Delete removes the office_hierarchy_nodes row AND every
 *     office_hierarchy_officer_history row for it, inside one
 *     transaction. Nothing else is touched.
 *
 * Access: Administrator / DSM Admin (is_manage_admin) only. Every
 * POST checks CSRF via csrf_check_or_die().
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/office_hierarchy_helpers.php';
require_auth();

$viewer = current_user() ?? [];
if (!is_manage_admin($viewer)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only Administrator / DSM Admin can access the Office Hierarchy trash.</div>';
    render_footer();
    exit;
}
office_hierarchy_bootstrap();

$viewerId = (int) $viewer['id'];
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['office_hierarchy_trash_flash']) && is_array($_SESSION['office_hierarchy_trash_flash'])) {
    $flashMessage = (string) ($_SESSION['office_hierarchy_trash_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['office_hierarchy_trash_flash']['type'] ?? 'success');
    unset($_SESSION['office_hierarchy_trash_flash']);
}
$flashAndBack = static function (string $msg, string $type = 'success'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['office_hierarchy_trash_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /office_hierarchy_trash.php');
    exit;
};

if (is_post() && ($_POST['action'] ?? '') === 'purge') {
    csrf_check_or_die();
    $nodeId = (int) ($_POST['id'] ?? 0);
    if ($nodeId <= 0) $flashAndBack('Missing node id.', 'danger');

    $stmt = db()->prepare('SELECT id, name, level_type, active_status FROM office_hierarchy_nodes WHERE id = ?');
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch();
    if ($node === false)               $flashAndBack('Node not found.', 'danger');
    if ((int) $node['active_status'] !== 0) $flashAndBack('Only deactivated nodes can be permanently deleted. Deactivate the row first.', 'danger');

    // Guard: no children in the tree (active or inactive) — the operator
    // must trash them first, bottom-up, so nothing is silently discarded.
    $chStmt = db()->prepare('SELECT COUNT(*) FROM office_hierarchy_nodes WHERE parent_id = ?');
    $chStmt->execute([$nodeId]);
    if ((int) $chStmt->fetchColumn() > 0) {
        $flashAndBack('Cannot delete "' . $node['name'] . '" — it still has child nodes. Delete or move the children first.', 'danger');
    }

    // Guard: no task_assignment references (past or present).
    $taStmt = db()->prepare('SELECT COUNT(*) FROM task_assignment WHERE seat_id = ?');
    try { $taStmt->execute([$nodeId]); $taCount = (int) $taStmt->fetchColumn(); }
    catch (Throwable $e) { $taCount = 0; /* task_assignment may not exist yet */ }
    if ($taCount > 0) {
        $flashAndBack('Cannot delete "' . $node['name'] . '" — ' . $taCount . ' task assignment(s) still reference it. Remove those first.', 'danger');
    }

    $db = db();
    $db->query('START TRANSACTION');
    try {
        $db->prepare('DELETE FROM office_hierarchy_officer_history WHERE node_id = ?')->execute([$nodeId]);
        $db->prepare('DELETE FROM office_hierarchy_nodes WHERE id = ?')->execute([$nodeId]);
        $db->query('COMMIT');
        $flashAndBack(office_hierarchy_level_label((string) $node['level_type']) . ' "' . $node['name'] . '" permanently deleted.', 'success');
    } catch (Throwable $e) {
        try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
        $flashAndBack('Delete failed: ' . $e->getMessage(), 'danger');
    }
}

// Fetch every deactivated node + its parent name for context, plus
// per-row counts we use to decide whether the delete button is armed.
$rows = db()->query("SELECT n.*, p.name AS parent_name, p.level_type AS parent_level,
        (SELECT COUNT(*) FROM office_hierarchy_nodes c WHERE c.parent_id = n.id) AS child_count
    FROM office_hierarchy_nodes n
    LEFT JOIN office_hierarchy_nodes p ON p.id = n.parent_id
    WHERE n.active_status = 0
    ORDER BY n.level_type, n.name ASC")->fetchAll();

// Task-assignment counts in one grouped fetch (table might not exist
// on installs that never opened Task Tracker — treat that as zero).
$taByNode = [];
try {
    foreach (db()->query('SELECT seat_id, COUNT(*) AS c FROM task_assignment GROUP BY seat_id')->fetchAll() as $ta) {
        $taByNode[(int) $ta['seat_id']] = (int) $ta['c'];
    }
} catch (Throwable $e) { /* task_assignment absent */ }

render_header('Office Hierarchy · Trash', ['main_container_class' => 'container-xl']);
render_page_header('Office Hierarchy · Trash', [
    'icon'     => 'bi-trash',
    'subtitle' => 'Deactivated nodes only. Permanent deletion is blocked when a node still has children or is referenced by task assignments.',
    'actions'  => '<a class="btn btn-light" href="/office_hierarchy.php"><i class="bi bi-arrow-left me-1"></i>Back to Office Hierarchy</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-trash text-danger me-1"></i>Deactivated nodes</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> row<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Type</th>
                    <th>Name</th>
                    <th>Under</th>
                    <th class="text-end">Children</th>
                    <th class="text-end">Task refs</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-check2-circle"></i>Nothing in the trash — every node is active.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($rows as $r):
                    $children = (int) $r['child_count'];
                    $taskRefs = (int) ($taByNode[(int) $r['id']] ?? 0);
                    $canPurge = $children === 0 && $taskRefs === 0;
                    $reasons  = [];
                    if ($children > 0)  $reasons[] = 'has ' . $children . ' child node' . ($children === 1 ? '' : 's');
                    if ($taskRefs > 0)  $reasons[] = $taskRefs . ' task assignment' . ($taskRefs === 1 ? '' : 's') . ' reference it';
                ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><span class="badge text-bg-light border"><i class="bi <?= esc(office_hierarchy_level_icon((string) $r['level_type'])) ?> me-1"></i><?= esc(office_hierarchy_level_label((string) $r['level_type'])) ?></span></td>
                        <td class="fw-semibold"><?= esc((string) $r['name']) ?></td>
                        <td class="small text-muted">
                            <?php if (!empty($r['parent_name'])): ?>
                                <i class="bi <?= esc(office_hierarchy_level_icon((string) $r['parent_level'])) ?> me-1"></i><?= esc((string) $r['parent_name']) ?>
                            <?php else: ?>
                                <span class="text-muted">— (root)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small <?= $children > 0 ? 'text-warning fw-bold' : 'text-muted' ?>"><?= number_format($children) ?></td>
                        <td class="text-end small <?= $taskRefs > 0 ? 'text-warning fw-bold' : 'text-muted' ?>"><?= number_format($taskRefs) ?></td>
                        <td class="text-end">
                            <?php if ($canPurge): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Permanently delete \'<?= esc(addslashes((string) $r['name'])) ?>\'?\n\nThis also removes its officer-history rows and cannot be undone.');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="purge">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn btn-sm btn-danger"><i class="bi bi-trash me-1"></i>Delete permanently</button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled
                                    title="Blocked: <?= esc(implode(' + ', $reasons)) ?>. Handle those first.">
                                    <i class="bi bi-lock"></i> Blocked
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        A permanent delete removes the node and every <code>office_hierarchy_officer_history</code> row for it, in one transaction. Nothing else is touched. Children and task references block the delete on purpose — clear them first (bottom-up) so nothing silently disappears with a parent.
    </div>
</div>

<?php render_footer(); ?>

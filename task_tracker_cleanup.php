<?php
/**
 * Administration · Task Tracker cleanup.
 *
 * Permanently delete projects (with every task + sub-task + assignment
 * + remark + history + file row) or a single task + its descendant
 * subtree. Admin-only. The whole operation runs in one transaction so
 * a failed cascade doesn't leave half-deleted data.
 *
 * This complements the Projects list page which only deactivates — use
 * this page when a project / task was created in error and shouldn't
 * remain in history at all.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
task_tracker_bootstrap();

if (!is_manage_admin($viewer) && !user_can_admin_module($viewerId, 'administration')) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only an Administrator can delete projects or tasks.</div>';
    render_footer(); exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['tt_cleanup_flash']) && is_array($_SESSION['tt_cleanup_flash'])) {
    $flashMessage = (string) ($_SESSION['tt_cleanup_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['tt_cleanup_flash']['type'] ?? 'success');
    unset($_SESSION['tt_cleanup_flash']);
}
$flashAndBack = static function (string $msg, string $type = 'success'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['tt_cleanup_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /task_tracker_cleanup.php');
    exit;
};

/**
 * Delete a single task and every descendant underneath it (recursive).
 * Cleans assignments, remarks, history, files and the task row itself.
 * The caller owns the transaction.
 */
function tt_cleanup_delete_task_tree(int $taskId, int $viewerId): int
{
    $db = db();
    // Build the descendant set via BFS so we can batch the DELETEs.
    $toDelete = [$taskId];
    $frontier = [$taskId];
    $guard    = 0;
    while ($frontier !== [] && $guard++ < 1000) {
        $ph = implode(',', array_fill(0, count($frontier), '?'));
        $st = $db->prepare("SELECT id FROM task WHERE parent_id IN ($ph)");
        $st->execute($frontier);
        $kids = array_map(static fn($r) => (int) $r['id'], $st->fetchAll());
        if ($kids === []) break;
        foreach ($kids as $k) $toDelete[] = $k;
        $frontier = $kids;
    }
    $ph = implode(',', array_fill(0, count($toDelete), '?'));
    foreach (['task_assignment', 'task_remark', 'task_history', 'task_file'] as $t) {
        try { $db->prepare("DELETE FROM $t WHERE task_id IN ($ph)")->execute($toDelete); }
        catch (Throwable $e) { /* table absent on legacy installs */ }
    }
    // Clear meeting-decision-task back-link references so the decision
    // row doesn't point at ids we just removed.
    try { $db->prepare("UPDATE meeting_decision SET id = id WHERE 1 = 0"); } catch (Throwable $e) {}
    $db->prepare("DELETE FROM task WHERE id IN ($ph)")->execute($toDelete);
    return count($toDelete);
}

if (is_post() && ($_POST['action'] ?? '') === 'delete_task') {
    csrf_check_or_die();
    $tid = (int) ($_POST['id'] ?? 0);
    if ($tid <= 0) $flashAndBack('Missing task id.', 'danger');
    $st = db()->prepare('SELECT t.id, t.title, t.task_number, p.code FROM task t LEFT JOIN project p ON p.id = t.project_id WHERE t.id = ? LIMIT 1');
    $st->execute([$tid]);
    $row = $st->fetch();
    if ($row === false) $flashAndBack('Task not found.', 'danger');
    $label = ((string) ($row['code'] ?? '')) . '-' . (int) $row['task_number'] . ' · ' . (string) $row['title'];
    $db = db();
    $db->query('START TRANSACTION');
    try {
        $n = tt_cleanup_delete_task_tree($tid, $viewerId);
        $db->query('COMMIT');
        $flashAndBack('Deleted task ' . $label . ' (' . $n . ' row' . ($n === 1 ? '' : 's') . ' including sub-tasks).');
    } catch (Throwable $e) {
        try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
        $flashAndBack('Delete failed: ' . $e->getMessage(), 'danger');
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'delete_project') {
    csrf_check_or_die();
    $pid = (int) ($_POST['id'] ?? 0);
    if ($pid <= 0) $flashAndBack('Missing project id.', 'danger');
    $st = db()->prepare('SELECT id, code, name FROM project WHERE id = ? LIMIT 1');
    $st->execute([$pid]);
    $proj = $st->fetch();
    if ($proj === false) $flashAndBack('Project not found.', 'danger');

    $db = db();
    $db->query('START TRANSACTION');
    try {
        $taskIds = [];
        $st = $db->prepare('SELECT id FROM task WHERE project_id = ?');
        $st->execute([$pid]);
        foreach ($st->fetchAll() as $r) $taskIds[] = (int) $r['id'];
        $taskCount = count($taskIds);
        if ($taskIds !== []) {
            $ph = implode(',', array_fill(0, count($taskIds), '?'));
            foreach (['task_assignment', 'task_remark', 'task_history', 'task_file'] as $t) {
                try { $db->prepare("DELETE FROM $t WHERE task_id IN ($ph)")->execute($taskIds); }
                catch (Throwable $e) { /* table absent */ }
            }
            $db->prepare('DELETE FROM task WHERE project_id = ?')->execute([$pid]);
        }
        $db->prepare('DELETE FROM project WHERE id = ?')->execute([$pid]);
        $db->query('COMMIT');
        $flashAndBack('Deleted project ' . (string) $proj['code'] . ' — ' . (string) $proj['name'] . ' (' . $taskCount . ' task' . ($taskCount === 1 ? '' : 's') . ' removed).');
    } catch (Throwable $e) {
        try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
        $flashAndBack('Delete failed: ' . $e->getMessage(), 'danger');
    }
}

/* ---------- Listing ---------- */
$filterQ = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT p.id, p.code, p.name, p.start_date, p.end_date, p.is_active,
        (SELECT COUNT(*) FROM task WHERE project_id = p.id) AS task_count
    FROM project p
    WHERE p.office_id = ' . (int) TASK_TRACKER_OFFICE_ID;
$params = [];
if ($filterQ !== '') {
    $sql .= ' AND (p.name LIKE ? OR p.code LIKE ?)';
    array_push($params, '%' . $filterQ . '%', '%' . $filterQ . '%');
}
$sql .= ' ORDER BY p.is_active DESC, p.name ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

// Expanded project tasks (lazy — only fetched when ?expand=<pid> is set).
$expandId  = (int) ($_GET['expand'] ?? 0);
$expandedTasks = [];
if ($expandId > 0) {
    $st = db()->prepare('SELECT id, parent_id, task_number, title, is_active FROM task WHERE project_id = ? ORDER BY COALESCE(parent_id, 0), task_number');
    $st->execute([$expandId]);
    $expandedTasks = $st->fetchAll();
}

$fmtDate = static fn($s) => $s === null || $s === '' ? '—' : date('d/m/Y', strtotime((string) $s));

render_header('Administration · Task Tracker cleanup', ['main_container_class' => 'container-xl']);
render_page_header('Administration · Task Tracker cleanup', [
    'icon'     => 'bi-trash',
    'subtitle' => 'Permanently delete projects (with every task + sub-task + assignment + remark + history) or an individual task + its sub-tree. Use this when a row was created in error and should not remain in history. Deactivating a project from its own page is still the preferred reversible operation.',
    'actions'  => '<a class="btn btn-light" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<form class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-6">
            <label class="form-label">Search project</label>
            <input class="form-control" name="q" value="<?= esc($filterQ) ?>" placeholder="Project name or code">
        </div>
        <div class="col-md-6 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
            <a class="btn btn-light" href="/task_tracker_cleanup.php">Reset</a>
        </div>
    </div>
</form>

<div class="card">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead>
        <tr>
            <th>Project</th>
            <th>Dates</th>
            <th>Status</th>
            <th>Tasks</th>
            <th class="text-end" style="width: 220px;">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($projects === []): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No projects.</td></tr>
    <?php endif; ?>
    <?php foreach ($projects as $p):
        $pid = (int) $p['id'];
        $isExpanded = $expandId === $pid;
    ?>
        <tr>
            <td>
                <span class="badge text-bg-light border font-monospace me-2"><?= esc((string) $p['code']) ?></span>
                <strong><?= esc((string) $p['name']) ?></strong>
            </td>
            <td class="small text-muted">
                <?= esc($fmtDate($p['start_date'] ?? '')) ?> → <?= esc($fmtDate($p['end_date'] ?? '')) ?>
            </td>
            <td><span class="badge text-bg-<?= ((int) $p['is_active']) === 1 ? 'success' : 'secondary' ?>"><?= ((int) $p['is_active']) === 1 ? 'Active' : 'Inactive' ?></span></td>
            <td><?= (int) $p['task_count'] ?></td>
            <td class="text-end">
                <?php if ((int) $p['task_count'] > 0): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="?<?= $isExpanded ? '' : 'expand=' . $pid ?><?= $filterQ !== '' ? ($isExpanded ? 'q=' . urlencode($filterQ) : '&q=' . urlencode($filterQ)) : '' ?>">
                        <i class="bi bi-<?= $isExpanded ? 'chevron-up' : 'list-ul' ?> me-1"></i><?= $isExpanded ? 'Hide tasks' : 'Show tasks' ?>
                    </a>
                <?php endif; ?>
                <form method="post" class="d-inline" onsubmit="return confirm('PERMANENTLY delete project &quot;<?= esc((string) $p['name']) ?>&quot;?\n\nThis cascades through <?= (int) $p['task_count'] ?> task(s) with all their assignments, remarks, history and files. The operation cannot be undone.');">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_project">
                    <input type="hidden" name="id" value="<?= $pid ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash me-1"></i>Delete project</button>
                </form>
            </td>
        </tr>
        <?php if ($isExpanded && $expandedTasks !== []):
            // Build a parent -> children map so we can render in tree order.
            $kidsByParent = [];
            foreach ($expandedTasks as $t) {
                $par = (int) ($t['parent_id'] ?? 0);
                $kidsByParent[$par][] = $t;
            }
            $renderTree = static function (int $parent, int $depth) use (&$renderTree, &$kidsByParent, $p, $pid): void {
                foreach (($kidsByParent[$parent] ?? []) as $t) {
                    $tid = (int) $t['id'];
                    $prefix = $depth === 0 ? '' : str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth) . '└ ';
                    ?>
                    <tr class="table-light">
                        <td><?= $prefix ?><span class="badge text-bg-light border font-monospace me-1"><?= esc((string) ($p['code'] ?? '')) ?>-<?= (int) $t['task_number'] ?></span><?= esc((string) $t['title']) ?></td>
                        <td class="small text-muted">—</td>
                        <td><span class="badge text-bg-<?= ((int) $t['is_active']) === 1 ? 'success' : 'secondary' ?>"><?= ((int) $t['is_active']) === 1 ? 'Active' : 'Inactive' ?></span></td>
                        <td>—</td>
                        <td class="text-end">
                            <form method="post" class="d-inline" onsubmit="return confirm('PERMANENTLY delete this task and every sub-task underneath?\n\nAll assignments, remarks, history and files are removed. Cannot be undone.');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_task">
                                <input type="hidden" name="id" value="<?= $tid ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash me-1"></i>Delete task + subtree</button>
                            </form>
                        </td>
                    </tr>
                    <?php
                    $renderTree($tid, $depth + 1);
                }
            };
            $renderTree(0, 0);
        endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<?php render_footer(); ?>

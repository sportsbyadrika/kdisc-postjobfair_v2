<?php
/**
 * Task Tracker · Kanban card move handler.
 *
 * Wire format (POST, JSON body OR form-encoded):
 *   csrf_token
 *   task_id
 *   new_status_id
 *   prev_task_id | ''            (task above the dropped card, empty if top)
 *   next_task_id | ''            (task below the dropped card, empty if bottom)
 *   actual_end   | ''            (YYYY-MM-DD, only when new status is terminal)
 *
 * Response (JSON):
 *   { ok: true, task_id, new_status_id, new_board_order, column_counts: {..} }
 *   { ok: false, error: "message" }
 *
 * Server computes new_board_order as:
 *   - midpoint of (prev.board_order, next.board_order) when both sides present
 *   - prev.board_order + 1000 when only prev present
 *   - next.board_order - 1000 (min 1) when only next present
 *   - 1000 when column is empty
 *
 * Every move writes a task_history row (status change, and optionally an
 * actual_end change) inside the same transaction as the update.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$sendError = static function (string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
};

require_auth();
task_tracker_bootstrap();

$viewer   = current_user();
$viewerId = (int) ($viewer['id'] ?? 0);

if (!is_post()) $sendError('POST required.', 405);
if (!csrf_check()) $sendError('CSRF check failed.', 403);

$taskId     = (int) ($_POST['task_id']       ?? 0);
$newStatus  = (int) ($_POST['new_status_id'] ?? 0);
$prevTaskId = (int) ($_POST['prev_task_id']  ?? 0);
$nextTaskId = (int) ($_POST['next_task_id']  ?? 0);
$actualEnd  = trim((string) ($_POST['actual_end'] ?? ''));

if ($taskId <= 0 || $newStatus <= 0) $sendError('Missing task_id or new_status_id.');

$db = db();

$t = $db->prepare("SELECT t.*, s.is_terminal AS old_is_terminal, s.name AS old_status_name
    FROM task t LEFT JOIN task_status s ON s.id = t.status_id
    WHERE t.id = ? AND t.is_active = 1 LIMIT 1");
$t->execute([$taskId]);
$task = $t->fetch();
if ($task === false) $sendError('Task not found or deactivated.', 404);

$ns = $db->prepare('SELECT id, name, is_terminal, is_active FROM task_status WHERE id = ? LIMIT 1');
$ns->execute([$newStatus]);
$newStatusRow = $ns->fetch();
if ($newStatusRow === false || (int) $newStatusRow['is_active'] !== 1) $sendError('Target status is not active.');

// Walk parent chain in PHP so an optional Sub Section between Section
// and Seat is transparent to the permission check.
$astmt = $db->prepare("SELECT ta.seat_id, n.parent_id AS immediate_parent_id
    FROM task_assignment ta
    INNER JOIN office_hierarchy_nodes n ON n.id = ta.seat_id
    WHERE ta.task_id = ? AND ta.role = 'primary' LIMIT 1");
$astmt->execute([$taskId]);
$assign = $astmt->fetch();
$sectionId = 0; $divisionId = 0;
$cursor = (int) ($assign['immediate_parent_id'] ?? 0);
for ($g = 0; $g < 10 && $cursor > 0; $g++) {
    $ps = $db->prepare('SELECT id, parent_id, level_type FROM office_hierarchy_nodes WHERE id = ?');
    $ps->execute([$cursor]);
    $pr = $ps->fetch();
    if ($pr === false) break;
    if ((string) $pr['level_type'] === 'section'  && $sectionId  === 0) $sectionId  = (int) $pr['id'];
    if ((string) $pr['level_type'] === 'division' && $divisionId === 0) $divisionId = (int) $pr['id'];
    $cursor = (int) ($pr['parent_id'] ?? 0);
}
$taskForPerm = array_merge((array) $task, [
    'primary_seat_id'     => (int) ($assign['seat_id'] ?? 0),
    'primary_section_id'  => $sectionId,
    'primary_division_id' => $divisionId,
]);
if (!can_move_task($viewerId, $taskForPerm)) $sendError('You do not have permission to move this task.', 403);

$fetchOrder = static function (int $id, int $projectId, int $statusId) use ($db): ?float {
    if ($id <= 0) return null;
    $stmt = $db->prepare('SELECT board_order FROM task WHERE id = ? AND project_id = ? AND status_id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$id, $projectId, $statusId]);
    $r = $stmt->fetch();
    return $r === false ? null : (float) $r['board_order'];
};

$projectId  = (int) $task['project_id'];
$prevOrder  = $fetchOrder($prevTaskId, $projectId, $newStatus);
$nextOrder  = $fetchOrder($nextTaskId, $projectId, $newStatus);

if ($prevOrder !== null && $nextOrder !== null) {
    $newOrder = ($prevOrder + $nextOrder) / 2.0;
    if (abs($nextOrder - $prevOrder) < 0.000002) $newOrder = $prevOrder + 0.000001;
} elseif ($prevOrder !== null) {
    $newOrder = $prevOrder + 1000.0;
} elseif ($nextOrder !== null) {
    $newOrder = max($nextOrder - 1000.0, 0.000001);
} else {
    $newOrder = 1000.0;
}

$actualEndSave = null;
if ((int) $newStatusRow['is_terminal'] === 1 && $actualEnd !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $actualEnd)) $sendError('actual_end must be YYYY-MM-DD.');
    $actualEndSave = $actualEnd;
}

$db->query('START TRANSACTION');
try {
    $upd = $db->prepare('UPDATE task SET status_id = ?, board_order = ?, updated_at = NOW(), updated_by = ?'
        . ($actualEndSave !== null ? ', actual_end = ?' : '')
        . ' WHERE id = ?');
    $params = [$newStatus, $newOrder, $viewerId];
    if ($actualEndSave !== null) $params[] = $actualEndSave;
    $params[] = $taskId;
    $upd->execute($params);

    $hist = $db->prepare('INSERT INTO task_history (task_id, field_name, old_value, new_value, changed_by, changed_at)
        VALUES (?, ?, ?, ?, ?, NOW())');
    if ((int) $task['status_id'] !== $newStatus) {
        $hist->execute([$taskId, 'status',
            (string) ($task['old_status_name'] ?? '#' . (int) $task['status_id']),
            (string) $newStatusRow['name'],
            $viewerId]);
    }
    if ($actualEndSave !== null && substr((string) ($task['actual_end'] ?? ''), 0, 10) !== $actualEndSave) {
        $hist->execute([$taskId, 'actual_end',
            substr((string) ($task['actual_end'] ?? ''), 0, 10) ?: null,
            $actualEndSave, $viewerId]);
    }

    $db->query('COMMIT');
} catch (Throwable $e) {
    try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
    $sendError('Move failed: ' . $e->getMessage(), 500);
}

$counts = [];
$cstmt = $db->prepare("SELECT status_id, COUNT(*) AS c FROM task
    WHERE project_id = ? AND is_active = 1 GROUP BY status_id");
$cstmt->execute([$projectId]);
foreach ($cstmt->fetchAll() as $row) $counts[(string) (int) $row['status_id']] = (int) $row['c'];

echo json_encode([
    'ok'              => true,
    'task_id'         => $taskId,
    'new_status_id'   => $newStatus,
    'new_board_order' => $newOrder,
    'is_terminal'     => (int) $newStatusRow['is_terminal'],
    'column_counts'   => $counts,
]);

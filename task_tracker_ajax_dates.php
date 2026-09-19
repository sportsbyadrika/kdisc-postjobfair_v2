<?php
/**
 * Task Tracker · Batch update of planned dates from the Gantt chart.
 *
 * Wire format (POST, form-encoded):
 *   csrf_token
 *   updates[<n>][task_id]       — int
 *   updates[<n>][planned_start] — YYYY-MM-DD
 *   updates[<n>][planned_end]   — YYYY-MM-DD
 *
 * Response (JSON):
 *   { ok: true,  updated: <int>, skipped: <int>, errors: [<string>] }
 *   { ok: false, error: "message" }
 *
 * Each row is permission-checked via can_edit_task() and written
 * inside the shared transaction; a task_history row is added for
 * every changed field (planned_start / planned_end). A single denied
 * row does not abort the batch — it lands in the errors[] array and
 * the others still commit.
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

if (!is_post())    $sendError('POST required.', 405);
if (!csrf_check()) $sendError('CSRF check failed.', 403);

$updates = (array) ($_POST['updates'] ?? []);
if ($updates === []) $sendError('No updates supplied.');

$db = db();
$db->query('START TRANSACTION');

$updated = 0; $skipped = 0; $errors = [];

try {
    foreach ($updates as $u) {
        $taskId = (int)  ($u['task_id']       ?? 0);
        $ps     = trim((string) ($u['planned_start'] ?? ''));
        $pe     = trim((string) ($u['planned_end']   ?? ''));
        if ($taskId <= 0) { $errors[] = 'Missing task_id in an update entry.'; $skipped++; continue; }
        if ($ps !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ps)) { $errors[] = 'Task ' . $taskId . ': bad start date format.'; $skipped++; continue; }
        if ($pe !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pe)) { $errors[] = 'Task ' . $taskId . ': bad end date format.';   $skipped++; continue; }
        if ($ps !== '' && $pe !== '' && $ps > $pe) { $errors[] = 'Task ' . $taskId . ': start after end.'; $skipped++; continue; }

        $tstmt = $db->prepare('SELECT * FROM task WHERE id = ? AND is_active = 1 LIMIT 1');
        $tstmt->execute([$taskId]);
        $task = $tstmt->fetch();
        if ($task === false) { $errors[] = 'Task ' . $taskId . ' not found.'; $skipped++; continue; }

        // Permission check — mirrors ajax_move by resolving section /
        // division ancestors in PHP so a sub_section between Section
        // and Seat is transparent.
        $astmt = $db->prepare("SELECT ta.seat_id, n.parent_id AS immediate_parent_id
            FROM task_assignment ta
            INNER JOIN office_hierarchy_nodes n ON n.id = ta.seat_id
            WHERE ta.task_id = ? AND ta.role = 'primary' LIMIT 1");
        $astmt->execute([$taskId]);
        $assign = $astmt->fetch();
        $sectionId = 0; $divisionId = 0;
        $cursor = (int) ($assign['immediate_parent_id'] ?? 0);
        for ($g = 0; $g < 10 && $cursor > 0; $g++) {
            $ps2 = $db->prepare('SELECT id, parent_id, level_type FROM office_hierarchy_nodes WHERE id = ?');
            $ps2->execute([$cursor]);
            $pr = $ps2->fetch();
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
        if (!can_edit_task($viewerId, $taskForPerm)) {
            $errors[] = 'Task ' . $taskId . ': not permitted.'; $skipped++; continue;
        }

        $oldStart = substr((string) ($task['planned_start'] ?? ''), 0, 10);
        $oldEnd   = substr((string) ($task['planned_end']   ?? ''), 0, 10);
        if ($oldStart === $ps && $oldEnd === $pe) { $skipped++; continue; }

        $db->prepare('UPDATE task SET planned_start = ?, planned_end = ?, updated_at = NOW(), updated_by = ? WHERE id = ?')
           ->execute([$ps === '' ? null : $ps, $pe === '' ? null : $pe, $viewerId, $taskId]);

        $hist = $db->prepare('INSERT INTO task_history (task_id, field_name, old_value, new_value, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, NOW())');
        if ($oldStart !== $ps) $hist->execute([$taskId, 'planned_start', $oldStart === '' ? null : $oldStart, $ps === '' ? null : $ps, $viewerId]);
        if ($oldEnd   !== $pe) $hist->execute([$taskId, 'planned_end',   $oldEnd   === '' ? null : $oldEnd,   $pe === '' ? null : $pe, $viewerId]);
        $updated++;
    }
    $db->query('COMMIT');
} catch (Throwable $e) {
    try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
    $sendError('Batch failed: ' . $e->getMessage(), 500);
}

echo json_encode(['ok' => true, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors]);

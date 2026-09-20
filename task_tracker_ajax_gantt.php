<?php
/**
 * Task Tracker · Gantt data endpoint.
 *
 * Returns JSON for one project's tasks so the Project Status page's
 * Gantt tab can lazy-load instead of running a page-blocking query
 * inline. The response also carries a lightweight suggestion for the
 * default view mode so a project with a long date range doesn't open
 * in Day view — that's what actually made the SVG huge and slow.
 *
 * Wire (GET):
 *   project=<id>
 *
 * Response (JSON):
 *   { ok: true, tasks: [ {id,name,start,end,progress,dependencies}, ... ],
 *     suggested_view: 'Day'|'Week'|'Month'|'Quarter Day',
 *     span_days: <int>, count: <int> }
 *   { ok: false, error: 'message' }
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';

header('Content-Type: application/json; charset=utf-8');

require_auth();
task_tracker_bootstrap();

$sendError = static function (string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
};

$projectId = (int) ($_GET['project'] ?? 0);
if ($projectId <= 0) $sendError('Missing project id.');

$pstmt = db()->prepare('SELECT id, code, name FROM project WHERE id = ? AND office_id = ? LIMIT 1');
$pstmt->execute([$projectId, TASK_TRACKER_OFFICE_ID]);
$project = $pstmt->fetch();
if ($project === false) $sendError('Project not found.', 404);

$hasProgress = task_tracker_column_exists('task', 'progress_pct');
$progressSel = $hasProgress ? 't.progress_pct' : 'NULL AS progress_pct';

// One project only, and we only need the columns the Gantt renders —
// keeps the payload lean even on very large projects. LIMIT 2000 is a
// hard safety cap so a runaway project doesn't hang the browser.
$stmt = db()->prepare("SELECT t.id, t.parent_id, t.task_number, t.title,
        t.planned_start, t.planned_end, $progressSel,
        s.is_terminal
    FROM task t
    LEFT JOIN task_status s ON s.id = t.status_id
    WHERE t.project_id = ? AND t.is_active = 1
    ORDER BY t.parent_id IS NULL DESC, COALESCE(t.parent_id, t.id), t.task_number ASC
    LIMIT 2000");
$stmt->execute([$projectId]);
$rows = $stmt->fetchAll();

$code = (string) $project['code'];
$tasks = [];
$minStart = null; $maxEnd = null;
foreach ($rows as $t) {
    $ps = substr((string) ($t['planned_start'] ?? ''), 0, 10);
    $pe = substr((string) ($t['planned_end']   ?? ''), 0, 10);
    if ($ps === '' && $pe === '') continue;
    if ($ps === '') $ps = $pe;
    if ($pe === '') $pe = $ps;
    $pct = $t['progress_pct'] !== null ? (int) $t['progress_pct']
        : ((int) ($t['is_terminal'] ?? 0) === 1 ? 100 : 0);
    $tasks[] = [
        'id'           => 't' . (int) $t['id'],
        'name'         => $code . '-' . (int) $t['task_number'] . ' · ' . (string) $t['title'],
        'start'        => $ps,
        'end'          => $pe,
        'progress'     => $pct,
        'dependencies' => empty($t['parent_id']) ? '' : ('t' . (int) $t['parent_id']),
    ];
    if ($minStart === null || $ps < $minStart) $minStart = $ps;
    if ($maxEnd   === null || $pe > $maxEnd)   $maxEnd   = $pe;
}

// Heuristic for default view mode: pick the coarsest mode that keeps
// the timeline width manageable. Frappe-Gantt renders one column per
// unit — Day view over a 3-year project draws 1000+ columns and is
// where the "slow" comes from.
$suggested = 'Day';
$spanDays  = 0;
if ($minStart !== null && $maxEnd !== null) {
    $spanDays = (int) round((strtotime($maxEnd) - strtotime($minStart)) / 86400);
    if     ($spanDays > 365) $suggested = 'Month';
    elseif ($spanDays > 120) $suggested = 'Week';
    elseif ($spanDays > 60)  $suggested = 'Week';
}
if (count($tasks) > 40 && $suggested === 'Day') $suggested = 'Week';

echo json_encode([
    'ok'             => true,
    'tasks'          => $tasks,
    'suggested_view' => $suggested,
    'span_days'      => $spanDays,
    'count'          => count($tasks),
]);

<?php
/**
 * Task Tracker · Gantt (people view) data endpoint.
 *
 * Returns one Gantt-shaped row per (person, task) engagement inside
 * the selected project. Persons are the current officer holders of
 * each assigned seat — a task without a live officer shows the seat
 * name as a fallback so nothing silently disappears from the chart.
 *
 * Rows are sorted so every person's items sit contiguously, and each
 * row's label leads with the person name so the y-axis reads as a
 * per-person timeline even though Frappe-Gantt draws one bar per row.
 *
 * Wire (GET):
 *   project=<id>
 *
 * Response (JSON):
 *   { ok: true,
 *     tasks: [ {id,name,start,end,progress,dependencies:''}, ... ],
 *     suggested_view: 'Day'|'Week'|'Month'|'Quarter Day',
 *     span_days: <int>, count: <int>, people: <int> }
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
$code = (string) $project['code'];

$hasProgress = task_tracker_column_exists('task', 'progress_pct');
$progressSel = $hasProgress ? 't.progress_pct' : 'NULL AS progress_pct';

// Every (task, assigned seat) with the seat's current officer. If the
// seat is currently vacant, seat name stands in.
$sql = "SELECT t.id AS task_id, t.task_number, t.title, t.parent_id,
        t.planned_start, t.planned_end, $progressSel, s.is_terminal,
        ta.role, ta.seat_id,
        n.name AS seat_name, n.seat_number,
        u.id   AS officer_id, u.name AS officer_name
    FROM task t
    LEFT JOIN task_status s ON s.id = t.status_id
    INNER JOIN task_assignment ta ON ta.task_id = t.id
    INNER JOIN office_hierarchy_nodes n ON n.id = ta.seat_id
    LEFT JOIN office_hierarchy_officer_history h
        ON h.node_id = n.id AND h.unassigned_at IS NULL
    LEFT JOIN users u ON u.id = h.officer_id
    WHERE t.project_id = ? AND t.is_active = 1
    ORDER BY COALESCE(u.name, n.name) ASC, t.task_number ASC
    LIMIT 5000";
$stmt = db()->prepare($sql);
$stmt->execute([$projectId]);
$rows = $stmt->fetchAll();

$tasks = [];
$people = [];
$minStart = null; $maxEnd = null;

foreach ($rows as $r) {
    $ps = substr((string) ($r['planned_start'] ?? ''), 0, 10);
    $pe = substr((string) ($r['planned_end']   ?? ''), 0, 10);
    if ($ps === '' && $pe === '') continue;
    if ($ps === '') $ps = $pe;
    if ($pe === '') $pe = $ps;
    $pct = $r['progress_pct'] !== null ? (int) $r['progress_pct']
        : ((int) ($r['is_terminal'] ?? 0) === 1 ? 100 : 0);

    $personName = trim((string) ($r['officer_name'] ?? ''));
    if ($personName === '') $personName = trim((string) ($r['seat_name'] ?? '')) . ' (vacant)';
    $people[$personName] = true;

    $roleTag = ((string) $r['role']) === 'primary' ? 'P' : 'S';
    $taskKey = $code . '-' . (int) $r['task_number'];
    $label   = $personName . '  ·  [' . $roleTag . '] ' . $taskKey . ' · ' . (string) $r['title'];

    $tasks[] = [
        // Unique id per (task, seat, role) so Frappe doesn't collapse rows.
        'id'           => 'p' . (int) ($r['officer_id'] ?? 0) . '-s' . (int) $r['seat_id'] . '-t' . (int) $r['task_id'] . '-' . $roleTag,
        'name'         => $label,
        'start'        => $ps,
        'end'          => $pe,
        'progress'     => $pct,
        'dependencies' => '',
    ];
    if ($minStart === null || $ps < $minStart) $minStart = $ps;
    if ($maxEnd   === null || $pe > $maxEnd)   $maxEnd   = $pe;
}

$suggested = 'Day';
$spanDays  = 0;
if ($minStart !== null && $maxEnd !== null) {
    $spanDays = (int) round((strtotime($maxEnd) - strtotime($minStart)) / 86400);
    if     ($spanDays > 365) $suggested = 'Month';
    elseif ($spanDays > 60)  $suggested = 'Week';
}
if (count($tasks) > 40 && $suggested === 'Day') $suggested = 'Week';

echo json_encode([
    'ok'             => true,
    'tasks'          => $tasks,
    'suggested_view' => $suggested,
    'span_days'      => $spanDays,
    'count'          => count($tasks),
    'people'         => count($people),
]);

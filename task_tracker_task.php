<?php
/**
 * Task Tracker · Task new / edit form.
 *
 * URL forms:
 *   /task_tracker_task.php?project=<pid>          — new task under a project
 *   /task_tracker_task.php?id=<tid>               — edit existing task
 *
 * Notes:
 *   - task_number is allocated atomically from project.next_task_number
 *     with a FOR UPDATE inside the same transaction as the INSERT, so
 *     two concurrent creates cannot collide.
 *   - board_order lands at MAX + 1000 for the target column so new
 *     tasks appear at the bottom until moved.
 *   - task_history writes one row per changed field, always inside the
 *     save transaction. Nothing about a task ever changes without a
 *     history row.
 *   - primary + secondary assignments live in task_assignment. Save
 *     replaces the assignment set wholesale (delete + insert) — history
 *     records the change as one row keyed by field_name = 'primary_seat'
 *     or 'secondary_seats'.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';
require_task_tracker_access();
task_tracker_bootstrap();

$viewer   = current_user();
$viewerId = (int) $viewer['id'];
$canManage = is_manage_admin($viewer);
$scope     = get_user_scope($viewerId);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashAndReturn = static function (string $msg, string $type, int $projectId, int $taskId): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['task_tracker_flash'] = ['msg' => $msg, 'type' => $type];
    if ($projectId > 0) header('Location: /task_tracker_project_view.php?id=' . $projectId);
    elseif ($taskId > 0) header('Location: /task_tracker_task.php?id=' . $taskId);
    else header('Location: /task_tracker_projects.php');
    exit;
};

$taskId    = (int) ($_GET['id']      ?? 0);
$projectId = (int) ($_GET['project'] ?? 0);
$existing  = null;
if ($taskId > 0) {
    $stmt = db()->prepare('SELECT * FROM task WHERE id = ? LIMIT 1');
    $stmt->execute([$taskId]);
    $existing = $stmt->fetch() ?: null;
    if ($existing !== null) {
        $projectId = (int) $existing['project_id'];
    } else {
        $taskId = 0;
    }
}

if ($projectId <= 0) {
    render_header('Task not found');
    render_page_header('Task not found', ['icon' => 'bi-exclamation-triangle',
        'actions' => '<a class="btn btn-light" href="/task_tracker_projects.php"><i class="bi bi-arrow-left me-1"></i>Back</a>']);
    echo '<div class="alert alert-warning">Project not identified. Open the task from the project view.</div>';
    render_footer();
    exit;
}

$pstmt = db()->prepare('SELECT * FROM project WHERE id = ? AND office_id = ? LIMIT 1');
$pstmt->execute([$projectId, TASK_TRACKER_OFFICE_ID]);
$project = $pstmt->fetch();
if ($project === false) {
    render_header('Project not found');
    render_page_header('Project not found', ['icon' => 'bi-exclamation-triangle',
        'actions' => '<a class="btn btn-light" href="/task_tracker_projects.php"><i class="bi bi-arrow-left me-1"></i>Back</a>']);
    echo '<div class="alert alert-warning">This project does not exist.</div>';
    render_footer();
    exit;
}

// Load bare seat ids + role; section / division ancestors are derived
// AFTER the $seats array is built (below), because that array walks
// the parent chain and transparently handles sub_section-in-between.
$existingAssignmentPayload = ['primary_seat_id' => 0, 'primary_section_id' => 0, 'primary_division_id' => 0, 'secondary_seat_ids' => []];
if ($existing !== null) {
    $astmt = db()->prepare('SELECT role, seat_id FROM task_assignment WHERE task_id = ?');
    $astmt->execute([$taskId]);
    foreach ($astmt->fetchAll() as $a) {
        if ($a['role'] === 'primary') {
            $existingAssignmentPayload['primary_seat_id'] = (int) $a['seat_id'];
        } else {
            $existingAssignmentPayload['secondary_seat_ids'][] = (int) $a['seat_id'];
        }
    }
}

if (!can_edit_task($viewerId, $existing === null ? null : array_merge(
    (array) $existing,
    ['primary_seat_id'     => $existingAssignmentPayload['primary_seat_id'],
     'primary_section_id'  => $existingAssignmentPayload['primary_section_id'],
     'primary_division_id' => $existingAssignmentPayload['primary_division_id']]
))) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock',
        'actions' => '<a class="btn btn-light" href="/task_tracker_project_view.php?id=' . (int) $project['id'] . '"><i class="bi bi-arrow-left me-1"></i>Back</a>']);
    echo '<div class="alert alert-danger">You do not have permission to edit this task.</div>';
    render_footer();
    exit;
}

$statuses = db()->query('SELECT * FROM task_status WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
$defaultStatusId = 0;
foreach ($statuses as $s) { if ((string) $s['category'] === 'todo' && (int) $s['is_terminal'] === 0) { $defaultStatusId = (int) $s['id']; break; } }
if ($defaultStatusId === 0 && $statuses !== []) $defaultStatusId = (int) $statuses[0]['id'];

$activitiesStmt = db()->prepare('SELECT id, task_number, title FROM task
    WHERE project_id = ? AND (parent_id IS NULL OR parent_id = 0) AND is_active = 1
      AND id <> ?
    ORDER BY task_number ASC');
$activitiesStmt->execute([$projectId, $taskId]);
$activities = $activitiesStmt->fetchAll();

$treeRows = db()->query("SELECT id, parent_id, level_type, name, seat_number, active_status
    FROM office_hierarchy_nodes
    WHERE active_status = 1 AND level_type IN ('office','division','section','sub_section','seat')
    ORDER BY level_type, sort_order ASC, name ASC")->fetchAll();

$byId = [];
foreach ($treeRows as $n) $byId[(int) $n['id']] = $n;

// Walk up ancestors so a seat under a Sub Section still resolves to
// its enclosing Section (and Division). The cascading picker stays
// three-level for the operator — Division → Section → Seat — with
// Sub Sections transparently flattened underneath.
$ancestorOfType = static function (array $node, string $wantedType) use ($byId): ?array {
    $cursor = $node;
    for ($guard = 0; $guard < 10; $guard++) {
        $pid = (int) ($cursor['parent_id'] ?? 0);
        if ($pid <= 0) return null;
        $parent = $byId[$pid] ?? null;
        if ($parent === null) return null;
        if ((string) $parent['level_type'] === $wantedType) return $parent;
        $cursor = $parent;
    }
    return null;
};

$divisions = []; $sections = []; $seats = [];
foreach ($treeRows as $n) {
    if ($n['level_type'] === 'division') {
        $divisions[] = ['id' => (int) $n['id'], 'name' => (string) $n['name'], 'office_id' => (int) $n['parent_id']];
    } elseif ($n['level_type'] === 'section') {
        $sections[] = ['id' => (int) $n['id'], 'name' => (string) $n['name'], 'division_id' => (int) $n['parent_id']];
    } elseif ($n['level_type'] === 'seat') {
        $section  = $ancestorOfType($n, 'section');
        $division = $section ? ($byId[(int) $section['parent_id']] ?? null) : null;
        $seats[] = [
            'id'          => (int) $n['id'],
            'name'        => (string) $n['name'],
            'seat_number' => (string) ($n['seat_number'] ?? ''),
            'section_id'  => $section  ? (int) $section['id']  : 0,
            'division_id' => $division ? (int) $division['id'] : 0,
        ];
    }
}

// With $seats built, fill in section/division for the primary seat so
// the cascading picker preselects the right chain when editing.
if ($existingAssignmentPayload['primary_seat_id'] > 0) {
    foreach ($seats as $sInfo) {
        if ($sInfo['id'] === $existingAssignmentPayload['primary_seat_id']) {
            $existingAssignmentPayload['primary_section_id']  = $sInfo['section_id'];
            $existingAssignmentPayload['primary_division_id'] = $sInfo['division_id'];
            break;
        }
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();

    $type          = (string) ($_POST['type'] ?? 'activity');
    $parentIdIn    = (int) ($_POST['parent_id'] ?? 0);
    $title         = trim((string) ($_POST['title'] ?? ''));
    $description   = trim((string) ($_POST['description'] ?? ''));
    $target        = trim((string) ($_POST['target'] ?? ''));
    $statusIdIn    = (int) ($_POST['status_id'] ?? 0);
    $priority      = (string) ($_POST['priority'] ?? 'medium');
    $plannedStart  = trim((string) ($_POST['planned_start'] ?? ''));
    $plannedEnd    = trim((string) ($_POST['planned_end'] ?? ''));

    // Financial fields (optional). Blank string -> null so the column
    // doesn't get set to 0.00 by accident.
    $moneyIn = static fn(string $key) => trim((string) ($_POST[$key] ?? '')) === ''
        ? null : (float) $_POST[$key];
    $shareAmount     = $moneyIn('share_amount');
    $projectedAmount = $moneyIn('projected_amount');
    $targetExp       = $moneyIn('target_expenditure');
    $actualExp       = $moneyIn('actual_expenditure');
    $progressIn      = trim((string) ($_POST['progress_pct'] ?? ''));
    $progressPct     = $progressIn === '' ? null : max(0, min(100, (int) $progressIn));
    $hasFin      = task_tracker_column_exists('task', 'share_amount');
    $hasProgress = task_tracker_column_exists('task', 'progress_pct');
    $primarySeat   = (int) ($_POST['primary_seat_id'] ?? 0);
    $secondaryRaw  = (array) ($_POST['secondary_seat_ids'] ?? []);
    $secondarySeat = [];
    foreach ($secondaryRaw as $s) {
        $sid = (int) $s;
        if ($sid > 0 && $sid !== $primarySeat && !in_array($sid, $secondarySeat, true)) $secondarySeat[] = $sid;
    }

    $errors = [];
    if ($title === '') $errors[] = 'Title is required.';
    if ($type === 'sub' && $parentIdIn <= 0) $errors[] = 'Choose an activity when saving a sub-activity.';
    if ($type === 'sub' && $parentIdIn > 0) {
        $ok = false;
        foreach ($activities as $a) { if ((int) $a['id'] === $parentIdIn) { $ok = true; break; } }
        if (!$ok) $errors[] = 'Selected parent activity is not valid for this project.';
    }
    $statusOk = false;
    foreach ($statuses as $s) { if ((int) $s['id'] === $statusIdIn) { $statusOk = true; break; } }
    if (!$statusOk) $errors[] = 'Selected status is not valid.';
    if (!in_array($priority, ['lowest','low','medium','high','highest'], true)) $errors[] = 'Invalid priority.';
    if ($plannedStart !== '' && $plannedEnd !== '' && $plannedStart > $plannedEnd) {
        $errors[] = 'Planned end date must be on or after planned start date.';
    }
    if ($primarySeat > 0) {
        $ok = false;
        foreach ($seats as $st) { if ($st['id'] === $primarySeat) { $ok = true; break; } }
        if (!$ok) $errors[] = 'Primary seat is not a valid seat.';
    }
    foreach ($secondarySeat as $sid) {
        $ok = false;
        foreach ($seats as $st) { if ($st['id'] === $sid) { $ok = true; break; } }
        if (!$ok) { $errors[] = 'One of the secondary seats is not valid.'; break; }
    }

    if ($errors !== []) {
        $flashAndReturn(implode(' ', $errors), 'danger', 0, $taskId);
    }

    $parentForInsert = ($type === 'sub' && $parentIdIn > 0) ? $parentIdIn : null;

    $db = db();
    $db->query('START TRANSACTION');

    try {
        if ($existing === null) {
            $lock = $db->prepare('SELECT next_task_number FROM project WHERE id = ? FOR UPDATE');
            $lock->execute([$projectId]);
            $nextRow = $lock->fetch();
            $taskNumber = (int) ($nextRow['next_task_number'] ?? 1);

            $orderStmt = $db->prepare('SELECT COALESCE(MAX(board_order), 0) AS m FROM task WHERE project_id = ? AND status_id = ?');
            $orderStmt->execute([$projectId, $statusIdIn]);
            $newOrder = ((float) ($orderStmt->fetch()['m'] ?? 0)) + 1000;

            $ins = $db->prepare('INSERT INTO task
                (project_id, task_number, parent_id, title, description, target, status_id, priority,
                 planned_start, planned_end, board_order, is_active,
                 created_at, updated_at, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), ?, ?)');
            $ins->execute([
                $projectId, $taskNumber, $parentForInsert,
                $title,
                $description === '' ? null : $description,
                $target === '' ? null : $target,
                $statusIdIn, $priority,
                $plannedStart === '' ? null : $plannedStart,
                $plannedEnd === '' ? null : $plannedEnd,
                $newOrder,
                $viewerId, $viewerId,
            ]);
            $newTaskId = $db->lastInsertId();

            // Financial + progress fields — persisted via a follow-up
            // UPDATE keyed by newTaskId so the columns are conditional
            // (some installs may not have run the ALTER yet).
            $extra = task_tracker__financial_sets($shareAmount, $projectedAmount, $targetExp, $actualExp, $progressPct, $hasFin, $hasProgress);
            if ($extra['sets'] !== []) {
                $extra['params'][] = $newTaskId;
                $db->prepare('UPDATE task SET ' . implode(', ', $extra['sets']) . ' WHERE id = ?')
                   ->execute($extra['params']);
            }

            $db->prepare('UPDATE project SET next_task_number = next_task_number + 1, updated_at = NOW(), updated_by = ? WHERE id = ?')
               ->execute([$viewerId, $projectId]);

            $assignIns = $db->prepare('INSERT INTO task_assignment (task_id, seat_id, role, created_at, created_by) VALUES (?, ?, ?, NOW(), ?)');
            if ($primarySeat > 0) {
                $assignIns->execute([$newTaskId, $primarySeat, 'primary', $viewerId]);
            }
            foreach ($secondarySeat as $sid) {
                try {
                    $assignIns->execute([$newTaskId, $sid, 'secondary', $viewerId]);
                } catch (Throwable $e) { /* dupes ignored */ }
            }

            $hist = $db->prepare('INSERT INTO task_history (task_id, field_name, old_value, new_value, changed_by, changed_at) VALUES (?, ?, NULL, ?, ?, NOW())');
            $hist->execute([$newTaskId, 'created', 'Task ' . $project['code'] . '-' . $taskNumber . ' created', $viewerId]);
            if ($primarySeat > 0) {
                $hist->execute([$newTaskId, 'primary_seat', task_tracker__seat_name($seats, $primarySeat), $viewerId]);
            }
            if ($secondarySeat !== []) {
                $names = [];
                foreach ($secondarySeat as $sid) $names[] = task_tracker__seat_name($seats, $sid);
                $hist->execute([$newTaskId, 'secondary_seats', implode(', ', $names), $viewerId]);
            }

            $db->query('COMMIT');
            $flashAndReturn('Task ' . $project['code'] . '-' . $taskNumber . ' created.', 'success', $projectId, 0);
        } else {
            $changes = [];
            $mapping = [
                'title'         => ['old' => (string) ($existing['title'] ?? ''),        'new' => $title],
                'description'   => ['old' => (string) ($existing['description'] ?? ''), 'new' => $description],
                'target'        => ['old' => (string) ($existing['target'] ?? ''),      'new' => $target],
                'status'        => ['old' => (int) $existing['status_id'],              'new' => $statusIdIn],
                'priority'      => ['old' => (string) $existing['priority'],            'new' => $priority],
                'planned_start' => ['old' => substr((string) ($existing['planned_start'] ?? ''), 0, 10), 'new' => $plannedStart],
                'planned_end'   => ['old' => substr((string) ($existing['planned_end']   ?? ''), 0, 10), 'new' => $plannedEnd],
                'parent'        => ['old' => (int) ($existing['parent_id'] ?? 0),       'new' => (int) ($parentForInsert ?? 0)],
            ];
            foreach ($mapping as $field => $v) {
                if ((string) $v['old'] !== (string) $v['new']) {
                    $changes[] = ['field' => $field, 'old' => (string) $v['old'], 'new' => (string) $v['new']];
                }
            }

            $upd = $db->prepare('UPDATE task
                SET parent_id = ?, title = ?, description = ?, target = ?, status_id = ?, priority = ?,
                    planned_start = ?, planned_end = ?, updated_at = NOW(), updated_by = ?
                WHERE id = ?');
            $upd->execute([
                $parentForInsert,
                $title,
                $description === '' ? null : $description,
                $target === '' ? null : $target,
                $statusIdIn, $priority,
                $plannedStart === '' ? null : $plannedStart,
                $plannedEnd === '' ? null : $plannedEnd,
                $viewerId, $taskId,
            ]);

            // Financial + progress fields — same conditional shape as
            // the insert path.
            $extra = task_tracker__financial_sets($shareAmount, $projectedAmount, $targetExp, $actualExp, $progressPct, $hasFin, $hasProgress);
            if ($extra['sets'] !== []) {
                $extra['params'][] = $taskId;
                $db->prepare('UPDATE task SET ' . implode(', ', $extra['sets']) . ' WHERE id = ?')
                   ->execute($extra['params']);
            }

            // History rows for the financial fields (only when
            // something actually changed).
            $moneyEq = static fn($a, $b) => (float) ($a ?? 0) === (float) ($b ?? 0);
            $moneyLog = [
                'share_amount'       => [(float) ($existing['share_amount'] ?? 0),       $shareAmount],
                'projected_amount'   => [(float) ($existing['projected_amount'] ?? 0),   $projectedAmount],
                'target_expenditure' => [(float) ($existing['target_expenditure'] ?? 0), $targetExp],
                'actual_expenditure' => [(float) ($existing['actual_expenditure'] ?? 0), $actualExp],
            ];
            if ($hasFin) {
                foreach ($moneyLog as $field => $pair) {
                    if (!$moneyEq($pair[0], $pair[1])) {
                        $changes[] = ['field' => $field,
                            'old' => $pair[0] > 0 ? number_format($pair[0], 2, '.', '') : '',
                            'new' => $pair[1] === null ? '' : number_format($pair[1], 2, '.', '')];
                    }
                }
            }
            if ($hasProgress) {
                $oldPct = $existing['progress_pct'] === null ? null : (int) $existing['progress_pct'];
                if ($oldPct !== $progressPct) {
                    $changes[] = ['field' => 'progress_pct',
                        'old' => $oldPct === null ? '' : $oldPct . '%',
                        'new' => $progressPct === null ? '' : $progressPct . '%'];
                }
            }

            $oldPrimary   = (int) $existingAssignmentPayload['primary_seat_id'];
            $oldSecondary = $existingAssignmentPayload['secondary_seat_ids'];
            sort($oldSecondary); sort($secondarySeat);
            $primaryChanged   = $oldPrimary !== $primarySeat;
            $secondaryChanged = $oldSecondary !== $secondarySeat;

            if ($primaryChanged || $secondaryChanged) {
                $db->prepare('DELETE FROM task_assignment WHERE task_id = ?')->execute([$taskId]);
                $assignIns = $db->prepare('INSERT INTO task_assignment (task_id, seat_id, role, created_at, created_by) VALUES (?, ?, ?, NOW(), ?)');
                if ($primarySeat > 0) {
                    $assignIns->execute([$taskId, $primarySeat, 'primary', $viewerId]);
                }
                foreach ($secondarySeat as $sid) {
                    try {
                        $assignIns->execute([$taskId, $sid, 'secondary', $viewerId]);
                    } catch (Throwable $e) { /* dupe ignored */ }
                }
            }

            $hist = $db->prepare('INSERT INTO task_history (task_id, field_name, old_value, new_value, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, NOW())');
            foreach ($changes as $c) $hist->execute([$taskId, $c['field'], $c['old'] === '' ? null : $c['old'], $c['new'] === '' ? null : $c['new'], $viewerId]);
            if ($primaryChanged) {
                $hist->execute([$taskId, 'primary_seat',
                    $oldPrimary > 0 ? task_tracker__seat_name($seats, $oldPrimary) : null,
                    $primarySeat > 0 ? task_tracker__seat_name($seats, $primarySeat) : null,
                    $viewerId]);
            }
            if ($secondaryChanged) {
                $oldNames = array_map(static fn($sid) => task_tracker__seat_name($seats, $sid), $oldSecondary);
                $newNames = array_map(static fn($sid) => task_tracker__seat_name($seats, $sid), $secondarySeat);
                $hist->execute([$taskId, 'secondary_seats',
                    $oldNames === [] ? null : implode(', ', $oldNames),
                    $newNames === [] ? null : implode(', ', $newNames),
                    $viewerId]);
            }

            $db->query('COMMIT');
            $flashAndReturn('Task ' . $project['code'] . '-' . (int) $existing['task_number'] . ' updated.', 'success', $projectId, 0);
        }
    } catch (Throwable $e) {
        try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
        $flashAndReturn('Save failed: ' . $e->getMessage(), 'danger', $projectId, $taskId);
    }
}

/** Look up a seat's name from the preloaded seats array (used only for
 *  writing history rows on the same request that already has $seats). */
function task_tracker__seat_name(array $seats, int $seatId): string
{
    foreach ($seats as $s) if ($s['id'] === $seatId) return $s['name'];
    return '#' . $seatId;
}

/**
 * Build the SET fragment for the financial + progress columns as an
 * ['sets' => [...], 'params' => [...]] pair. Returns empty sets when
 * neither column group exists in the schema, so the caller skips the
 * follow-up UPDATE entirely.
 */
function task_tracker__financial_sets(?float $share, ?float $projected, ?float $target, ?float $actual, ?int $progress, bool $hasFin, bool $hasProgress): array
{
    $sets = []; $params = [];
    if ($hasFin) {
        $sets[] = 'share_amount = ?';       $params[] = $share;
        $sets[] = 'projected_amount = ?';   $params[] = $projected;
        $sets[] = 'target_expenditure = ?'; $params[] = $target;
        $sets[] = 'actual_expenditure = ?'; $params[] = $actual;
    }
    if ($hasProgress) {
        $sets[] = 'progress_pct = ?'; $params[] = $progress;
    }
    return ['sets' => $sets, 'params' => $params];
}

// When called with ?parent=<tid>, preselect Sub-activity + that parent.
$parentHint = (int) ($_GET['parent'] ?? 0);
if ($parentHint > 0 && $existing === null) {
    $ok = false;
    foreach ($activities as $a) { if ((int) $a['id'] === $parentHint) { $ok = true; break; } }
    if (!$ok) $parentHint = 0;
}

$formHasFin      = task_tracker_column_exists('task', 'share_amount');
$formHasProgress = task_tracker_column_exists('task', 'progress_pct');

$editing = $existing !== null;
$formValues = $existing !== null ? [
    'type'          => $existing['parent_id'] === null ? 'activity' : 'sub',
    'parent_id'     => (int) ($existing['parent_id'] ?? 0),
    'title'         => (string) $existing['title'],
    'description'   => (string) ($existing['description'] ?? ''),
    'target'        => (string) ($existing['target'] ?? ''),
    'status_id'     => (int) $existing['status_id'],
    'priority'      => (string) $existing['priority'],
    'planned_start' => substr((string) ($existing['planned_start'] ?? ''), 0, 10),
    'planned_end'   => substr((string) ($existing['planned_end']   ?? ''), 0, 10),
    'primary_seat_id'     => (int) $existingAssignmentPayload['primary_seat_id'],
    'primary_section_id'  => (int) $existingAssignmentPayload['primary_section_id'],
    'primary_division_id' => (int) $existingAssignmentPayload['primary_division_id'],
    'secondary_seat_ids'  => $existingAssignmentPayload['secondary_seat_ids'],
    'share_amount'        => $existing['share_amount']       ?? null,
    'projected_amount'    => $existing['projected_amount']   ?? null,
    'target_expenditure'  => $existing['target_expenditure'] ?? null,
    'actual_expenditure'  => $existing['actual_expenditure'] ?? null,
    'progress_pct'        => $existing['progress_pct']       ?? null,
] : [
    'type'          => $parentHint > 0 ? 'sub' : 'activity',
    'parent_id'     => $parentHint,
    'title'         => '',
    'description'   => '',
    'target'        => '',
    'status_id'     => $defaultStatusId,
    'priority'      => 'medium',
    'planned_start' => '',
    'planned_end'   => '',
    'primary_seat_id'     => 0,
    'primary_section_id'  => 0,
    'primary_division_id' => 0,
    'secondary_seat_ids'  => [],
    'share_amount'       => null,
    'projected_amount'   => null,
    'target_expenditure' => null,
    'actual_expenditure' => null,
    'progress_pct'       => null,
];

$pageTitle = $editing
    ? 'Edit task · ' . $project['code'] . '-' . (int) $existing['task_number']
    : 'New task · ' . $project['code'];

render_header('Task Tracker · ' . $pageTitle, ['main_container_class' => 'container-xl']);
render_page_header($pageTitle, [
    'icon' => 'bi-card-checklist',
    'subtitle' => $editing
        ? 'Edit the fields you need to change — every change writes a task_history row.'
        : 'Create a new activity or sub-activity in ' . htmlspecialchars((string) $project['name'], ENT_QUOTES, 'UTF-8') . '.',
    'actions' => '<a class="btn btn-light" href="/task_tracker_project_view.php?id=' . (int) $project['id'] . '"><i class="bi bi-arrow-left me-1"></i>Back to project</a>',
]);
?>

<form method="post" class="card">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="save">

    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Type</label>
                <div class="btn-group w-100" role="group">
                    <input type="radio" class="btn-check" name="type" id="typeActivity" value="activity" <?= $formValues['type'] === 'activity' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary" for="typeActivity"><i class="bi bi-diagram-2 me-1"></i>Activity</label>
                    <input type="radio" class="btn-check" name="type" id="typeSub" value="sub" <?= $formValues['type'] === 'sub' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary" for="typeSub"><i class="bi bi-diagram-3 me-1"></i>Sub-activity</label>
                </div>
                <div class="small text-muted mt-1">Sub-activities live under an activity.</div>
            </div>
            <div class="col-md-9" id="parentActivityWrap" style="display:none;">
                <label class="form-label" for="parentActivity">Parent activity <span class="text-danger">*</span></label>
                <select class="form-select" id="parentActivity" name="parent_id">
                    <option value="">— Select activity —</option>
                    <?php foreach ($activities as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $formValues['parent_id'] ? 'selected' : '' ?>>
                            <?= esc((string) $project['code']) ?>-<?= (int) $a['task_number'] ?> · <?= esc((string) $a['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="small text-muted mt-1">Only activities in the same project appear here.</div>
            </div>

            <div class="col-12">
                <label class="form-label" for="taskTitle">Title <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-lg" id="taskTitle" name="title"
                    value="<?= esc($formValues['title']) ?>" required maxlength="500" placeholder="One-line summary of what needs doing">
            </div>

            <div class="col-md-6">
                <label class="form-label" for="taskDesc">Description</label>
                <textarea class="form-control" id="taskDesc" name="description" rows="4" placeholder="Context, deliverables, notes"><?= esc($formValues['description']) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="taskTarget">Target</label>
                <textarea class="form-control" id="taskTarget" name="target" rows="4" placeholder="What done looks like — the acceptance criteria"><?= esc($formValues['target']) ?></textarea>
            </div>

            <div class="col-md-3">
                <label class="form-label" for="taskStatus">Status</label>
                <select class="form-select" id="taskStatus" name="status_id" required>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $formValues['status_id'] ? 'selected' : '' ?>>
                            <?= esc((string) $s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="taskPriority">Priority</label>
                <select class="form-select" id="taskPriority" name="priority" required>
                    <?php foreach (['lowest','low','medium','high','highest'] as $p): ?>
                        <option value="<?= esc($p) ?>" <?= $p === $formValues['priority'] ? 'selected' : '' ?>><?= esc(ucfirst($p)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="taskStart">Planned start</label>
                <input type="date" class="form-control" id="taskStart" name="planned_start" value="<?= esc($formValues['planned_start']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="taskEnd">Planned end</label>
                <input type="date" class="form-control" id="taskEnd" name="planned_end" value="<?= esc($formValues['planned_end']) ?>">
            </div>
        </div>

        <?php if ($formHasFin || $formHasProgress): ?>
        <hr class="my-4">
        <h6 class="text-uppercase small text-muted mb-3"><i class="bi bi-cash-coin me-1"></i>Budget &amp; progress</h6>
        <div class="row g-3">
            <?php if ($formHasFin): ?>
                <div class="col-md-3">
                    <label class="form-label" for="taskShare">Share amount (₹)</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="taskShare" name="share_amount"
                        value="<?= $formValues['share_amount'] === null ? '' : esc((string) $formValues['share_amount']) ?>"
                        placeholder="Allocated from project budget">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="taskProjected">Projected amount (₹)</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="taskProjected" name="projected_amount"
                        value="<?= $formValues['projected_amount'] === null ? '' : esc((string) $formValues['projected_amount']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="taskTargetExp">Target expenditure (₹)</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="taskTargetExp" name="target_expenditure"
                        value="<?= $formValues['target_expenditure'] === null ? '' : esc((string) $formValues['target_expenditure']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="taskActualExp">Actual expenditure (₹)</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="taskActualExp" name="actual_expenditure"
                        value="<?= $formValues['actual_expenditure'] === null ? '' : esc((string) $formValues['actual_expenditure']) ?>">
                    <div class="small text-muted mt-1">Balance = target − actual.</div>
                </div>
            <?php endif; ?>
            <?php if ($formHasProgress): ?>
                <div class="col-md-3">
                    <label class="form-label" for="taskProgress">Progress (%)</label>
                    <input type="number" min="0" max="100" class="form-control" id="taskProgress" name="progress_pct"
                        value="<?= $formValues['progress_pct'] === null ? '' : esc((string) $formValues['progress_pct']) ?>"
                        placeholder="0–100">
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <hr class="my-4">

        <h6 class="text-uppercase small text-muted mb-3"><i class="bi bi-person-check me-1"></i>Primary responsibility</h6>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="primaryDivision">Division</label>
                <select class="form-select" id="primaryDivision">
                    <option value="">— Select division —</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="primarySection">Section</label>
                <select class="form-select" id="primarySection">
                    <option value="">— Select section —</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="primarySeatSel">Seat</label>
                <select class="form-select" id="primarySeatSel">
                    <option value="">— Select seat —</option>
                </select>
                <input type="hidden" name="primary_seat_id" id="primarySeatHidden" value="<?= (int) $formValues['primary_seat_id'] ?>">
            </div>
        </div>

        <h6 class="text-uppercase small text-muted mb-3 mt-4"><i class="bi bi-people me-1"></i>Secondary responsibility</h6>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="secondaryDivision">Division</label>
                <select class="form-select" id="secondaryDivision">
                    <option value="">— Select division —</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="secondarySection">Section</label>
                <select class="form-select" id="secondarySection">
                    <option value="">— Select section —</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="secondarySeatSel">Seat</label>
                <div class="input-group">
                    <select class="form-select" id="secondarySeatSel">
                        <option value="">— Select seat —</option>
                    </select>
                    <button class="btn btn-outline-primary" type="button" id="addSecondaryBtn"><i class="bi bi-plus-lg"></i></button>
                </div>
            </div>
            <div class="col-12">
                <div id="secondaryChips" class="d-flex flex-wrap gap-2"></div>
                <div id="secondaryHiddenWrap"></div>
                <div class="small text-muted mt-1">Add any number of seats that share responsibility. The primary seat is excluded automatically.</div>
            </div>
        </div>
    </div>

    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="small text-muted">
            <?php if ($editing): ?>Task key: <strong class="font-monospace"><?= esc((string) $project['code']) ?>-<?= (int) $existing['task_number'] ?></strong>
            <?php else: ?>New key will be <strong class="font-monospace"><?= esc((string) $project['code']) ?>-<?= (int) $project['next_task_number'] ?></strong> (allocated atomically on save).<?php endif; ?>
        </div>
        <div>
            <a class="btn btn-light" href="/task_tracker_project_view.php?id=<?= (int) $project['id'] ?>">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?= $editing ? 'Save changes' : 'Create task' ?></button>
        </div>
    </div>
</form>

<script>
window.__taskTrackerPicker = {
    divisions:  <?= json_encode($divisions, JSON_UNESCAPED_UNICODE) ?>,
    sections:   <?= json_encode($sections,  JSON_UNESCAPED_UNICODE) ?>,
    seats:      <?= json_encode($seats,     JSON_UNESCAPED_UNICODE) ?>,
    preset:     <?= json_encode([
        'primary_division_id' => $formValues['primary_division_id'],
        'primary_section_id'  => $formValues['primary_section_id'],
        'primary_seat_id'     => $formValues['primary_seat_id'],
        'secondary_seat_ids'  => $formValues['secondary_seat_ids'],
    ], JSON_UNESCAPED_UNICODE) ?>,
};
</script>
<script>
(function () {
    const P = window.__taskTrackerPicker;

    const activityRadio  = document.getElementById('typeActivity');
    const subRadio       = document.getElementById('typeSub');
    const parentWrap     = document.getElementById('parentActivityWrap');
    const parentSel      = document.getElementById('parentActivity');
    const refreshTypeUI = () => {
        const isSub = subRadio.checked;
        parentWrap.style.display = isSub ? '' : 'none';
        parentSel.required = isSub;
        if (!isSub) parentSel.value = '';
    };
    activityRadio.addEventListener('change', refreshTypeUI);
    subRadio.addEventListener('change', refreshTypeUI);
    refreshTypeUI();

    const fillDivisions = (selectEl, currentVal) => {
        selectEl.innerHTML = '<option value="">— Select division —</option>';
        P.divisions.forEach(d => {
            const opt = document.createElement('option');
            opt.value = String(d.id); opt.textContent = d.name;
            if (String(d.id) === String(currentVal || '')) opt.selected = true;
            selectEl.appendChild(opt);
        });
    };
    const fillSections = (selectEl, divisionId, currentVal) => {
        selectEl.innerHTML = '<option value="">— Select section —</option>';
        P.sections
            .filter(s => String(s.division_id) === String(divisionId))
            .forEach(s => {
                const opt = document.createElement('option');
                opt.value = String(s.id); opt.textContent = s.name;
                if (String(s.id) === String(currentVal || '')) opt.selected = true;
                selectEl.appendChild(opt);
            });
    };
    const fillSeats = (selectEl, sectionId, currentVal, excludeIds = []) => {
        selectEl.innerHTML = '<option value="">— Select seat —</option>';
        P.seats
            .filter(s => String(s.section_id) === String(sectionId))
            .filter(s => !excludeIds.includes(s.id))
            .forEach(s => {
                const opt = document.createElement('option');
                opt.value = String(s.id);
                opt.textContent = s.seat_number ? (s.name + ' (' + s.seat_number + ')') : s.name;
                if (String(s.id) === String(currentVal || '')) opt.selected = true;
                selectEl.appendChild(opt);
            });
    };
    const seatById = (id) => P.seats.find(s => s.id === Number(id));

    // Primary picker
    const primDiv = document.getElementById('primaryDivision');
    const primSec = document.getElementById('primarySection');
    const primSeat = document.getElementById('primarySeatSel');
    const primHidden = document.getElementById('primarySeatHidden');

    fillDivisions(primDiv, P.preset.primary_division_id);
    fillSections(primSec, P.preset.primary_division_id, P.preset.primary_section_id);
    fillSeats(primSeat, P.preset.primary_section_id, P.preset.primary_seat_id);

    primDiv.addEventListener('change', () => {
        fillSections(primSec, primDiv.value, '');
        fillSeats(primSeat, '', '');
        primHidden.value = '';
    });
    primSec.addEventListener('change', () => {
        fillSeats(primSeat, primSec.value, '');
        primHidden.value = '';
    });
    primSeat.addEventListener('change', () => {
        primHidden.value = primSeat.value || '';
        rebuildSecondaryChips();
    });

    // Secondary picker: chips
    const secDiv = document.getElementById('secondaryDivision');
    const secSec = document.getElementById('secondarySection');
    const secSeat = document.getElementById('secondarySeatSel');
    const chipsEl = document.getElementById('secondaryChips');
    const hiddenWrap = document.getElementById('secondaryHiddenWrap');
    const addBtn = document.getElementById('addSecondaryBtn');
    let secondaryIds = Array.isArray(P.preset.secondary_seat_ids) ? P.preset.secondary_seat_ids.slice() : [];

    fillDivisions(secDiv, '');
    fillSections(secSec, '', '');
    fillSeats(secSeat, '', '');

    secDiv.addEventListener('change', () => { fillSections(secSec, secDiv.value, ''); fillSeats(secSeat, '', ''); });
    secSec.addEventListener('change', () => { fillSeats(secSeat, secSec.value, '', currentSecondaryExcludes()); });

    const currentSecondaryExcludes = () => {
        const list = secondaryIds.slice();
        if (primHidden.value) list.push(Number(primHidden.value));
        return list;
    };

    const rebuildSecondaryChips = () => {
        chipsEl.innerHTML = '';
        hiddenWrap.innerHTML = '';
        secondaryIds = secondaryIds.filter(id => Number(id) !== Number(primHidden.value || 0));
        secondaryIds.forEach(id => {
            const s = seatById(id);
            if (!s) return;
            const chip = document.createElement('span');
            chip.className = 'badge text-bg-light border d-inline-flex align-items-center gap-2 p-2';
            chip.innerHTML = '<i class="bi bi-person-workspace"></i><span></span>' +
                '<button type="button" class="btn-close btn-close-sm" aria-label="Remove"></button>';
            chip.querySelector('span').textContent = s.seat_number ? (s.name + ' (' + s.seat_number + ')') : s.name;
            chip.querySelector('button').addEventListener('click', () => {
                secondaryIds = secondaryIds.filter(x => Number(x) !== Number(id));
                rebuildSecondaryChips();
            });
            chipsEl.appendChild(chip);

            const hid = document.createElement('input');
            hid.type = 'hidden'; hid.name = 'secondary_seat_ids[]'; hid.value = String(id);
            hiddenWrap.appendChild(hid);
        });
        if (secSec.value) fillSeats(secSeat, secSec.value, '', currentSecondaryExcludes());
    };
    rebuildSecondaryChips();

    addBtn.addEventListener('click', () => {
        const v = Number(secSeat.value || 0);
        if (v <= 0) return;
        if (v === Number(primHidden.value || 0)) return;
        if (secondaryIds.some(id => Number(id) === v)) return;
        secondaryIds.push(v);
        rebuildSecondaryChips();
        secSeat.value = '';
    });
})();
</script>

<?php render_footer(); ?>

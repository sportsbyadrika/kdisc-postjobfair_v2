<?php
/**
 * Task Tracker · My Work.
 *
 * Personal view of every task where the viewer's currently-held seats
 * are named as primary or secondary responsibility. Grouped by status
 * column, ordered by planned end (earliest first), with a filter for
 * All / Primary only / Secondary only.
 *
 * Access: any authenticated user with at least one seat (or admin).
 * Admins with no seats see an empty view with a hint — this page is
 * scoped to seats, not roles.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';
require_auth();
task_tracker_bootstrap();

$viewer   = current_user();
$viewerId = (int) $viewer['id'];
$scope    = get_user_scope($viewerId);

$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'primary', 'secondary'], true)) $filter = 'all';

$seats = $scope['seat_ids'];
$rows  = [];

// task_assignment.user_id lets a meeting decision land on a user who
// doesn't hold any seat yet. The My Work query matches either path.
$hasUserAssignCol = task_tracker_column_exists('task_assignment', 'user_id');

if ($seats !== [] || $hasUserAssignCol) {
    $roleClause = $filter === 'primary'   ? "AND ta.role = 'primary'"
                : ($filter === 'secondary' ? "AND ta.role = 'secondary'" : '');
    // Build the "mine" predicate — seat IN list, user_id match, or both.
    $mineParts = []; $params = [];
    if ($seats !== []) {
        $ph = implode(',', array_fill(0, count($seats), '?'));
        $mineParts[] = "ta.seat_id IN ($ph)";
        $params = array_merge($params, $seats);
    }
    if ($hasUserAssignCol) {
        $mineParts[] = 'ta.user_id = ?';
        $params[] = $viewerId;
    }
    $mine = '(' . implode(' OR ', $mineParts) . ')';
    // Mirror for the my_role subquery.
    $mineSubParts = []; $subParams = [];
    if ($seats !== []) {
        $ph2 = implode(',', array_fill(0, count($seats), '?'));
        $mineSubParts[] = "ta2.seat_id IN ($ph2)";
        $subParams = array_merge($subParams, $seats);
    }
    if ($hasUserAssignCol) {
        $mineSubParts[] = 'ta2.user_id = ?';
        $subParams[] = $viewerId;
    }
    $mineSub = '(' . implode(' OR ', $mineSubParts) . ')';
    $sql = "SELECT DISTINCT t.*, p.code AS project_code, p.name AS project_name,
            s.name AS status_name, s.colour_token AS status_colour, s.sort_order AS status_sort,
            s.is_terminal, s.category AS status_category,
            (SELECT ta2.role FROM task_assignment ta2
                WHERE ta2.task_id = t.id AND $mineSub
                ORDER BY (ta2.role='primary') DESC LIMIT 1) AS my_role
        FROM task t
        INNER JOIN task_assignment ta ON ta.task_id = t.id AND $mine $roleClause
        INNER JOIN project p     ON p.id = t.project_id
        LEFT JOIN task_status s  ON s.id = t.status_id
        WHERE t.is_active = 1 AND p.is_active = 1
        ORDER BY s.sort_order ASC, s.id ASC, t.planned_end IS NULL, t.planned_end ASC, t.task_number ASC";
    // Params order: outer subquery (ta2) first, then the main join (ta).
    $allParams = array_merge($subParams, $params);
    $stmt = db()->prepare($sql);
    $stmt->execute($allParams);
    $rows = $stmt->fetchAll();
}

$byStatus = [];
foreach ($rows as $r) {
    $sid = (int) $r['status_id'];
    if (!isset($byStatus[$sid])) $byStatus[$sid] = ['name' => (string) $r['status_name'], 'tone' => (string) $r['status_colour'], 'is_terminal' => (int) $r['is_terminal'], 'rows' => []];
    $byStatus[$sid]['rows'][] = $r;
}

$priorityTones  = ['lowest' => 'secondary', 'low' => 'info', 'medium' => 'primary', 'high' => 'warning', 'highest' => 'danger'];

render_header('Task Tracker · My Work', ['main_container_class' => 'container-xl']);
render_page_header('My Work', [
    'icon' => 'bi-person-workspace',
    'subtitle' => 'Every task where any of your currently-held seats is named — primary or secondary.',
    'actions' => '<a class="btn btn-light" href="/task_tracker_projects.php"><i class="bi bi-briefcase me-1"></i>All projects</a>',
]);
?>

<div class="card mb-3">
    <div class="card-body d-flex justify-content-between align-items-center">
        <div>
            <div class="text-uppercase small text-muted">Your scope</div>
            <div>
                Level: <span class="badge text-bg-primary"><?= esc(str_replace('_', ' ', $scope['level'])) ?></span>
                · Seats: <strong><?= count($seats) ?></strong>
                · Sections: <strong><?= count($scope['section_ids']) ?></strong>
                · Divisions: <strong><?= count($scope['division_ids']) ?></strong>
            </div>
        </div>
        <div class="btn-group" role="group">
            <a class="btn btn-sm <?= $filter === 'all'       ? 'btn-primary' : 'btn-outline-primary' ?>" href="?filter=all">All</a>
            <a class="btn btn-sm <?= $filter === 'primary'   ? 'btn-primary' : 'btn-outline-primary' ?>" href="?filter=primary">Primary only</a>
            <a class="btn btn-sm <?= $filter === 'secondary' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?filter=secondary">Secondary only</a>
        </div>
    </div>
</div>

<?php if ($seats === [] && $rows === []): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>You do not currently hold any seat in the office hierarchy — My Work is scoped to seat assignments. Ask an administrator to assign you to a seat, or use the Kanban board via <a href="/task_tracker_projects.php">Projects</a>.
    </div>
<?php elseif ($rows === []): ?>
    <div class="empty-state"><i class="bi bi-inbox"></i>No tasks are assigned to you yet.</div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($byStatus as $sid => $bucket):
            $tone = $bucket['tone']; if ($tone === 'neutral') $tone = 'secondary';
        ?>
            <div class="col-md-6 col-xl-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center text-bg-<?= esc($tone) ?>">
                        <span class="fw-semibold">
                            <?php if ($bucket['is_terminal'] === 1): ?><i class="bi bi-check2-square me-1"></i><?php endif; ?>
                            <?= esc($bucket['name']) ?>
                        </span>
                        <span class="badge text-bg-light border"><?= count($bucket['rows']) ?></span>
                    </div>
                    <div class="card-body p-2" style="max-height:60vh;overflow-y:auto;">
                        <?php foreach ($bucket['rows'] as $t):
                            $priTone = $priorityTones[(string) $t['priority']] ?? 'secondary';
                            $pe = substr((string) ($t['planned_end'] ?? ''), 0, 10);
                            $today = date('Y-m-d');
                            $overdue = $pe !== '' && $pe < $today && $bucket['is_terminal'] !== 1;
                        ?>
                            <a href="/task_tracker_task_view.php?id=<?= (int) $t['id'] ?>" class="d-block text-decoration-none text-body p-2 rounded mb-2 border">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <span class="badge text-bg-light border font-monospace small"><?= esc((string) $t['project_code']) ?>-<?= (int) $t['task_number'] ?></span>
                                    <div class="d-flex gap-1">
                                        <?php if (($t['my_role'] ?? '') === 'primary'): ?>
                                            <span class="badge text-bg-primary" title="You are primary">P</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary" title="You are secondary">S</span>
                                        <?php endif; ?>
                                        <span class="badge text-bg-<?= esc($priTone) ?>"><?= esc(ucfirst((string) $t['priority'])) ?></span>
                                    </div>
                                </div>
                                <div class="fw-semibold mt-1"><?= esc((string) $t['title']) ?></div>
                                <div class="small text-muted mt-1">
                                    <i class="bi bi-briefcase me-1"></i><?= esc((string) $t['project_name']) ?>
                                    <?php if ($pe !== ''): ?>
                                        <span class="ms-2 <?= $overdue ? 'text-danger' : '' ?>">
                                            <i class="bi bi-calendar-event me-1"></i>due <?= esc(date('d/m/Y', strtotime($pe))) ?>
                                            <?php if ($overdue): ?> <span class="badge text-bg-danger">Overdue</span><?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php render_footer(); ?>

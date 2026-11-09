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
    // Meeting-decision tasks only surface here AFTER the chairperson
    // approves the source meeting. Non-meeting tasks (meeting_decision_id
    // IS NULL) are unaffected. The LEFT JOIN pattern tolerates a host
    // where the approved_at column was refused by ALTER — the OR branch
    // then falls through on NULL and nothing is filtered out.
    $approvalFilter = 't.meeting_decision_id IS NULL OR md_m.approved_at IS NOT NULL';
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
        LEFT JOIN meeting_decision md_d ON md_d.id = t.meeting_decision_id
        LEFT JOIN meeting md_m          ON md_m.id = md_d.meeting_id
        WHERE t.is_active = 1 AND p.is_active = 1
          AND ($approvalFilter)
        ORDER BY s.sort_order ASC, s.id ASC, t.planned_end IS NULL, t.planned_end ASC, t.task_number ASC";
    // Params order: outer subquery (ta2) first, then the main join (ta).
    $allParams = array_merge($subParams, $params);
    $stmt = db()->prepare($sql);
    $stmt->execute($allParams);
    $rows = $stmt->fetchAll();
}

// Group by project — the primary UX ask ("list of task in a card based
// table grouped by projects"). byStatus still gets built for the
// consolidated counts.
$today    = date('Y-m-d');
$dueSoon  = date('Y-m-d', strtotime('+3 days'));

$byProject = []; // project_id => ['code','name','rows'=>[]]
$countTotal = 0; $countDone = 0; $countOverdue = 0;
foreach ($rows as $r) {
    $pid = (int) $r['project_id'];
    if (!isset($byProject[$pid])) {
        $byProject[$pid] = [
            'id'   => $pid,
            'code' => (string) $r['project_code'],
            'name' => (string) $r['project_name'],
            'rows' => [],
            'done' => 0,
            'overdue' => 0,
        ];
    }
    $byProject[$pid]['rows'][] = $r;

    $countTotal++;
    $pe   = substr((string) ($r['planned_end'] ?? ''), 0, 10);
    $term = (int) ($r['is_terminal'] ?? 0) === 1;
    if ($term) { $countDone++; $byProject[$pid]['done']++; continue; }
    if ($pe !== '' && $pe < $today) { $countOverdue++; $byProject[$pid]['overdue']++; }
}
$countProjects = count($byProject);

// Status chip tone keyed on due date. Terminal (Done) tasks are grey;
// overdue → red; due within 3 days → amber; else green. Falls back to
// grey when no planned_end is set.
$dueChip = static function (?string $plannedEnd, bool $terminal) use ($today, $dueSoon): array {
    if ($terminal) return ['tone' => 'success', 'label' => 'Completed'];
    $pe = substr((string) ($plannedEnd ?? ''), 0, 10);
    if ($pe === '')           return ['tone' => 'secondary', 'label' => 'No due date'];
    if ($pe <  $today)        return ['tone' => 'danger',    'label' => 'Overdue · ' . date('d/m/Y', strtotime($pe))];
    if ($pe <= $dueSoon)      return ['tone' => 'warning',   'label' => 'Due ' . date('d/m/Y', strtotime($pe))];
    return                            ['tone' => 'success',  'label' => 'Due ' . date('d/m/Y', strtotime($pe))];
};

$priorityTones  = ['lowest' => 'secondary', 'low' => 'info', 'medium' => 'primary', 'high' => 'warning', 'highest' => 'danger'];

render_header('Task Tracker · My Work', ['main_container_class' => 'container-xl']);
render_page_header('My Work', [
    'icon' => 'bi-person-workspace',
    'subtitle' => 'Every task where any of your currently-held seats is named — primary or secondary.',
    'actions' => '<a class="btn btn-light" href="/task_tracker_projects.php"><i class="bi bi-briefcase me-1"></i>All projects</a>',
]);
?>

<!-- Consolidated counts — projects / tasks / completed / overdue. -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-primary h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100">
                    <p class="stat-label">Projects assigned</p>
                    <p class="stat-value"><?= number_format($countProjects) ?></p>
                </div>
                <span class="stat-icon-box tone-primary"><i class="bi bi-briefcase"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-info h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100">
                    <p class="stat-label">Total tasks</p>
                    <p class="stat-value"><?= number_format($countTotal) ?></p>
                </div>
                <span class="stat-icon-box tone-info"><i class="bi bi-list-check"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-success h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100">
                    <p class="stat-label">Completed</p>
                    <p class="stat-value"><?= number_format($countDone) ?></p>
                </div>
                <span class="stat-icon-box tone-success"><i class="bi bi-check2-circle"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-danger h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100">
                    <p class="stat-label">Overdue</p>
                    <p class="stat-value"><?= number_format($countOverdue) ?></p>
                </div>
                <span class="stat-icon-box tone-danger"><i class="bi bi-exclamation-triangle"></i></span>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
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
    <?php foreach ($byProject as $proj):
        $done     = (int) $proj['done'];
        $overdue  = (int) $proj['overdue'];
        $total    = count($proj['rows']);
    ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>
                    <i class="bi bi-briefcase text-primary me-1"></i>
                    <a href="/task_tracker_project_view.php?id=<?= (int) $proj['id'] ?>" class="text-decoration-none text-body">
                        <span class="badge text-bg-light border font-monospace me-1"><?= esc((string) $proj['code']) ?></span>
                        <strong><?= esc((string) $proj['name']) ?></strong>
                    </a>
                </span>
                <div class="d-flex gap-1">
                    <span class="badge text-bg-light border"><?= $total ?> task<?= $total === 1 ? '' : 's' ?></span>
                    <?php if ($done    > 0): ?><span class="badge text-bg-success-subtle text-success border"><?= $done ?> done</span><?php endif; ?>
                    <?php if ($overdue > 0): ?><span class="badge text-bg-danger-subtle text-danger border"><?= $overdue ?> overdue</span><?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-2 my-work-grid">
                    <?php foreach ($proj['rows'] as $t):
                        $priTone  = $priorityTones[(string) $t['priority']] ?? 'secondary';
                        $term     = (int) ($t['is_terminal'] ?? 0) === 1;
                        $chip     = $dueChip($t['planned_end'] ?? null, $term);
                        $statusT  = (string) ($t['status_colour'] ?? 'secondary'); if ($statusT === 'neutral') $statusT = 'secondary';
                        $pe       = substr((string) ($t['planned_end'] ?? ''), 0, 10);
                        $isOverdue= !$term && $pe !== '' && $pe < $today;
                    ?>
                        <div class="col-12 col-md-6 col-xl-4">
                            <a href="/task_tracker_task_view.php?id=<?= (int) $t['id'] ?>" class="my-work-card d-block text-decoration-none text-body border rounded p-2 h-100 <?= $isOverdue ? 'my-work-overdue' : '' ?>">
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
                                <div class="fw-semibold mt-2"><?= esc((string) $t['title']) ?></div>
                                <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-1">
                                    <span class="badge text-bg-<?= esc($statusT) ?>"><?= esc((string) ($t['status_name'] ?? '—')) ?></span>
                                    <span class="badge text-bg-<?= esc($chip['tone']) ?>"><?= esc($chip['label']) ?></span>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<style>
.my-work-card           { transition: box-shadow .15s, border-color .15s; }
.my-work-card:hover     { box-shadow: 0 2px 8px rgba(30,42,66,.08); border-color: var(--pjf-primary); }
.my-work-card.my-work-overdue        { background: #fef2f2; border-color: #fecaca !important; }
.my-work-card.my-work-overdue:hover  { border-color: #dc2626 !important; }
</style>

<?php render_footer(); ?>

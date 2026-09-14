<?php
/**
 * Task Tracker · Project view (interim list).
 *
 * This is the landing for a single project. Push 2c replaces the tasks
 * table here with a full Kanban board — the URL and query string stay
 * the same so no menu entries have to move. For now it exists so the
 * task CRUD form has somewhere sensible to return to, and so tasks are
 * visible end-to-end during Phase 2 review.
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

$projectId = (int) ($_GET['id'] ?? 0);
if ($projectId <= 0) {
    header('Location: /task_tracker_projects.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM project WHERE id = ? AND office_id = ? LIMIT 1');
$stmt->execute([$projectId, TASK_TRACKER_OFFICE_ID]);
$project = $stmt->fetch();
if ($project === false) {
    render_header('Project not found');
    render_page_header('Project not found', ['icon' => 'bi-exclamation-triangle',
        'actions' => '<a class="btn btn-light" href="/task_tracker_projects.php"><i class="bi bi-arrow-left me-1"></i>Back to Projects</a>']);
    echo '<div class="alert alert-warning">This project does not exist or you do not have access.</div>';
    render_footer();
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['task_tracker_flash']) && is_array($_SESSION['task_tracker_flash'])) {
    $flashMessage = (string) ($_SESSION['task_tracker_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['task_tracker_flash']['type'] ?? 'success');
    unset($_SESSION['task_tracker_flash']);
}

$tasksStmt = db()->prepare("SELECT
        t.*,
        s.name AS status_name, s.colour_token AS status_colour, s.category AS status_category,
        (SELECT n.name FROM task_assignment ta INNER JOIN office_hierarchy_nodes n ON n.id = ta.seat_id
            WHERE ta.task_id = t.id AND ta.role = 'primary' LIMIT 1) AS primary_seat_name,
        (SELECT u.name FROM task_assignment ta INNER JOIN office_hierarchy_nodes n ON n.id = ta.seat_id
            LEFT JOIN office_hierarchy_officer_history h ON h.node_id = n.id AND h.unassigned_at IS NULL
            LEFT JOIN users u ON u.id = h.officer_id
            WHERE ta.task_id = t.id AND ta.role = 'primary' LIMIT 1) AS primary_officer_name,
        (SELECT COUNT(*) FROM task_assignment ta WHERE ta.task_id = t.id AND ta.role = 'secondary') AS secondary_count,
        pt.title AS parent_title, pt.task_number AS parent_task_number
    FROM task t
    LEFT JOIN task_status s ON s.id = t.status_id
    LEFT JOIN task pt ON pt.id = t.parent_id
    WHERE t.project_id = ? AND t.is_active = 1
    ORDER BY COALESCE(t.parent_id, t.id) ASC, t.parent_id IS NULL DESC, t.task_number ASC");
$tasksStmt->execute([$projectId]);
$tasks = $tasksStmt->fetchAll();

$statusRows = db()->query('SELECT * FROM task_status WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
$statusById = [];
foreach ($statusRows as $s) $statusById[(int) $s['id']] = $s;

$canCreate = $canManage || in_array($scope['level'], ['office_head', 'division_head', 'section_head', 'staff'], true);

render_header('Task Tracker · ' . $project['name'], ['main_container_class' => 'container-fluid']);
render_page_header('Project · ' . $project['name'], [
    'icon' => 'bi-briefcase',
    'subtitle' => 'Task list (interim view — Push 2c replaces this with a drag-and-drop Kanban board).',
    'actions' => ($canCreate
            ? '<a class="btn btn-primary" href="/task_tracker_task.php?project=' . (int) $project['id'] . '"><i class="bi bi-plus-lg me-1"></i>New task</a>'
            : '')
        . '<a class="btn btn-light ms-2" href="/task_tracker_projects.php"><i class="bi bi-arrow-left me-1"></i>All projects</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-3">
        <div class="card card-stat accent-primary">
            <div class="stat-icon-box"><i class="bi bi-hash"></i></div>
            <div>
                <div class="stat-value font-monospace"><?= esc((string) $project['code']) ?></div>
                <div class="stat-label">Code</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-stat accent-info">
            <div class="stat-icon-box"><i class="bi bi-calendar3"></i></div>
            <div>
                <div class="stat-value"><?= esc((string) ($project['financial_year'] ?? '—')) ?: '—' ?></div>
                <div class="stat-label">Financial year</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-stat accent-success">
            <div class="stat-icon-box"><i class="bi bi-list-check"></i></div>
            <div>
                <div class="stat-value"><?= number_format(count($tasks)) ?></div>
                <div class="stat-label">Active task<?= count($tasks) === 1 ? '' : 's' ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-stat accent-neutral">
            <div class="stat-icon-box"><i class="bi bi-123"></i></div>
            <div>
                <div class="stat-value"><?= (int) $project['next_task_number'] ?></div>
                <div class="stat-label">Next task #</div>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul text-primary me-1"></i>Tasks</span>
        <span class="status-chip status-info"><?= number_format(count($tasks)) ?> task<?= count($tasks) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Task #</th>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Primary seat</th>
                    <th>Officer</th>
                    <th>Planned</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($tasks === []): ?>
                    <tr><td colspan="10"><div class="empty-state"><i class="bi bi-inbox"></i>No tasks yet in this project. <?= $canCreate ? 'Click "New task" to create one.' : 'Ask an administrator to create one.' ?></div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($tasks as $t): ?>
                    <?php
                        $tone = (string) ($t['status_colour'] ?? 'secondary');
                        $priorityTones = ['lowest' => 'secondary', 'low' => 'info', 'medium' => 'primary', 'high' => 'warning', 'highest' => 'danger'];
                        $priTone = $priorityTones[(string) $t['priority']] ?? 'secondary';
                        $isSub = !empty($t['parent_id']);
                        $canEdit = can_edit_task($viewerId);
                    ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td class="font-monospace small"><?= esc((string) $project['code']) ?>-<?= (int) $t['task_number'] ?></td>
                        <td>
                            <?php if ($isSub): ?><span class="text-muted me-1">↳</span><?php endif; ?>
                            <span class="fw-semibold"><?= esc((string) $t['title']) ?></span>
                            <?php if ($isSub && !empty($t['parent_title'])): ?>
                                <div class="small text-muted">under <?= esc((string) $t['parent_title']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isSub): ?>
                                <span class="badge text-bg-secondary">Sub-activity</span>
                            <?php else: ?>
                                <span class="badge text-bg-primary">Activity</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge text-bg-<?= esc($tone === 'neutral' ? 'secondary' : $tone) ?>"><?= esc((string) $t['status_name']) ?></span></td>
                        <td><span class="badge text-bg-<?= esc($priTone) ?>"><?= esc(ucfirst((string) $t['priority'])) ?></span></td>
                        <td class="small"><?= esc((string) ($t['primary_seat_name'] ?? '—')) ?></td>
                        <td class="small"><?= esc((string) ($t['primary_officer_name'] ?? '—')) ?></td>
                        <td class="small text-muted">
                            <?php
                                $ps = substr((string) ($t['planned_start'] ?? ''), 0, 10);
                                $pe = substr((string) ($t['planned_end']   ?? ''), 0, 10);
                                if ($ps === '' && $pe === '') echo '—';
                                elseif ($ps !== '' && $pe !== '') echo esc(date('d/m/Y', strtotime($ps)) . ' → ' . date('d/m/Y', strtotime($pe)));
                                elseif ($ps !== '') echo 'from ' . esc(date('d/m/Y', strtotime($ps)));
                                else echo 'by ' . esc(date('d/m/Y', strtotime($pe)));
                            ?>
                        </td>
                        <td class="text-end">
                            <?php if ($canEdit): ?>
                                <a class="btn btn-sm btn-outline-primary" href="/task_tracker_task.php?id=<?= (int) $t['id'] ?>">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        Task numbers are allocated atomically from <code>project.next_task_number</code> inside the insert transaction.
        The full Kanban board (Push 2c) will replace this table with columns from the status master.
    </div>
</div>

<?php render_footer(); ?>

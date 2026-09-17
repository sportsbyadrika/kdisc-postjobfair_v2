<?php
/**
 * Task Tracker · Project view — Kanban board.
 *
 * Columns are drawn from the active task_status rows, ordered by
 * sort_order. Cards inside each column are ordered by board_order
 * ASC. Drag-and-drop is powered by SortableJS 1.15.0 (single script
 * tag from cdnjs). Every drop posts to task_tracker_ajax_move.php,
 * which recomputes board_order as the midpoint of the neighbours and
 * writes a task_history row inside the same transaction as the update.
 *
 * A drop into a terminal-status column opens a small modal that asks
 * for the actual completion date before the move is sent — cancelling
 * the modal reverts the card back to where it came from.
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

$statuses = db()->query('SELECT * FROM task_status WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();

$tasksStmt = db()->prepare("SELECT
        t.*,
        s.name AS status_name, s.colour_token AS status_colour, s.category AS status_category, s.is_terminal,
        (SELECT n.name FROM task_assignment ta INNER JOIN office_hierarchy_nodes n ON n.id = ta.seat_id
            WHERE ta.task_id = t.id AND ta.role = 'primary' LIMIT 1) AS primary_seat_name,
        (SELECT u.name FROM task_assignment ta INNER JOIN office_hierarchy_nodes n ON n.id = ta.seat_id
            LEFT JOIN office_hierarchy_officer_history h ON h.node_id = n.id AND h.unassigned_at IS NULL
            LEFT JOIN users u ON u.id = h.officer_id
            WHERE ta.task_id = t.id AND ta.role = 'primary' LIMIT 1) AS primary_officer_name,
        (SELECT u.avatar_colour FROM task_assignment ta INNER JOIN office_hierarchy_nodes n ON n.id = ta.seat_id
            LEFT JOIN office_hierarchy_officer_history h ON h.node_id = n.id AND h.unassigned_at IS NULL
            LEFT JOIN users u ON u.id = h.officer_id
            WHERE ta.task_id = t.id AND ta.role = 'primary' LIMIT 1) AS primary_officer_colour,
        (SELECT COUNT(*) FROM task_assignment ta WHERE ta.task_id = t.id AND ta.role = 'secondary') AS secondary_count,
        (SELECT COUNT(*) FROM task sub WHERE sub.parent_id = t.id AND sub.is_active = 1) AS subtask_count,
        pt.title AS parent_title, pt.task_number AS parent_task_number
    FROM task t
    LEFT JOIN task_status s ON s.id = t.status_id
    LEFT JOIN task pt ON pt.id = t.parent_id
    WHERE t.project_id = ? AND t.is_active = 1
    ORDER BY t.status_id ASC, t.board_order ASC, t.id ASC");
$tasksStmt->execute([$projectId]);
$tasks = $tasksStmt->fetchAll();

$tasksByStatus = [];
foreach ($statuses as $s) $tasksByStatus[(int) $s['id']] = [];
foreach ($tasks as $t) {
    $sid = (int) $t['status_id'];
    if (!isset($tasksByStatus[$sid])) $tasksByStatus[$sid] = [];
    $tasksByStatus[$sid][] = $t;
}

$canCreate = $canManage || in_array($scope['level'], ['office_head', 'division_head', 'section_head', 'staff'], true);

$priorityTones  = ['lowest' => 'secondary', 'low' => 'info', 'medium' => 'primary', 'high' => 'warning', 'highest' => 'danger'];
$priorityLabels = ['lowest' => 'Lowest', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'highest' => 'Highest'];

$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    if ($parts === false || $parts === []) return '?';
    $first = $parts[0] !== '' ? mb_substr($parts[0], 0, 1) : '';
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return strtoupper(($first . $last) ?: '?');
};

render_header('Task Tracker · ' . $project['name'], ['main_container_class' => 'container-fluid']);
render_page_header('Project · ' . $project['name'], [
    'icon' => 'bi-kanban',
    'subtitle' => 'Drag cards across columns to change status. Cards land where you drop them; a drop into a terminal column asks for the actual completion date.',
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
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-primary h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100">
                    <p class="stat-label">Code</p>
                    <p class="stat-value font-monospace"><?= esc((string) $project['code']) ?></p>
                </div>
                <span class="stat-icon-box tone-primary"><i class="bi bi-hash"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-info h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100">
                    <p class="stat-label">Financial year</p>
                    <p class="stat-value"><?= esc((string) ($project['financial_year'] ?? '—')) ?: '—' ?></p>
                </div>
                <span class="stat-icon-box tone-info"><i class="bi bi-calendar3"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-success h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100">
                    <p class="stat-label">Active task<?= count($tasks) === 1 ? '' : 's' ?></p>
                    <p class="stat-value"><?= number_format(count($tasks)) ?></p>
                </div>
                <span class="stat-icon-box tone-success"><i class="bi bi-list-check"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-slate h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100">
                    <p class="stat-label">Next task #</p>
                    <p class="stat-value"><?= (int) $project['next_task_number'] ?></p>
                </div>
                <span class="stat-icon-box tone-slate"><i class="bi bi-123"></i></span>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-kanban text-primary me-1"></i>Kanban board</span>
        <span class="small text-muted">Positions save automatically on drop.</span>
    </div>
    <div class="card-body p-3">
        <div class="tt-board" id="ttBoard">
            <?php foreach ($statuses as $s):
                $sid = (int) $s['id'];
                $tone = (string) $s['colour_token'];
                $bootstrapTone = $tone === 'neutral' ? 'secondary' : $tone;
                $columnTasks = $tasksByStatus[$sid] ?? [];
            ?>
                <div class="tt-column"
                     data-status-id="<?= $sid ?>"
                     data-is-terminal="<?= (int) $s['is_terminal'] ?>"
                     data-status-name="<?= esc((string) $s['name']) ?>">
                    <div class="tt-column-header text-bg-<?= esc($bootstrapTone) ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">
                                <?php if ((int) $s['is_terminal'] === 1): ?><i class="bi bi-check2-square me-1"></i><?php endif; ?>
                                <?= esc((string) $s['name']) ?>
                            </span>
                            <span class="badge text-bg-light border tt-count"><?= count($columnTasks) ?></span>
                        </div>
                    </div>
                    <div class="tt-column-body" data-sortable="tasks" data-status-id="<?= $sid ?>">
                        <?php foreach ($columnTasks as $t):
                            $isSub = !empty($t['parent_id']);
                            $priTone = $priorityTones[(string) $t['priority']] ?? 'secondary';
                            $priLabel = $priorityLabels[(string) $t['priority']] ?? ucfirst((string) $t['priority']);
                            $officer = (string) ($t['primary_officer_name'] ?? '');
                            $officerColour = (string) ($t['primary_officer_colour'] ?? 'secondary');
                            if ($officerColour === 'neutral') $officerColour = 'secondary';
                        ?>
                            <div class="tt-card" data-task-id="<?= (int) $t['id'] ?>" data-status-id="<?= $sid ?>" data-open-url="/task_tracker_task_view.php?id=<?= (int) $t['id'] ?>" title="Open task">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <span class="badge text-bg-light border font-monospace small">
                                        <?= esc((string) $project['code']) ?>-<?= (int) $t['task_number'] ?>
                                    </span>
                                    <div class="d-flex align-items-center gap-1">
                                        <?php if ($isSub): ?><span class="badge text-bg-secondary" title="Sub-activity">Sub</span><?php endif; ?>
                                        <span class="badge text-bg-<?= esc($priTone) ?>"><?= esc($priLabel) ?></span>
                                    </div>
                                </div>
                                <div class="tt-card-title mt-2"><?= esc((string) $t['title']) ?></div>
                                <?php if ($isSub && !empty($t['parent_title'])): ?>
                                    <div class="small text-muted mt-1">↳ under <?= esc((string) $t['parent_title']) ?></div>
                                <?php endif; ?>
                                <?php if ((int) $t['subtask_count'] > 0): ?>
                                    <div class="small text-muted mt-1"><i class="bi bi-diagram-3 me-1"></i><?= (int) $t['subtask_count'] ?> sub-activit<?= (int) $t['subtask_count'] === 1 ? 'y' : 'ies' ?></div>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <div class="small text-muted">
                                        <?php
                                            $pe = substr((string) ($t['planned_end'] ?? ''), 0, 10);
                                            if ($pe !== '') echo '<i class="bi bi-calendar-event me-1"></i>' . esc(date('d/m/Y', strtotime($pe)));
                                            else echo '<span class="text-muted">no planned end</span>';
                                        ?>
                                    </div>
                                    <?php if ($officer !== ''): ?>
                                        <span class="tt-avatar text-bg-<?= esc($officerColour) ?>" title="<?= esc($officer) ?>">
                                            <?= esc($initials($officer)) ?>
                                        </span>
                                    <?php elseif (!empty($t['primary_seat_name'])): ?>
                                        <span class="tt-avatar text-bg-light border" title="Seat: <?= esc((string) $t['primary_seat_name']) ?>">
                                            <i class="bi bi-person-workspace"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($columnTasks === []): ?>
                            <div class="tt-empty text-muted small">Drop tasks here.</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-footer small text-muted">
        Card positions use fractional <code>board_order</code>: a mid-drop updates <strong>one row</strong> at midpoint, not the whole column.
    </div>
</div>

<!-- Actual-end date prompt for terminal-status drops -->
<div class="modal fade" id="terminalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-check2-square me-1"></i>Set actual completion date</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" data-tt-cancel="1"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">You dropped <strong id="terminalTaskLabel">this task</strong> into <strong id="terminalStatusLabel">a terminal column</strong>.</p>
                <label class="form-label" for="terminalDate">Actual completion date</label>
                <input type="date" class="form-control" id="terminalDate" value="<?= esc(date('Y-m-d')) ?>">
                <div class="form-text">This becomes the task's <code>actual_end</code>. History records the change.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-tt-cancel="1">Cancel</button>
                <button type="button" class="btn btn-primary" id="terminalConfirm"><i class="bi bi-check2-circle me-1"></i>Confirm</button>
            </div>
        </div>
    </div>
</div>

<style>
.tt-board { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 6px; }
.tt-column { flex: 0 0 300px; background: #f4f6fa; border-radius: 10px; display: flex; flex-direction: column; max-height: 76vh; }
.tt-column-header { padding: 10px 12px; border-radius: 10px 10px 0 0; }
.tt-column-body { padding: 10px; flex: 1; overflow-y: auto; min-height: 60px; display: flex; flex-direction: column; gap: 8px; }
.tt-card { position: relative; background: #fff; border: 1px solid #e3e6ee; border-radius: 8px; padding: 10px 12px; cursor: grab; box-shadow: 0 1px 2px rgba(30,42,66,.04); transition: box-shadow .15s ease, transform .05s ease; -webkit-user-drag: none; user-select: none; }
.tt-card a { -webkit-user-drag: none; }
.tt-card:hover { box-shadow: 0 2px 6px rgba(30,42,66,.08); }
.tt-card:active { cursor: grabbing; }
.tt-card-title { font-weight: 600; line-height: 1.3; }
.tt-empty { text-align: center; padding: 12px; border: 1px dashed #cfd6e4; border-radius: 8px; background: #fff; }
.tt-avatar { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; font-size: 12px; font-weight: 700; }
.tt-ghost { opacity: .5; }
.tt-drag { transform: rotate(1deg); }
.tt-card.stretched-link { text-decoration: none; color: inherit; }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"
    crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function () {
    const csrfToken = <?= json_encode(csrf_token()) ?>;
    const moveUrl   = '/task_tracker_ajax_move.php';
    const board = document.getElementById('ttBoard');
    if (!board) return;
    if (typeof Sortable === 'undefined') {
        console.error('Task Tracker: SortableJS failed to load — drag-and-drop disabled. Check network access to cdnjs.cloudflare.com.');
        return;
    }

    const modalEl = document.getElementById('terminalModal');
    const modal   = modalEl ? new bootstrap.Modal(modalEl) : null;
    let pendingMove = null;   // { card, oldParent, oldNext, taskId, newStatusId, prevId, nextId, statusName }

    const revert = () => {
        if (!pendingMove) return;
        const { card, oldParent, oldIndex } = pendingMove;
        // Slot the card back at its original index inside its original column.
        const cards = oldParent.querySelectorAll('.tt-card');
        if (oldIndex >= cards.length) {
            oldParent.appendChild(card);
        } else {
            oldParent.insertBefore(card, cards[oldIndex]);
        }
        // Restore / clean up "Drop tasks here" placeholders in every column.
        document.querySelectorAll('.tt-column-body').forEach(col => {
            const hasCards = col.querySelector('.tt-card');
            const hasEmpty = col.querySelector('.tt-empty');
            if (!hasCards && !hasEmpty) {
                const div = document.createElement('div');
                div.className = 'tt-empty text-muted small';
                div.textContent = 'Drop tasks here.';
                col.appendChild(div);
            }
            if (hasCards && hasEmpty) hasEmpty.remove();
        });
        pendingMove = null;
    };

    const applyCounts = (counts) => {
        Object.keys(counts).forEach(sid => {
            const col = board.querySelector(`.tt-column[data-status-id="${sid}"] .tt-count`);
            if (col) col.textContent = String(counts[sid] || 0);
        });
    };

    const send = async (payload) => {
        const body = new URLSearchParams();
        body.set('csrf_token',  csrfToken);
        body.set('task_id',     String(payload.task_id));
        body.set('new_status_id', String(payload.new_status_id));
        body.set('prev_task_id', payload.prev_task_id ? String(payload.prev_task_id) : '');
        body.set('next_task_id', payload.next_task_id ? String(payload.next_task_id) : '');
        if (payload.actual_end) body.set('actual_end', payload.actual_end);
        try {
            const res  = await fetch(moveUrl, { method: 'POST', body });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'Move failed.');
            applyCounts(json.column_counts || {});
            // Update the card's status_id attr so a subsequent move computes correctly.
            const card = board.querySelector(`.tt-card[data-task-id="${payload.task_id}"]`);
            if (card) card.setAttribute('data-status-id', String(payload.new_status_id));
        } catch (e) {
            alert('Move failed: ' + (e.message || e));
            revert();
        } finally {
            pendingMove = null;
        }
    };

    modalEl?.addEventListener('hidden.bs.modal', (ev) => {
        // Cancelled or dismissed: revert only if move is still pending.
        if (pendingMove) revert();
    });
    document.getElementById('terminalConfirm')?.addEventListener('click', () => {
        if (!pendingMove) return;
        const date = document.getElementById('terminalDate').value;
        if (!date) { alert('Pick a completion date, or cancel.'); return; }
        const p = pendingMove; pendingMove = null;
        modal?.hide();
        send({
            task_id: p.taskId,
            new_status_id: p.newStatusId,
            prev_task_id: p.prevId,
            next_task_id: p.nextId,
            actual_end: date,
        });
    });

    document.querySelectorAll('.tt-column-body').forEach(col => {
        new Sortable(col, {
            group: 'tt-tasks',
            animation: 150,
            ghostClass: 'tt-ghost',
            chosenClass: 'tt-drag',
            forceFallback: false,
            // Only real cards are draggable — the "Drop tasks here"
            // placeholder is also a direct child of the sortable
            // container and would otherwise be treated as a movable
            // item, blocking cross-column drops.
            draggable: '.tt-card',
            filter:    '.tt-empty',
            preventOnFilter: false,
            onStart: (evt) => {
                // Remove any "drop tasks here" placeholder in the source col.
                const empties = evt.from.querySelectorAll('.tt-empty');
                empties.forEach(e => e.remove());
            },
            onAdd: (evt) => {
                // Also strip the placeholder in the destination column.
                const empties = evt.to.querySelectorAll('.tt-empty');
                empties.forEach(e => e.remove());
            },
            onEnd: (evt) => {
                // Suppress the click that fires the moment the drop
                // finishes — otherwise every drop would also navigate
                // to the dropped card's detail page.
                window.__ttJustDragged = true;
                setTimeout(() => { window.__ttJustDragged = false; }, 300);
                const card = evt.item;
                const newCol = evt.to;
                const oldCol = evt.from;
                const newStatusId = Number(newCol.getAttribute('data-status-id') || 0);
                const isTerminal  = newCol.closest('.tt-column')?.getAttribute('data-is-terminal') === '1';
                const statusName  = newCol.closest('.tt-column')?.getAttribute('data-status-name') || '';
                const taskId      = Number(card.getAttribute('data-task-id') || 0);
                const prevEl      = card.previousElementSibling && card.previousElementSibling.classList.contains('tt-card') ? card.previousElementSibling : null;
                const nextEl      = card.nextElementSibling     && card.nextElementSibling.classList.contains('tt-card')     ? card.nextElementSibling     : null;
                const prevId      = prevEl ? Number(prevEl.getAttribute('data-task-id') || 0) : 0;
                const nextId      = nextEl ? Number(nextEl.getAttribute('data-task-id') || 0) : 0;

                // Restore empty placeholder in the source col if it's now empty.
                if (oldCol !== newCol && !oldCol.querySelector('.tt-card')) {
                    const div = document.createElement('div');
                    div.className = 'tt-empty text-muted small';
                    div.textContent = 'Drop tasks here.';
                    oldCol.appendChild(div);
                }

                pendingMove = {
                    card, oldParent: oldCol, oldIndex: evt.oldIndex,
                    taskId, newStatusId, prevId, nextId, statusName,
                };

                if (isTerminal) {
                    document.getElementById('terminalTaskLabel').textContent = card.querySelector('.tt-card-title')?.textContent?.trim() || 'this task';
                    document.getElementById('terminalStatusLabel').textContent = statusName;
                    modal?.show();
                    return;
                }
                const p = pendingMove; pendingMove = null;
                send({
                    task_id: p.taskId,
                    new_status_id: p.newStatusId,
                    prev_task_id: p.prevId,
                    next_task_id: p.nextId,
                });
            },
        });
    });

    // Card click → open the task detail page. Suppress if a drag just
    // ended so the mouseup at the end of a drop does not double as a
    // navigation click.
    board.addEventListener('click', (ev) => {
        if (window.__ttJustDragged) return;
        const card = ev.target.closest('.tt-card');
        if (!card) return;
        const url = card.getAttribute('data-open-url');
        if (url) window.location.href = url;
    });
})();
</script>

<?php render_footer(); ?>

<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';
require_task_tracker_admin();
task_tracker_bootstrap();

$viewer   = current_user();
$viewerId = (int) $viewer['id'];

$flashMessage = null;
$flashType    = 'success';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!empty($_SESSION['task_status_flash']) && is_array($_SESSION['task_status_flash'])) {
    $flashMessage = (string) ($_SESSION['task_status_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['task_status_flash']['type'] ?? 'success');
    unset($_SESSION['task_status_flash']);
}
$flashAndRedirect = static function (string $msg, string $type = 'success', string $url = '/task_tracker_status.php'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['task_status_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: ' . $url);
    exit;
};

/* -------------------------------------------------------------------- *
 * AJAX endpoint for the drag-to-reorder — first use of SortableJS in
 * the repo. Rewrites sort_order for every row in one transaction; the
 * status master is tiny (<20 rows in practice) so a full column
 * rewrite is cheaper than the fractional-order trickery the task
 * board will need.
 * -------------------------------------------------------------------- */
if (is_post() && ($_POST['action'] ?? '') === 'reorder') {
    header('Content-Type: application/json');
    if (!csrf_check()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'CSRF token invalid']);
        exit;
    }
    $orderRaw = $_POST['order'] ?? [];
    if (!is_array($orderRaw)) $orderRaw = [$orderRaw];
    $order = array_values(array_filter(array_map('intval', $orderRaw), static fn(int $v): bool => $v > 0));
    if ($order === []) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Empty order']);
        exit;
    }
    try {
        db()->query('START TRANSACTION');
        $step = 10;
        $sort = $step;
        foreach ($order as $id) {
            $u = db()->prepare('UPDATE task_status SET sort_order = ?, updated_at = NOW(), updated_by = ? WHERE id = ?');
            $u->execute([$sort, $viewerId, $id]);
            $sort += $step;
        }
        db()->query('COMMIT');
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        db()->query('ROLLBACK');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* -------------------------------------------------------------------- *
 * Full-page POST handlers: save + toggle-active.
 * -------------------------------------------------------------------- */
if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();
    $editId       = (int) ($_POST['id'] ?? 0);
    $name         = trim((string) ($_POST['name'] ?? ''));
    $category     = (string) ($_POST['category'] ?? 'todo');
    $colourToken  = trim((string) ($_POST['colour_token'] ?? 'secondary'));
    $isTerminal   = isset($_POST['is_terminal']) ? 1 : 0;
    $isActive     = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $flashMessage = 'Name is required.'; $flashType = 'danger';
    } elseif (!in_array($category, ['todo', 'inprogress', 'done'], true)) {
        $flashMessage = 'Category must be todo, inprogress or done.'; $flashType = 'danger';
    } else {
        try {
            if ($editId > 0) {
                $u = db()->prepare('UPDATE task_status
                    SET name = ?, category = ?, colour_token = ?, is_terminal = ?, is_active = ?,
                        updated_at = NOW(), updated_by = ?
                    WHERE id = ?');
                $u->execute([$name, $category, $colourToken, $isTerminal, $isActive, $viewerId, $editId]);
                $flashAndRedirect('Status updated.');
            } else {
                // Place the new status at the bottom by taking MAX(sort_order)+10.
                $maxOrder = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM task_status')->fetchColumn();
                $ins = db()->prepare('INSERT INTO task_status
                    (name, category, colour_token, is_terminal, is_active, sort_order, created_at, updated_at, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)');
                $ins->execute([$name, $category, $colourToken, $isTerminal, $isActive, $maxOrder + 10, $viewerId, $viewerId]);
                $flashAndRedirect('Status added.');
            }
        } catch (Throwable $e) {
            $flashMessage = 'Save failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
} elseif (is_post() && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_check_or_die();
    $tid = (int) ($_POST['id'] ?? 0);
    if ($tid > 0) {
        try {
            db()->prepare('UPDATE task_status SET is_active = 1 - is_active, updated_at = NOW(), updated_by = ? WHERE id = ?')
                ->execute([$viewerId, $tid]);
            $flashAndRedirect('Status toggled.');
        } catch (Throwable $e) {
            $flashMessage = 'Toggle failed: ' . $e->getMessage(); $flashType = 'danger';
        }
    }
}

$statuses = db()->query('SELECT * FROM task_status ORDER BY sort_order ASC, id ASC')->fetchAll();

// Available colour tokens for the picker — match the tone tokens used
// elsewhere so a status lozenge stays visually consistent with a badge.
$tones = ['primary', 'success', 'danger', 'warning', 'info', 'secondary', 'dark', 'neutral'];

$categoryLabels = [
    'todo'       => ['label' => 'To do',       'tone' => 'secondary'],
    'inprogress' => ['label' => 'In progress', 'tone' => 'primary'],
    'done'       => ['label' => 'Done',        'tone' => 'success'],
];

render_header('Task Tracker · Status master', ['main_container_class' => 'container-fluid']);
render_page_header('Task Tracker · Status master', [
    'icon'     => 'bi-columns-gap',
    'subtitle' => 'The Kanban columns. Drag rows to reorder; add / edit / deactivate below the list.',
    'actions'  => '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#statusModal" id="statusAddBtn"><i class="bi bi-plus-lg me-1"></i>New status</button>'
        . '<a class="btn btn-light ms-2" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-columns text-primary me-1"></i>Statuses</span>
        <span class="status-chip status-info"><?= number_format(count($statuses)) ?> row<?= count($statuses) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="statusTable">
            <thead>
                <tr>
                    <th style="width:44px;"></th>
                    <th>Sl No</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Colour</th>
                    <th>Terminal</th>
                    <th>Active</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody id="statusSortable">
                <?php if ($statuses === []): ?>
                    <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No statuses defined yet.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($statuses as $s): ?>
                    <?php
                        $cat = (string) ($s['category'] ?? 'todo');
                        $catInfo = $categoryLabels[$cat] ?? ['label' => $cat, 'tone' => 'neutral'];
                        $payload = htmlspecialchars(json_encode([
                            'id'           => (int) $s['id'],
                            'name'         => (string) $s['name'],
                            'category'     => $cat,
                            'colour_token' => (string) ($s['colour_token'] ?? 'secondary'),
                            'is_terminal'  => (int) ($s['is_terminal'] ?? 0),
                            'is_active'    => (int) ($s['is_active'] ?? 1),
                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                    ?>
                    <tr data-status-id="<?= (int) $s['id'] ?>">
                        <td class="js-drag-handle" style="cursor: grab;" title="Drag to reorder">
                            <i class="bi bi-grip-vertical text-muted"></i>
                        </td>
                        <td class="js-sl-no"><?= $i++ ?></td>
                        <td class="fw-semibold">
                            <span class="badge text-bg-<?= esc((string) ($s['colour_token'] ?? 'secondary')) ?> me-1 text-uppercase" style="font-size:11px; letter-spacing:.04em;">
                                <?= esc((string) $s['name']) ?>
                            </span>
                        </td>
                        <td><span class="badge text-bg-<?= esc($catInfo['tone']) ?>"><?= esc($catInfo['label']) ?></span></td>
                        <td class="small text-muted"><?= esc((string) ($s['colour_token'] ?? '—')) ?></td>
                        <td>
                            <?php if ((int) $s['is_terminal'] === 1): ?>
                                <span class="badge text-bg-success">Yes</span>
                            <?php else: ?>
                                <span class="text-muted">No</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $s['is_active'] === 1): ?>
                                <span class="badge text-bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <button type="button" class="btn btn-sm btn-outline-primary js-edit-status"
                                        data-payload="<?= $payload ?>"
                                        data-bs-toggle="modal" data-bs-target="#statusModal"
                                        title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Toggle active status?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                    <button class="btn btn-sm <?= (int) $s['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                        <i class="bi <?= (int) $s['is_active'] === 1 ? 'bi-slash-circle' : 'bi-check2-circle' ?>"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        <i class="bi bi-info-circle me-1"></i>Drag the <i class="bi bi-grip-vertical"></i> handle on the left to reorder. The board reads statuses in this order.
        Terminal statuses (Completed / Dropped) stop the workflow — dropping onto one asks for an actual completion date.
    </div>
</div>

<!-- Add / Edit modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="statusForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="statusModalId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="statusModalTitle"><i class="bi bi-plus-lg me-1"></i>New status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="statusModalName">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="statusModalName" name="name" required maxlength="80">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="statusModalCategory">Category</label>
                            <select class="form-select" id="statusModalCategory" name="category">
                                <option value="todo">To do</option>
                                <option value="inprogress">In progress</option>
                                <option value="done">Done</option>
                            </select>
                            <div class="small text-muted mt-1">Drives the column-header tone on the board.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="statusModalColour">Colour token</label>
                            <select class="form-select" id="statusModalColour" name="colour_token">
                                <?php foreach ($tones as $t): ?>
                                    <option value="<?= esc($t) ?>"><?= esc($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="small text-muted mt-1">The lozenge tone (matches existing badges).</div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="statusModalTerminal" name="is_terminal" value="1">
                                <label class="form-check-label" for="statusModalTerminal">Terminal</label>
                                <div class="small text-muted">Marks the task as finished — asks for an actual date.</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="statusModalActive" name="is_active" value="1" checked>
                                <label class="form-check-label" for="statusModalActive">Active</label>
                                <div class="small text-muted">Deactivated statuses stay for history but leave the board.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- SortableJS from cdnjs — first use in the repo. Every future
     drag-and-drop module MUST reuse this same version. See CLAUDE.md. -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"
        integrity="sha512-jXWEq3AbLnjxaKk/InbHTVQxvgSf07VXBqB+5D4YGvE8m4bfBGrLnKXjM4jGQjrs2Qs6/HqbcAkMhqUHqO7aRw=="
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function () {
    // Reorder — POST the id order after a drop, re-number the Sl No
    // column client-side so the operator sees the change take effect
    // before the server confirms.
    const tbody = document.getElementById('statusSortable');
    if (!tbody || typeof Sortable === 'undefined') return;
    const csrfToken = <?= json_encode(csrf_token()) ?>;

    Sortable.create(tbody, {
        handle: '.js-drag-handle',
        animation: 150,
        ghostClass: 'table-secondary',
        onEnd: async () => {
            const ids = Array.from(tbody.querySelectorAll('tr[data-status-id]'))
                             .map((tr) => tr.getAttribute('data-status-id'));
            // Re-number Sl No column optimistically.
            Array.from(tbody.querySelectorAll('.js-sl-no')).forEach((td, i) => { td.textContent = String(i + 1); });
            const body = new URLSearchParams();
            body.append('action', 'reorder');
            body.append('csrf_token', csrfToken);
            ids.forEach((id) => body.append('order[]', id));
            try {
                const res = await fetch('/task_tracker_status.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    body,
                });
                const data = await res.json();
                if (!data.ok) alert('Reorder failed: ' + (data.error || 'unknown'));
            } catch (err) {
                alert('Reorder failed: ' + err);
            }
        },
    });

    // Edit / Add hydration.
    const modalEl   = document.getElementById('statusModal');
    const modalTitle = document.getElementById('statusModalTitle');
    const modalId    = document.getElementById('statusModalId');
    const modalName  = document.getElementById('statusModalName');
    const modalCat   = document.getElementById('statusModalCategory');
    const modalCol   = document.getElementById('statusModalColour');
    const modalTerm  = document.getElementById('statusModalTerminal');
    const modalActv  = document.getElementById('statusModalActive');

    modalEl?.addEventListener('show.bs.modal', (ev) => {
        const trigger = ev.relatedTarget;
        if (!trigger) return;
        if (trigger.id === 'statusAddBtn') {
            modalTitle.innerHTML = '<i class="bi bi-plus-lg me-1"></i>New status';
            modalId.value = '0';
            modalName.value = '';
            modalCat.value = 'todo';
            modalCol.value = 'secondary';
            modalTerm.checked = false;
            modalActv.checked = true;
        } else if (trigger.classList.contains('js-edit-status')) {
            let d = {};
            try { d = JSON.parse(trigger.getAttribute('data-payload') || '{}'); } catch (e) {}
            modalTitle.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit status';
            modalId.value = d.id;
            modalName.value = d.name || '';
            modalCat.value = d.category || 'todo';
            modalCol.value = d.colour_token || 'secondary';
            modalTerm.checked = d.is_terminal === 1;
            modalActv.checked = d.is_active === 1;
        }
    });
})();
</script>

<?php render_footer(); ?>

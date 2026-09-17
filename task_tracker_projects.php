<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';
require_task_tracker_access();
task_tracker_bootstrap();

$viewer   = current_user();
$viewerId = (int) $viewer['id'];
$canManage = is_manage_admin($viewer);

$flashMessage = null;
$flashType    = 'success';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!empty($_SESSION['task_projects_flash']) && is_array($_SESSION['task_projects_flash'])) {
    $flashMessage = (string) ($_SESSION['task_projects_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['task_projects_flash']['type'] ?? 'success');
    unset($_SESSION['task_projects_flash']);
}
$flashAndRedirect = static function (string $msg, string $type = 'success', string $url = '/task_tracker_projects.php'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['task_projects_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: ' . $url);
    exit;
};

if (is_post() && ($_POST['action'] ?? '') === 'save') {
    if (!$canManage) { http_response_code(403); echo 'Only admins can create / edit projects.'; exit; }
    csrf_check_or_die();
    $editId       = (int) ($_POST['id'] ?? 0);
    $name         = trim((string) ($_POST['name'] ?? ''));
    $codeRaw      = trim((string) ($_POST['code'] ?? ''));
    $code         = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $codeRaw));
    $description  = trim((string) ($_POST['description'] ?? ''));
    $financialYear = trim((string) ($_POST['financial_year'] ?? ''));
    $startDate    = trim((string) ($_POST['start_date'] ?? ''));
    $endDate      = trim((string) ($_POST['end_date'] ?? ''));
    $isActive     = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $flashMessage = 'Name is required.'; $flashType = 'danger';
    } elseif ($code === '' || strlen($code) > 20) {
        $flashMessage = 'Code is required and must be up to 20 uppercase letters / digits.'; $flashType = 'danger';
    } elseif ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
        $flashMessage = 'Start date must be on or before end date.'; $flashType = 'danger';
    } else {
        try {
            if ($editId > 0) {
                $u = db()->prepare('UPDATE project
                    SET name = ?, code = ?, description = ?, financial_year = ?,
                        start_date = ?, end_date = ?, is_active = ?,
                        updated_at = NOW(), updated_by = ?
                    WHERE id = ?');
                $u->execute([
                    $name, $code,
                    $description === '' ? null : $description,
                    $financialYear === '' ? null : $financialYear,
                    $startDate === '' ? null : $startDate,
                    $endDate === '' ? null : $endDate,
                    $isActive, $viewerId, $editId,
                ]);
                $flashAndRedirect('Project updated.');
            } else {
                $ins = db()->prepare('INSERT INTO project
                    (office_id, name, code, description, financial_year, start_date, end_date, is_active, next_task_number,
                     created_at, updated_at, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), ?, ?)');
                $ins->execute([
                    TASK_TRACKER_OFFICE_ID,
                    $name, $code,
                    $description === '' ? null : $description,
                    $financialYear === '' ? null : $financialYear,
                    $startDate === '' ? null : $startDate,
                    $endDate === '' ? null : $endDate,
                    $isActive, $viewerId, $viewerId,
                ]);
                $flashAndRedirect('Project added. Code ' . $code . ' — task keys will be formed as ' . $code . '-N.');
            }
        } catch (Throwable $e) {
            $flashMessage = 'Save failed: ' . $e->getMessage(); $flashType = 'danger';
        }
    }
} elseif (is_post() && ($_POST['action'] ?? '') === 'toggle_active') {
    if (!$canManage) { http_response_code(403); echo 'Only admins can toggle projects.'; exit; }
    csrf_check_or_die();
    $tid = (int) ($_POST['id'] ?? 0);
    if ($tid > 0) {
        try {
            db()->prepare('UPDATE project SET is_active = 1 - is_active, updated_at = NOW(), updated_by = ? WHERE id = ?')
                ->execute([$viewerId, $tid]);
            $flashAndRedirect('Project toggled.');
        } catch (Throwable $e) {
            $flashMessage = 'Toggle failed: ' . $e->getMessage(); $flashType = 'danger';
        }
    }
}

$projects = db()->query("SELECT p.*, u.name AS created_by_name,
        (SELECT COUNT(*) FROM task t WHERE t.project_id = p.id AND t.is_active = 1) AS task_count
    FROM project p
    LEFT JOIN users u ON u.id = p.created_by
    WHERE p.office_id = " . (int) TASK_TRACKER_OFFICE_ID . "
    ORDER BY p.is_active DESC, p.id DESC")->fetchAll();

render_header('Task Tracker · Projects', ['main_container_class' => 'container-fluid']);
render_page_header('Task Tracker · Projects', [
    'icon'     => 'bi-briefcase',
    'subtitle' => 'Every project reads from this list. The code becomes the first half of every task key (ADM-142, ESTB-12).',
    'actions'  => ($canManage
            ? '<button type="button" class="btn btn-primary js-new-project" data-bs-toggle="modal" data-bs-target="#projectModal"><i class="bi bi-plus-lg me-1"></i>New project</button>'
            : '')
        . '<a class="btn btn-light ms-2" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul text-primary me-1"></i>Projects</span>
        <span class="status-chip status-info"><?= number_format(count($projects)) ?> project<?= count($projects) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Financial Year</th>
                    <th>Dates</th>
                    <th class="text-end">Tasks</th>
                    <th class="text-end">Next #</th>
                    <th>Status</th>
                    <?php if ($canManage): ?><th class="text-end">Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($projects === []): ?>
                    <tr><td colspan="<?= $canManage ? 9 : 8 ?>"><div class="empty-state"><i class="bi bi-inbox"></i>No projects yet<?= $canManage ? '. Click "New project" to create one.' : ' — ask an administrator to create one.' ?></div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($projects as $p): ?>
                    <?php
                        $active = ((int) $p['is_active']) === 1;
                        $payload = htmlspecialchars(json_encode([
                            'id'             => (int) $p['id'],
                            'name'           => (string) $p['name'],
                            'code'           => (string) $p['code'],
                            'description'    => (string) ($p['description'] ?? ''),
                            'financial_year' => (string) ($p['financial_year'] ?? ''),
                            'start_date'     => substr((string) ($p['start_date'] ?? ''), 0, 10),
                            'end_date'       => substr((string) ($p['end_date'] ?? ''), 0, 10),
                            'is_active'      => (int) $p['is_active'],
                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                    ?>
                    <tr class="<?= $active ? '' : 'text-muted' ?>">
                        <td><?= $i++ ?></td>
                        <td>
                            <a href="/task_tracker_project_view.php?id=<?= (int) $p['id'] ?>" class="text-decoration-none">
                                <span class="badge text-bg-light border font-monospace"><?= esc((string) $p['code']) ?></span>
                            </a>
                        </td>
                        <td class="fw-semibold">
                            <a href="/task_tracker_project_view.php?id=<?= (int) $p['id'] ?>" class="text-decoration-none text-body">
                                <?= esc((string) $p['name']) ?>
                            </a>
                        </td>
                        <td class="small"><?= esc((string) ($p['financial_year'] ?? '—')) ?: '—' ?></td>
                        <td class="small text-muted">
                            <?php
                                $s = substr((string) ($p['start_date'] ?? ''), 0, 10);
                                $e = substr((string) ($p['end_date'] ?? ''), 0, 10);
                                if ($s === '' && $e === '') echo '—';
                                elseif ($s !== '' && $e !== '') echo esc(date('d/m/Y', strtotime($s)) . ' → ' . date('d/m/Y', strtotime($e)));
                                elseif ($s !== '') echo 'from ' . esc(date('d/m/Y', strtotime($s)));
                                else echo 'to ' . esc(date('d/m/Y', strtotime($e)));
                            ?>
                        </td>
                        <td class="text-end fw-bold">
                            <a href="/task_tracker_project_view.php?id=<?= (int) $p['id'] ?>" class="text-decoration-none text-body"><?= number_format((int) $p['task_count']) ?></a>
                        </td>
                        <td class="text-end small text-muted"><?= (int) $p['next_task_number'] ?></td>
                        <td>
                            <?php if ($active): ?><span class="badge text-bg-success">Active</span>
                            <?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?>
                        </td>
                        <?php if ($canManage): ?>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-primary js-edit-project"
                                            data-payload="<?= $payload ?>"
                                            data-bs-toggle="modal" data-bs-target="#projectModal">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Toggle status?');">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                        <button class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                            <i class="bi <?= $active ? 'bi-slash-circle' : 'bi-check2-circle' ?>"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        <strong>Code</strong> is uppercase A–Z / 0–9 only, up to 20 characters (deliberately short — it appears in every task key). <strong>Next #</strong> shows what number the next created task will get; the value grows atomically inside the same transaction as the task insert.
    </div>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="projectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="projectForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="projectModalId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="projectModalTitle"><i class="bi bi-plus-lg me-1"></i>New project</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="projectModalName">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="projectModalName" name="name" required maxlength="200">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="projectModalCode">Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control text-uppercase font-monospace" id="projectModalCode" name="code" required maxlength="20" pattern="[A-Za-z0-9]+" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');">
                            <div class="small text-muted mt-1">Short key that appears in every task ID (ADM-142).</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="projectModalFY">Financial year</label>
                            <input type="text" class="form-control" id="projectModalFY" name="financial_year" placeholder="e.g. 2024-25" maxlength="20">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="projectModalStart">Start date</label>
                            <input type="date" class="form-control" id="projectModalStart" name="start_date">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="projectModalEnd">End date</label>
                            <input type="date" class="form-control" id="projectModalEnd" name="end_date">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="projectModalDesc">Description</label>
                            <textarea class="form-control" id="projectModalDesc" name="description" rows="3"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="projectModalActive" name="is_active" value="1" checked>
                                <label class="form-check-label" for="projectModalActive">Active</label>
                                <div class="small text-muted">Deactivating a project hides its tasks from the board but keeps history.</div>
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
<script>
(function () {
    const modalEl = document.getElementById('projectModal');
    modalEl?.addEventListener('show.bs.modal', (ev) => {
        const t = ev.relatedTarget;
        if (!t) return;
        const title = document.getElementById('projectModalTitle');
        const setV = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
        const setC = (id, v) => { const el = document.getElementById(id); if (el) el.checked = v; };
        if (t.classList.contains('js-new-project')) {
            title.innerHTML = '<i class="bi bi-plus-lg me-1"></i>New project';
            setV('projectModalId', '0');
            setV('projectModalName', ''); setV('projectModalCode', '');
            setV('projectModalFY', ''); setV('projectModalStart', ''); setV('projectModalEnd', '');
            setV('projectModalDesc', ''); setC('projectModalActive', true);
        } else if (t.classList.contains('js-edit-project')) {
            let d = {};
            try { d = JSON.parse(t.getAttribute('data-payload') || '{}'); } catch (e) {}
            title.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit project';
            setV('projectModalId', d.id);
            setV('projectModalName', d.name || ''); setV('projectModalCode', d.code || '');
            setV('projectModalFY', d.financial_year || '');
            setV('projectModalStart', d.start_date || ''); setV('projectModalEnd', d.end_date || '');
            setV('projectModalDesc', d.description || '');
            setC('projectModalActive', d.is_active === 1);
        }
    });
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>

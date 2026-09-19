<?php
/**
 * Task Tracker · Financial Year master.
 *
 * The list every project's Financial Year dropdown reads from. Kept
 * as a master (rather than free-typed) so "FY 2025-26" doesn't drift
 * into "2025-26", "25/26", "25-26" and back across teams.
 *
 * Access: Administrator / DSM Admin only. Every state-changing POST
 * carries a CSRF token, checked with csrf_check_or_die().
 */

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
if (!empty($_SESSION['task_fy_flash']) && is_array($_SESSION['task_fy_flash'])) {
    $flashMessage = (string) ($_SESSION['task_fy_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['task_fy_flash']['type'] ?? 'success');
    unset($_SESSION['task_fy_flash']);
}
$flashAndRedirect = static function (string $msg, string $type = 'success', string $url = '/task_tracker_financial_years.php'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['task_fy_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: ' . $url);
    exit;
};

if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();
    $editId    = (int) ($_POST['id'] ?? 0);
    $codeRaw   = trim((string) ($_POST['code']  ?? ''));
    $code      = strtoupper(preg_replace('/[^A-Z0-9\-\/]+/i', '', $codeRaw));
    $label     = trim((string) ($_POST['label'] ?? ''));
    $isActive  = isset($_POST['is_active']) ? 1 : 0;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    if ($code === '' || strlen($code) > 20) {
        $flashMessage = 'Code is required and must be up to 20 characters (letters, digits, - or /).'; $flashType = 'danger';
    } else {
        try {
            if ($editId > 0) {
                db()->prepare('UPDATE financial_year
                    SET code = ?, label = ?, is_active = ?, sort_order = ?, updated_at = NOW(), updated_by = ?
                    WHERE id = ?')
                    ->execute([$code, $label === '' ? null : $label, $isActive, $sortOrder, $viewerId, $editId]);
                $flashAndRedirect('Financial year updated.');
            } else {
                db()->prepare('INSERT INTO financial_year
                    (code, label, is_active, sort_order, created_at, updated_at, created_by, updated_by)
                    VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?)')
                    ->execute([$code, $label === '' ? null : $label, $isActive, $sortOrder, $viewerId, $viewerId]);
                $flashAndRedirect('Financial year added.');
            }
        } catch (Throwable $e) {
            $flashMessage = 'Save failed: ' . $e->getMessage(); $flashType = 'danger';
        }
    }
} elseif (is_post() && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_check_or_die();
    $tid = (int) ($_POST['id'] ?? 0);
    if ($tid > 0) {
        try {
            db()->prepare('UPDATE financial_year SET is_active = 1 - is_active, updated_at = NOW(), updated_by = ? WHERE id = ?')
                ->execute([$viewerId, $tid]);
            $flashAndRedirect('Toggled.');
        } catch (Throwable $e) {
            $flashMessage = 'Toggle failed: ' . $e->getMessage(); $flashType = 'danger';
        }
    }
}

$rows = db()->query('SELECT f.*,
        (SELECT COUNT(*) FROM project p WHERE p.financial_year = f.code) AS project_count
    FROM financial_year f
    ORDER BY f.sort_order ASC, f.code ASC')->fetchAll();

render_header('Task Tracker · Financial Year master', ['main_container_class' => 'container-xl']);
render_page_header('Task Tracker · Financial Year master', [
    'icon'     => 'bi-calendar3',
    'subtitle' => 'The list every project\'s Financial Year dropdown reads from.',
    'actions'  => '<button type="button" class="btn btn-primary js-new-fy" data-bs-toggle="modal" data-bs-target="#fyModal"><i class="bi bi-plus-lg me-1"></i>New financial year</button>
        <a class="btn btn-light ms-2" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar3 text-primary me-1"></i>Financial years</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> row<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Code</th>
                    <th>Label</th>
                    <th class="text-end">Sort order</th>
                    <th class="text-end">Projects</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No financial years yet. Click "New financial year" to add one.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($rows as $r): ?>
                    <?php
                        $active = ((int) $r['is_active']) === 1;
                        $payload = htmlspecialchars(json_encode([
                            'id'         => (int) $r['id'],
                            'code'       => (string) $r['code'],
                            'label'      => (string) ($r['label'] ?? ''),
                            'sort_order' => (int) $r['sort_order'],
                            'is_active'  => (int) $r['is_active'],
                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                    ?>
                    <tr class="<?= $active ? '' : 'text-muted' ?>">
                        <td><?= $i++ ?></td>
                        <td><span class="badge text-bg-light border font-monospace"><?= esc((string) $r['code']) ?></span></td>
                        <td><?= esc((string) ($r['label'] ?? '')) ?></td>
                        <td class="text-end"><?= (int) $r['sort_order'] ?></td>
                        <td class="text-end fw-bold"><?= number_format((int) $r['project_count']) ?></td>
                        <td>
                            <?php if ($active): ?><span class="badge text-bg-success">Active</span>
                            <?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <button type="button" class="btn btn-sm btn-outline-primary js-edit-fy"
                                        data-payload="<?= $payload ?>"
                                        data-bs-toggle="modal" data-bs-target="#fyModal">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Toggle status?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                        <i class="bi <?= $active ? 'bi-slash-circle' : 'bi-check2-circle' ?>"></i>
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
        <strong>Code</strong> is what appears on projects and reports (e.g. <code>2025-26</code>). <strong>Label</strong> is optional and shown in the dropdown next to the code. Deactivating a year hides it from the picker on new projects but keeps history on already-tagged ones.
    </div>
</div>

<div class="modal fade" id="fyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="fyModalId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="fyModalTitle"><i class="bi bi-plus-lg me-1"></i>New financial year</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="fyModalCode">Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control text-uppercase font-monospace" id="fyModalCode" name="code" required maxlength="20" placeholder="2025-26"
                                pattern="[A-Za-z0-9\-/]+"
                                oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9\-/]/g, '');">
                            <div class="small text-muted mt-1">Letters, digits, <code>-</code> and <code>/</code> only.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="fyModalSort">Sort order</label>
                            <input type="number" class="form-control" id="fyModalSort" name="sort_order" value="10">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="fyModalLabel">Label</label>
                            <input type="text" class="form-control" id="fyModalLabel" name="label" maxlength="120" placeholder="FY 2025-26">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="fyModalActive" name="is_active" value="1" checked>
                                <label class="form-check-label" for="fyModalActive">Active</label>
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
    const modalEl = document.getElementById('fyModal');
    modalEl?.addEventListener('show.bs.modal', (ev) => {
        const t = ev.relatedTarget; if (!t) return;
        const title = document.getElementById('fyModalTitle');
        const setV = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
        const setC = (id, v) => { const el = document.getElementById(id); if (el) el.checked = v; };
        if (t.classList.contains('js-new-fy')) {
            title.innerHTML = '<i class="bi bi-plus-lg me-1"></i>New financial year';
            setV('fyModalId', '0'); setV('fyModalCode', ''); setV('fyModalLabel', '');
            setV('fyModalSort', '10'); setC('fyModalActive', true);
        } else if (t.classList.contains('js-edit-fy')) {
            let d = {}; try { d = JSON.parse(t.getAttribute('data-payload') || '{}'); } catch (e) {}
            title.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit financial year';
            setV('fyModalId', d.id);
            setV('fyModalCode', d.code || ''); setV('fyModalLabel', d.label || '');
            setV('fyModalSort', d.sort_order || 10); setC('fyModalActive', d.is_active === 1);
        }
    });
})();
</script>

<?php render_footer(); ?>

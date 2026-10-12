<?php
/**
 * LOI brochure · Template master.
 * Admin-only. Lists brochure templates. Create / rename / default /
 * deactivate here. Per-page editor lives on
 * loi_brochure_template_edit.php — click any row to open it.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/loi_brochure_helpers.php';
require_auth();

$viewer = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
loi_brochure_bootstrap();

if (!is_manage_admin($viewer) && !user_can_admin_module($viewerId, 'loi_jobs')) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only an LOI & Jobs admin can manage brochure templates.</div>';
    render_footer(); exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['loi_bt_flash']) && is_array($_SESSION['loi_bt_flash'])) {
    $flashMessage = (string) ($_SESSION['loi_bt_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['loi_bt_flash']['type'] ?? 'success');
    unset($_SESSION['loi_bt_flash']);
}
$flashAndBack = static function (string $msg, string $type = 'success', int $openId = 0): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['loi_bt_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: ' . ($openId > 0 ? '/loi_brochure_template_edit.php?id=' . $openId : '/loi_brochure_templates.php'));
    exit;
};

if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $desc = trim((string) ($_POST['description'] ?? ''));
    $isDefault = isset($_POST['is_default']) ? 1 : 0;
    $isActive  = isset($_POST['is_active'])  ? 1 : 0;
    if ($name === '') $flashAndBack('Name is required.', 'danger');
    try {
        if ($id > 0) {
            db()->prepare('UPDATE loi_brochure_template SET name = ?, description = ?, is_default = ?, is_active = ?, updated_at = NOW(), updated_by = ? WHERE id = ?')
                ->execute([$name, $desc === '' ? null : $desc, $isDefault, $isActive, $viewerId, $id]);
            if ($isDefault === 1) db()->prepare('UPDATE loi_brochure_template SET is_default = 0 WHERE id <> ?')->execute([$id]);
            $flashAndBack('Template updated.', 'success', $id);
        } else {
            db()->prepare('INSERT INTO loi_brochure_template (name, description, is_default, is_active, created_at, updated_at, created_by, updated_by) VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?)')
                ->execute([$name, $desc === '' ? null : $desc, $isDefault, $isActive, $viewerId, $viewerId]);
            $newId = (int) db()->lastInsertId();
            if ($isDefault === 1) db()->prepare('UPDATE loi_brochure_template SET is_default = 0 WHERE id <> ?')->execute([$newId]);
            // Seed one blank page so the editor has something to open.
            db()->prepare('INSERT INTO loi_brochure_page (template_id, page_number, name, config_json, created_at, updated_at) VALUES (?, 1, ?, ?, NOW(), NOW())')
                ->execute([$newId, 'Page 1', json_encode(['labels' => []])]);
            $flashAndBack('Template created. Click a page to lay out labels and upload a background.', 'success', $newId);
        }
    } catch (Throwable $e) { $flashAndBack('Save failed: ' . $e->getMessage(), 'danger'); }
}
if (is_post() && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_check_or_die();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) $flashAndBack('Missing id.', 'danger');
    db()->prepare('UPDATE loi_brochure_template SET is_active = 1 - is_active, updated_at = NOW(), updated_by = ? WHERE id = ?')->execute([$viewerId, $id]);
    $flashAndBack('Toggled.');
}

$rows = [];
try {
    $rows = db()->query("SELECT t.*,
            (SELECT COUNT(*) FROM loi_brochure_page WHERE template_id = t.id) AS page_count,
            (SELECT COUNT(*) FROM loi_brochure WHERE template_id = t.id) AS brochure_count,
            u.name AS created_by_name
        FROM loi_brochure_template t
        LEFT JOIN users u ON u.id = t.created_by
        ORDER BY t.is_default DESC, t.is_active DESC, t.name ASC")->fetchAll();
} catch (Throwable $e) { /* empty */ }

render_header('LOI & Jobs · Brochure templates', ['main_container_class' => 'container-xl']);
render_page_header('LOI & Jobs · Brochure templates', [
    'icon' => 'bi-file-earmark-image',
    'subtitle' => 'Reusable multi-page brochure layouts. Upload A4 background images on each page and drag the free-text labels over them. Any LOI user can then generate a brochure PDF from a chosen template + a set of jobs.',
    'actions' => '<button type="button" class="btn btn-primary js-new-tpl" data-bs-toggle="modal" data-bs-target="#tplModal"><i class="bi bi-plus-lg me-1"></i>New template</button>
        <a class="btn btn-light ms-2" href="/loi_brochures.php"><i class="bi bi-arrow-left me-1"></i>Back to Brochures</a>',
]);
?>

<?php if ($flashMessage !== null): ?><div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div><?php endif; ?>
<?php if (!loi_brochure_dompdf_available()): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i><strong>Dompdf is not installed.</strong> The brochure will still render as an A4 HTML page that you can save as PDF through your browser's print dialog. For a true one-click PDF, install Dompdf under <code>vendor/dompdf/</code>.</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><i class="bi bi-file-earmark-image text-primary me-1"></i>Templates</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Sl</th><th>Name</th><th>Description</th><th>Pages</th><th>Used by</th><th>Status</th><th class="text-end">Action</th></tr></thead>
            <tbody>
                <?php if ($rows === []): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No templates yet. Click <strong>New template</strong>.</div></td></tr><?php endif; ?>
                <?php $i = 1; foreach ($rows as $r):
                    $active = ((int) $r['is_active']) === 1;
                    $payload = htmlspecialchars(json_encode([
                        'id' => (int) $r['id'], 'name' => (string) $r['name'],
                        'description' => (string) ($r['description'] ?? ''),
                        'is_default' => (int) $r['is_default'], 'is_active' => (int) $r['is_active'],
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                ?>
                <tr class="<?= $active ? '' : 'text-muted' ?>">
                    <td><?= $i++ ?></td>
                    <td class="fw-semibold">
                        <a href="/loi_brochure_template_edit.php?id=<?= (int) $r['id'] ?>" class="text-decoration-none"><?= esc((string) $r['name']) ?></a>
                        <?php if ((int) $r['is_default'] === 1): ?><span class="badge text-bg-primary ms-2">Default</span><?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= esc(mb_substr((string) ($r['description'] ?? ''), 0, 120)) ?></td>
                    <td class="small"><?= (int) $r['page_count'] ?></td>
                    <td class="small"><?= (int) $r['brochure_count'] ?> brochure<?= (int) $r['brochure_count'] === 1 ? '' : 's' ?></td>
                    <td><?php if ($active): ?><span class="badge text-bg-success">Active</span><?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?></td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <a class="btn btn-sm btn-outline-primary" href="/loi_brochure_template_edit.php?id=<?= (int) $r['id'] ?>" title="Open editor"><i class="bi bi-pencil-square"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-primary js-edit-tpl" data-payload="<?= $payload ?>" data-bs-toggle="modal" data-bs-target="#tplModal" title="Rename / default"><i class="bi bi-tag"></i></button>
                            <form method="post" class="d-inline" onsubmit="return confirm('Toggle status?');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-success' ?>"><i class="bi <?= $active ? 'bi-slash-circle' : 'bi-check2-circle' ?>"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="tplModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="btId" value="0">
            <div class="modal-header"><h5 class="modal-title" id="btTitle"><i class="bi bi-plus-lg me-1"></i>New template</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-9"><label class="form-label">Name <span class="text-danger">*</span></label><input class="form-control" id="btName" name="name" required maxlength="200"></div>
                    <div class="col-md-3 d-flex align-items-end"><div class="form-check">
                        <input class="form-check-input" type="checkbox" id="btDefault" name="is_default" value="1">
                        <label class="form-check-label" for="btDefault">Default template</label>
                    </div></div>
                    <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" id="btDesc" name="description" rows="2" placeholder="Short note about what this template is for."></textarea></div>
                    <div class="col-12"><div class="form-check">
                        <input class="form-check-input" type="checkbox" id="btActive" name="is_active" value="1" checked>
                        <label class="form-check-label" for="btActive">Active</label>
                    </div></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Save</button></div>
        </form>
    </div></div>
</div>

<script>
(function () {
    const m = document.getElementById('tplModal');
    const setV = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
    const setC = (id, v) => { const el = document.getElementById(id); if (el) el.checked = !!v; };
    m?.addEventListener('show.bs.modal', (ev) => {
        const t = ev.relatedTarget; if (!t) return;
        const h = document.getElementById('btTitle');
        if (t.classList.contains('js-new-tpl')) {
            h.innerHTML = '<i class="bi bi-plus-lg me-1"></i>New template';
            setV('btId','0'); setV('btName',''); setV('btDesc',''); setC('btDefault', false); setC('btActive', true);
        } else if (t.classList.contains('js-edit-tpl')) {
            let d = {}; try { d = JSON.parse(t.getAttribute('data-payload') || '{}'); } catch (e) {}
            h.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit template';
            setV('btId', String(d.id || 0)); setV('btName', d.name); setV('btDesc', d.description);
            setC('btDefault', d.is_default === 1); setC('btActive', d.is_active === 1);
        }
    });
})();
</script>

<?php render_footer(); ?>

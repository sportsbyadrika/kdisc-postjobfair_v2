<?php
/**
 * LOI & Jobs · Job location master.
 * Admin-only CRUD. Powers the Location multi-select on the Job modal.
 * Seeded with Anywhere-in-Kerala / Kerala's 14 districts / Anywhere-
 * in-India / International; admins add more here.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/loi_jobs_helpers.php';
require_auth();

$viewer = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
loi_jobs_bootstrap();

if (!is_manage_admin($viewer) && !user_can_admin_module($viewerId, 'loi_jobs')) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only an LOI & Jobs admin can manage this master.</div>';
    render_footer(); exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['loi_loc_flash']) && is_array($_SESSION['loi_loc_flash'])) {
    $flashMessage = (string) ($_SESSION['loi_loc_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['loi_loc_flash']['type'] ?? 'success');
    unset($_SESSION['loi_loc_flash']);
}
$flashAndBack = static function (string $msg, string $type = 'success'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['loi_loc_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /loi_locations.php'); exit;
};

if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $sortOrder = (int) ($_POST['sort_order'] ?? 100);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    if ($name === '') $flashAndBack('Name is required.', 'danger');
    try {
        if ($id > 0) {
            db()->prepare('UPDATE loi_location SET name = ?, sort_order = ?, is_active = ?, updated_at = NOW(), updated_by = ? WHERE id = ?')
                ->execute([$name, $sortOrder, $isActive, $viewerId, $id]);
        } else {
            db()->prepare('INSERT INTO loi_location (name, sort_order, is_active, created_at, updated_at, created_by, updated_by) VALUES (?, ?, ?, NOW(), NOW(), ?, ?)')
                ->execute([$name, $sortOrder, $isActive, $viewerId, $viewerId]);
        }
        $flashAndBack($id > 0 ? 'Location updated.' : 'Location added.');
    } catch (Throwable $e) { $flashAndBack('Save failed: ' . $e->getMessage(), 'danger'); }
}
if (is_post() && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_check_or_die();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) $flashAndBack('Missing id.', 'danger');
    db()->prepare('UPDATE loi_location SET is_active = 1 - is_active, updated_at = NOW(), updated_by = ? WHERE id = ?')->execute([$viewerId, $id]);
    $flashAndBack('Toggled.');
}

$rows = loi_location_list(false);

render_header('LOI & Jobs · Job locations', ['main_container_class' => 'container-xl']);
render_page_header('LOI & Jobs · Job locations', [
    'icon' => 'bi-geo-alt',
    'subtitle' => 'Values shown in the Location multi-select on each Job (Anywhere in Kerala, individual districts, Anywhere in India, International, …).',
    'actions' => '<button type="button" class="btn btn-primary js-new-row" data-bs-toggle="modal" data-bs-target="#rowModal"><i class="bi bi-plus-lg me-1"></i>New location</button>
        <a class="btn btn-light ms-2" href="/loi_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>',
]);
?>

<?php if ($flashMessage !== null): ?><div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header"><i class="bi bi-geo-alt text-primary me-1"></i>Locations</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Sl</th><th>Name</th><th>Sort</th><th>Status</th><th class="text-end">Action</th></tr></thead>
            <tbody>
                <?php if ($rows === []): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-inbox"></i>No rows.</div></td></tr><?php endif; ?>
                <?php $i = 1; foreach ($rows as $r):
                    $active = ((int) $r['is_active']) === 1;
                    $payload = htmlspecialchars(json_encode(['id' => (int) $r['id'], 'name' => (string) $r['name'], 'sort_order' => (int) $r['sort_order'], 'is_active' => (int) $r['is_active']], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                ?>
                <tr class="<?= $active ? '' : 'text-muted' ?>">
                    <td><?= $i++ ?></td>
                    <td class="fw-semibold"><?= esc((string) $r['name']) ?></td>
                    <td class="small"><?= (int) $r['sort_order'] ?></td>
                    <td><?php if ($active): ?><span class="badge text-bg-success">Active</span><?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?></td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-primary js-edit-row" data-payload="<?= $payload ?>" data-bs-toggle="modal" data-bs-target="#rowModal"><i class="bi bi-pencil"></i></button>
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

<div class="modal fade" id="rowModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="rwId" value="0">
            <div class="modal-header"><h5 class="modal-title" id="rwTitle"><i class="bi bi-plus-lg me-1"></i>New location</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-9"><label class="form-label">Name <span class="text-danger">*</span></label><input class="form-control" id="rwName" name="name" required maxlength="160"></div>
                    <div class="col-md-3"><label class="form-label">Sort</label><input type="number" class="form-control" id="rwSort" name="sort_order" value="100"></div>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="rwActive" name="is_active" value="1" checked><label class="form-check-label" for="rwActive">Active</label></div></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Save</button></div>
        </form>
    </div></div>
</div>

<script>
(function () {
    const m = document.getElementById('rowModal');
    const setV = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
    const setC = (id, v) => { const el = document.getElementById(id); if (el) el.checked = !!v; };
    m?.addEventListener('show.bs.modal', (ev) => {
        const t = ev.relatedTarget; if (!t) return;
        const h = document.getElementById('rwTitle');
        if (t.classList.contains('js-new-row')) {
            h.innerHTML = '<i class="bi bi-plus-lg me-1"></i>New location';
            setV('rwId','0'); setV('rwName',''); setV('rwSort','100'); setC('rwActive', true);
        } else if (t.classList.contains('js-edit-row')) {
            let d = {}; try { d = JSON.parse(t.getAttribute('data-payload') || '{}'); } catch (e) {}
            h.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit location';
            setV('rwId', String(d.id || 0)); setV('rwName', d.name); setV('rwSort', String(d.sort_order || 100));
            setC('rwActive', d.is_active === 1);
        }
    });
})();
</script>

<?php render_footer(); ?>

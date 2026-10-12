<?php
/**
 * Platform Requests · Reason master.
 *
 * Admin-only CRUD. Reasons are seeded on bootstrap; admins add + edit
 * from here. Deactivate-never-delete so historic requests pointing
 * at an old reason stay intact.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/loi_requests_helpers.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
loi_requests_bootstrap();

if (!loi_requests_is_platform_admin($viewer)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only a Platform Requests admin can manage the reason master.</div>';
    render_footer(); exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['loi_reason_flash']) && is_array($_SESSION['loi_reason_flash'])) {
    $flashMessage = (string) ($_SESSION['loi_reason_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['loi_reason_flash']['type'] ?? 'success');
    unset($_SESSION['loi_reason_flash']);
}
$flashAndBack = static function (string $msg, string $type = 'success'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['loi_reason_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /loi_request_reasons.php');
    exit;
};

if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();
    $id         = (int) ($_POST['id'] ?? 0);
    $name       = trim((string) ($_POST['name'] ?? ''));
    $desc       = trim((string) ($_POST['description'] ?? ''));
    $sortOrder  = (int) ($_POST['sort_order'] ?? 100);
    $isActive   = isset($_POST['is_active']) ? 1 : 0;
    if ($name === '') $flashAndBack('Name is required.', 'danger');
    try {
        if ($id > 0) {
            db()->prepare('UPDATE loi_request_reason SET name = ?, description = ?, sort_order = ?, is_active = ?, updated_at = NOW(), updated_by = ? WHERE id = ?')
                ->execute([$name, $desc === '' ? null : $desc, $sortOrder, $isActive, $viewerId, $id]);
        } else {
            db()->prepare('INSERT INTO loi_request_reason (name, description, sort_order, is_active, created_at, updated_at, created_by, updated_by)
                VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?)')
                ->execute([$name, $desc === '' ? null : $desc, $sortOrder, $isActive, $viewerId, $viewerId]);
        }
        $flashAndBack($id > 0 ? 'Reason updated.' : 'Reason added.');
    } catch (Throwable $e) {
        $flashAndBack('Save failed: ' . $e->getMessage(), 'danger');
    }
}
if (is_post() && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_check_or_die();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) $flashAndBack('Missing id.', 'danger');
    db()->prepare('UPDATE loi_request_reason SET is_active = 1 - is_active, updated_at = NOW(), updated_by = ? WHERE id = ?')
        ->execute([$viewerId, $id]);
    $flashAndBack('Toggled.');
}

$rows = loi_request_reason_list(false);

render_header('Platform Requests · Reason master', ['main_container_class' => 'container-xl']);
render_page_header('Reason master', [
    'icon'     => 'bi-tags',
    'subtitle' => 'Reasons shown in the "Raise request" dropdown. Deactivate-never-delete.',
    'actions'  => '<button type="button" class="btn btn-primary js-new-reason" data-bs-toggle="modal" data-bs-target="#reasonModal"><i class="bi bi-plus-lg me-1"></i>New reason</button>
        <a class="btn btn-light ms-2" href="/platform_requests.php"><i class="bi bi-arrow-left me-1"></i>Back to Inbox</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><i class="bi bi-tags text-primary me-1"></i>Reasons</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Sl</th><th>Name</th><th>Description</th><th>Sort</th><th>Status</th><th class="text-end">Action</th></tr></thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i>No reasons yet.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($rows as $r):
                    $active = ((int) $r['is_active']) === 1;
                    $payload = htmlspecialchars(json_encode([
                        'id'          => (int) $r['id'],
                        'name'        => (string) $r['name'],
                        'description' => (string) ($r['description'] ?? ''),
                        'sort_order'  => (int) $r['sort_order'],
                        'is_active'   => (int) $r['is_active'],
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                ?>
                    <tr class="<?= $active ? '' : 'text-muted' ?>">
                        <td><?= $i++ ?></td>
                        <td class="fw-semibold"><?= esc((string) $r['name']) ?></td>
                        <td class="small text-muted"><?= esc((string) ($r['description'] ?? '')) ?></td>
                        <td class="small"><?= (int) $r['sort_order'] ?></td>
                        <td><?php if ($active): ?><span class="badge text-bg-success">Active</span><?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <button type="button" class="btn btn-sm btn-outline-primary js-edit-reason"
                                        data-payload="<?= $payload ?>"
                                        data-bs-toggle="modal" data-bs-target="#reasonModal">
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
</div>

<div class="modal fade" id="reasonModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="rModalId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="rModalTitle"><i class="bi bi-plus-lg me-1"></i>New reason</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-9"><label class="form-label">Name <span class="text-danger">*</span></label><input class="form-control" id="rModalName" name="name" required maxlength="200"></div>
                        <div class="col-md-3"><label class="form-label">Sort order</label><input type="number" class="form-control" id="rModalSort" name="sort_order" value="100"></div>
                        <div class="col-12"><label class="form-label">Description <span class="small text-muted">(shown as a hint under the dropdown)</span></label><textarea class="form-control" id="rModalDesc" name="description" rows="2"></textarea></div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="rModalActive" name="is_active" value="1" checked>
                                <label class="form-check-label" for="rModalActive">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const modalEl = document.getElementById('reasonModal');
    const setV = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
    const setC = (id, v) => { const el = document.getElementById(id); if (el) el.checked = !!v; };
    modalEl?.addEventListener('show.bs.modal', (ev) => {
        const t = ev.relatedTarget; if (!t) return;
        const title = document.getElementById('rModalTitle');
        if (t.classList.contains('js-new-reason')) {
            title.innerHTML = '<i class="bi bi-plus-lg me-1"></i>New reason';
            setV('rModalId','0'); setV('rModalName',''); setV('rModalDesc',''); setV('rModalSort','100');
            setC('rModalActive', true);
        } else if (t.classList.contains('js-edit-reason')) {
            let d = {}; try { d = JSON.parse(t.getAttribute('data-payload') || '{}'); } catch (e) {}
            title.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit reason';
            setV('rModalId', String(d.id || 0));
            setV('rModalName', d.name); setV('rModalDesc', d.description);
            setV('rModalSort', String(d.sort_order || 100));
            setC('rModalActive', d.is_active === 1);
        }
    });
})();
</script>

<?php render_footer(); ?>

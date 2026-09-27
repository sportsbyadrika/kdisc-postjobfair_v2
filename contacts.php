<?php
/**
 * Contacts master — global list of external persons used by the
 * Meetings module (participant list + decision-point responsibility).
 *
 * Rules (from the requirements chat):
 *   • Any logged-in user can add a contact.
 *   • The creator can edit their own additions.
 *   • Meetings-module admin (or admin-group role) can edit or
 *     deactivate any contact.
 *
 * CSRF on every write. Deactivate-never-delete follows the rest of
 * the app.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/meetings_helpers.php';
require_auth();

$viewer = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
// Every logged-in user can see contacts (they need them to pick
// participants / decision-responsibility). Fine-grained edit rights
// checked at row level.
meetings_bootstrap();

$canAdminAll = is_manage_admin($viewer) || user_can_admin_module($viewerId, 'meetings');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['contacts_flash']) && is_array($_SESSION['contacts_flash'])) {
    $flashMessage = (string) ($_SESSION['contacts_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['contacts_flash']['type'] ?? 'success');
    unset($_SESSION['contacts_flash']);
}
$flashAndBack = static function (string $msg, string $type = 'success'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['contacts_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /contacts.php');
    exit;
};

if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();
    $editId       = (int) ($_POST['id'] ?? 0);
    $name         = trim((string) ($_POST['name'] ?? ''));
    $institution  = trim((string) ($_POST['institution'] ?? ''));
    $designation  = trim((string) ($_POST['designation'] ?? ''));
    $email        = trim((string) ($_POST['email'] ?? ''));
    $mobile       = trim((string) ($_POST['mobile'] ?? ''));
    $address      = trim((string) ($_POST['address'] ?? ''));
    $notes        = trim((string) ($_POST['notes'] ?? ''));
    $isActive     = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') $flashAndBack('Name is required.', 'danger');

    try {
        if ($editId > 0) {
            // Only creator or admin can edit an existing row.
            $ow = db()->prepare('SELECT created_by FROM contact WHERE id = ?');
            $ow->execute([$editId]);
            $ownerRow = $ow->fetch();
            if ($ownerRow === false) $flashAndBack('Contact not found.', 'danger');
            $owner = (int) ($ownerRow['created_by'] ?? 0);
            if (!$canAdminAll && $owner !== $viewerId) {
                $flashAndBack('Only the contact\'s creator or a Meetings admin can edit this row.', 'danger');
            }
            db()->prepare('UPDATE contact SET
                    name = ?, institution = ?, designation = ?, email = ?, mobile = ?, address = ?, notes = ?,
                    is_active = ?, updated_at = NOW(), updated_by = ?
                WHERE id = ?')
                ->execute([
                    $name,
                    $institution === '' ? null : $institution,
                    $designation === '' ? null : $designation,
                    $email === ''       ? null : $email,
                    $mobile === ''      ? null : $mobile,
                    $address === ''     ? null : $address,
                    $notes === ''       ? null : $notes,
                    $isActive, $viewerId, $editId,
                ]);
            $flashAndBack('Contact updated.');
        } else {
            db()->prepare('INSERT INTO contact
                (name, institution, designation, email, mobile, address, notes, is_active,
                 created_at, updated_at, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)')
                ->execute([
                    $name,
                    $institution === '' ? null : $institution,
                    $designation === '' ? null : $designation,
                    $email === ''       ? null : $email,
                    $mobile === ''      ? null : $mobile,
                    $address === ''     ? null : $address,
                    $notes === ''       ? null : $notes,
                    $isActive, $viewerId, $viewerId,
                ]);
            $flashAndBack('Contact added.');
        }
    } catch (Throwable $e) {
        $flashAndBack('Save failed: ' . $e->getMessage(), 'danger');
    }
}
if (is_post() && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_check_or_die();
    $tid = (int) ($_POST['id'] ?? 0);
    if ($tid <= 0) $flashAndBack('Missing id.', 'danger');
    $ow = db()->prepare('SELECT created_by FROM contact WHERE id = ?');
    $ow->execute([$tid]);
    $row = $ow->fetch();
    if ($row === false) $flashAndBack('Contact not found.', 'danger');
    if (!$canAdminAll && (int) ($row['created_by'] ?? 0) !== $viewerId) {
        $flashAndBack('Only the contact\'s creator or a Meetings admin can change this status.', 'danger');
    }
    db()->prepare('UPDATE contact SET is_active = 1 - is_active, updated_at = NOW(), updated_by = ? WHERE id = ?')
        ->execute([$viewerId, $tid]);
    $flashAndBack('Toggled.');
}

$filterName = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT c.*, u.name AS created_by_name FROM contact c
    LEFT JOIN users u ON u.id = c.created_by
    WHERE 1=1';
$params = [];
if ($filterName !== '') {
    $sql .= ' AND (c.name LIKE ? OR c.institution LIKE ? OR c.email LIKE ? OR c.mobile LIKE ?)';
    $like = '%' . $filterName . '%';
    array_push($params, $like, $like, $like, $like);
}
$sql .= ' ORDER BY c.is_active DESC, c.name ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

render_header('Contacts master', ['main_container_class' => 'container-xl']);
render_page_header('Contacts master', [
    'icon'     => 'bi-person-vcard',
    'subtitle' => 'External contacts used across the Meetings module. Any user can add a contact; only the creator or an admin can edit / deactivate.',
    'actions'  => '<button type="button" class="btn btn-primary js-new-contact" data-bs-toggle="modal" data-bs-target="#contactModal"><i class="bi bi-plus-lg me-1"></i>New contact</button>
        <a class="btn btn-light ms-2" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<form method="get" class="filter-bar">
    <div class="row g-3 align-items-end">
        <div class="col-12 col-md-6">
            <label class="form-label">Search</label>
            <input class="form-control" name="q" value="<?= esc($filterName) ?>" placeholder="Name, institution, email or mobile">
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
            <a class="btn btn-light" href="/contacts.php">Reset</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-vcard text-primary me-1"></i>Contacts</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> row<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Name</th>
                    <th>Institution</th>
                    <th>Designation</th>
                    <th>Contact</th>
                    <th>Added by</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No contacts found.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($rows as $r):
                    $active = ((int) $r['is_active']) === 1;
                    $ownerId = (int) ($r['created_by'] ?? 0);
                    $canEditRow = $canAdminAll || $ownerId === $viewerId;
                    $payload = htmlspecialchars(json_encode([
                        'id'          => (int) $r['id'],
                        'name'        => (string) $r['name'],
                        'institution' => (string) ($r['institution'] ?? ''),
                        'designation' => (string) ($r['designation'] ?? ''),
                        'email'       => (string) ($r['email'] ?? ''),
                        'mobile'      => (string) ($r['mobile'] ?? ''),
                        'address'     => (string) ($r['address'] ?? ''),
                        'notes'       => (string) ($r['notes'] ?? ''),
                        'is_active'   => (int) $r['is_active'],
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                ?>
                    <tr class="<?= $active ? '' : 'text-muted' ?>">
                        <td><?= $i++ ?></td>
                        <td class="fw-semibold"><?= esc((string) $r['name']) ?></td>
                        <td class="small"><?= esc((string) ($r['institution'] ?? '')) ?></td>
                        <td class="small"><?= esc((string) ($r['designation'] ?? '')) ?></td>
                        <td class="small text-muted">
                            <?php if (!empty($r['mobile'])): ?><div><i class="bi bi-telephone me-1"></i><?= esc((string) $r['mobile']) ?></div><?php endif; ?>
                            <?php if (!empty($r['email'])): ?><div><i class="bi bi-envelope me-1"></i><?= esc((string) $r['email']) ?></div><?php endif; ?>
                        </td>
                        <td class="small"><?= esc((string) ($r['created_by_name'] ?? '')) ?></td>
                        <td>
                            <?php if ($active): ?><span class="badge text-bg-success">Active</span>
                            <?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($canEditRow): ?>
                                <div class="d-inline-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-primary js-edit-contact"
                                            data-payload="<?= $payload ?>"
                                            data-bs-toggle="modal" data-bs-target="#contactModal">
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
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="contactModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="cModalId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="cModalTitle"><i class="bi bi-plus-lg me-1"></i>New contact</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="cModalName">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="cModalName" name="name" required maxlength="200">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cModalInst">Institution</label>
                            <input type="text" class="form-control" id="cModalInst" name="institution" maxlength="200">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cModalDes">Designation</label>
                            <input type="text" class="form-control" id="cModalDes" name="designation" maxlength="200">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="cModalMob">Mobile</label>
                            <input type="text" class="form-control" id="cModalMob" name="mobile" maxlength="40">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="cModalEm">Email</label>
                            <input type="email" class="form-control" id="cModalEm" name="email" maxlength="200">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label" for="cModalAddr">Address</label>
                            <textarea class="form-control" id="cModalAddr" name="address" rows="2"></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label" for="cModalNotes">Notes</label>
                            <textarea class="form-control" id="cModalNotes" name="notes" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="cModalActive" name="is_active" value="1" checked>
                                <label class="form-check-label" for="cModalActive">Active</label>
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
    const modalEl = document.getElementById('contactModal');
    modalEl?.addEventListener('show.bs.modal', (ev) => {
        const t = ev.relatedTarget; if (!t) return;
        const title = document.getElementById('cModalTitle');
        const setV = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
        const setC = (id, v) => { const el = document.getElementById(id); if (el) el.checked = v; };
        if (t.classList.contains('js-new-contact')) {
            title.innerHTML = '<i class="bi bi-plus-lg me-1"></i>New contact';
            setV('cModalId','0'); setV('cModalName',''); setV('cModalInst',''); setV('cModalDes','');
            setV('cModalMob',''); setV('cModalEm',''); setV('cModalAddr',''); setV('cModalNotes','');
            setC('cModalActive', true);
        } else if (t.classList.contains('js-edit-contact')) {
            let d = {}; try { d = JSON.parse(t.getAttribute('data-payload') || '{}'); } catch (e) {}
            title.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit contact';
            setV('cModalId', d.id);
            setV('cModalName', d.name || ''); setV('cModalInst', d.institution || ''); setV('cModalDes', d.designation || '');
            setV('cModalMob', d.mobile || ''); setV('cModalEm', d.email || '');
            setV('cModalAddr', d.address || ''); setV('cModalNotes', d.notes || '');
            setC('cModalActive', d.is_active === 1);
        }
    });
})();
</script>

<?php render_footer(); ?>

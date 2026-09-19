<?php
/**
 * Role Groups — named bundles of (module, role) pairs.
 *
 * A user who's a member of a group inherits every grant in the
 * group; the highest role wins between direct grants and group-
 * derived grants.
 *
 * Access: Administrator / DSM Admin only. Every write POST is CSRF-
 * checked via csrf_check_or_die().
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_auth();

$viewer = current_user() ?? [];
if (!is_manage_admin($viewer)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only Administrator / DSM Admin can manage role groups.</div>';
    render_footer();
    exit;
}
$viewerId = (int) $viewer['id'];

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['role_groups_flash']) && is_array($_SESSION['role_groups_flash'])) {
    $flashMessage = (string) ($_SESSION['role_groups_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['role_groups_flash']['type'] ?? 'success');
    unset($_SESSION['role_groups_flash']);
}
$flashAndBack = static function (string $msg, string $type = 'success'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['role_groups_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /role_groups.php');
    exit;
};

$modules       = rbac_all_modules();
$rolesByModule = rbac_all_module_roles();
$moduleById    = [];
foreach ($modules as $m) $moduleById[(int) $m['id']] = $m;

if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();
    $editId = (int) ($_POST['id'] ?? 0);
    $name   = trim((string) ($_POST['name'] ?? ''));
    $desc   = trim((string) ($_POST['description'] ?? ''));
    $active = isset($_POST['is_active']) ? 1 : 0;
    $grants = (array) ($_POST['grants'] ?? []); // module_id => module_role_id (0 = none)

    if ($name === '') $flashAndBack('Name is required.', 'danger');

    $db = db();
    $db->query('START TRANSACTION');
    try {
        if ($editId > 0) {
            $db->prepare('UPDATE role_group SET name = ?, description = ?, is_active = ?, updated_at = NOW(), updated_by = ? WHERE id = ?')
               ->execute([$name, $desc === '' ? null : $desc, $active, $viewerId, $editId]);
            $groupId = $editId;
        } else {
            $db->prepare('INSERT INTO role_group (name, description, is_active, created_at, updated_at, created_by, updated_by) VALUES (?, ?, ?, NOW(), NOW(), ?, ?)')
               ->execute([$name, $desc === '' ? null : $desc, $active, $viewerId, $viewerId]);
            $groupId = $db->lastInsertId();
        }

        $db->prepare('DELETE FROM role_group_grant WHERE role_group_id = ?')->execute([$groupId]);
        $insG = $db->prepare('INSERT INTO role_group_grant (role_group_id, module_id, module_role_id) VALUES (?, ?, ?)');
        foreach ($grants as $modId => $roleId) {
            $modId = (int) $modId; $roleId = (int) $roleId;
            if ($modId <= 0 || $roleId <= 0) continue;
            $mod = $moduleById[$modId] ?? null;
            if ($mod === null) continue;
            $ok = false;
            foreach ($rolesByModule[$modId] ?? [] as $r) if ((int) $r['id'] === $roleId) { $ok = true; break; }
            if (!$ok) continue;
            $insG->execute([$groupId, $modId, $roleId]);
        }
        $db->query('COMMIT');
        $flashAndBack($editId > 0 ? 'Role group updated.' : 'Role group created.');
    } catch (Throwable $e) {
        try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
        $flashAndBack('Save failed: ' . $e->getMessage(), 'danger');
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_check_or_die();
    $tid = (int) ($_POST['id'] ?? 0);
    if ($tid > 0) {
        try {
            db()->prepare('UPDATE role_group SET is_active = 1 - is_active, updated_at = NOW(), updated_by = ? WHERE id = ?')
                ->execute([$viewerId, $tid]);
            $flashAndBack('Toggled.');
        } catch (Throwable $e) { $flashAndBack('Toggle failed: ' . $e->getMessage(), 'danger'); }
    }
}

// Load groups + their current grants + member counts.
$groups = db()->query('SELECT g.*, u.name AS created_by_name,
        (SELECT COUNT(*) FROM user_role_group urg WHERE urg.role_group_id = g.id) AS member_count
    FROM role_group g
    LEFT JOIN users u ON u.id = g.created_by
    ORDER BY g.is_active DESC, g.name ASC')->fetchAll();

$grantsByGroup = [];
try {
    foreach (db()->query('SELECT rgg.role_group_id, rgg.module_id, rgg.module_role_id,
            m.code AS module_code, m.name AS module_name, mr.code AS role_code, mr.name AS role_name
        FROM role_group_grant rgg
        INNER JOIN module m       ON m.id  = rgg.module_id
        INNER JOIN module_role mr ON mr.id = rgg.module_role_id
        ORDER BY m.sort_order ASC')->fetchAll() as $g) {
        $grantsByGroup[(int) $g['role_group_id']][] = $g;
    }
} catch (Throwable $e) { /* tables might be empty */ }

render_header('Administration · Role Groups', ['main_container_class' => 'container-xl']);
render_page_header('Administration · Role Groups', [
    'icon'     => 'bi-collection',
    'subtitle' => 'A named bundle of (module, role) grants. Attach one or more groups to a user and their effective role on each module is the highest of every grant they receive.',
    'actions'  => '<button type="button" class="btn btn-primary js-new-group" data-bs-toggle="modal" data-bs-target="#groupModal"><i class="bi bi-plus-lg me-1"></i>New role group</button>
        <a class="btn btn-light ms-2" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-collection text-primary me-1"></i>Role groups</span>
        <span class="status-chip status-info"><?= number_format(count($groups)) ?> group<?= count($groups) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Name</th>
                    <th>Grants</th>
                    <th class="text-end">Members</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($groups === []): ?>
                    <tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i>No role groups yet. Click "New role group" to create one.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($groups as $g):
                    $gid = (int) $g['id'];
                    $active = ((int) $g['is_active']) === 1;
                    $payload = htmlspecialchars(json_encode([
                        'id'          => $gid,
                        'name'        => (string) $g['name'],
                        'description' => (string) ($g['description'] ?? ''),
                        'is_active'   => (int) $g['is_active'],
                        'grants'      => array_map(static fn($x) => ['module_id' => (int) $x['module_id'], 'module_role_id' => (int) $x['module_role_id']], $grantsByGroup[$gid] ?? []),
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                ?>
                    <tr class="<?= $active ? '' : 'text-muted' ?>">
                        <td><?= $i++ ?></td>
                        <td>
                            <div class="fw-semibold"><?= esc((string) $g['name']) ?></div>
                            <?php if (!empty($g['description'])): ?><div class="small text-muted"><?= esc((string) $g['description']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <?php $gg = $grantsByGroup[$gid] ?? []; if ($gg === []): ?>
                                <span class="text-muted small">— no grants —</span>
                            <?php else: foreach ($gg as $x):
                                $tone = ($x['role_code'] === 'admin') ? 'primary' : (($x['role_code'] === 'reviewer') ? 'info' : 'secondary');
                            ?>
                                <span class="badge text-bg-<?= esc($tone) ?> me-1 mb-1"><?= esc((string) $x['module_name']) ?> · <?= esc((string) $x['role_name']) ?></span>
                            <?php endforeach; endif; ?>
                        </td>
                        <td class="text-end fw-bold"><?= number_format((int) $g['member_count']) ?></td>
                        <td>
                            <?php if ($active): ?><span class="badge text-bg-success">Active</span>
                            <?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <button type="button" class="btn btn-sm btn-outline-primary js-edit-group"
                                        data-payload="<?= $payload ?>"
                                        data-bs-toggle="modal" data-bs-target="#groupModal">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Toggle status?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= $gid ?>">
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
        Assign a group to a user under Administration → Users. If a user is in two groups that grant different roles on the same module, the higher role wins (Admin &gt; Reviewer &gt; User).
    </div>
</div>

<div class="modal fade" id="groupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="groupForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="groupModalId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="groupModalTitle"><i class="bi bi-plus-lg me-1"></i>New role group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label" for="groupModalName">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="groupModalName" name="name" required maxlength="120" placeholder="e.g. Project Reviewers">
                        </div>
                        <div class="col-md-5 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="groupModalActive" name="is_active" value="1" checked>
                                <label class="form-check-label" for="groupModalActive">Active</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="groupModalDesc">Description</label>
                            <input type="text" class="form-control" id="groupModalDesc" name="description" maxlength="500">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Module grants</label>
                            <div class="table-responsive border rounded">
                                <table class="table table-sm mb-0 align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Module</th>
                                            <th class="text-center">Admin</th>
                                            <th class="text-center">Reviewer</th>
                                            <th class="text-center">User</th>
                                            <th class="text-center">— None —</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($modules as $m):
                                            $mid = (int) $m['id'];
                                            $rolesRow = $rolesByModule[$mid] ?? [];
                                            $roleId = static function (string $code) use ($rolesRow): int {
                                                foreach ($rolesRow as $r) if ((string) $r['code'] === $code) return (int) $r['id'];
                                                return 0;
                                            };
                                        ?>
                                            <tr>
                                                <td class="fw-semibold"><?= esc((string) $m['name']) ?></td>
                                                <?php foreach (['admin', 'reviewer', 'user'] as $rc):
                                                    $rid = $roleId($rc);
                                                ?>
                                                    <td class="text-center">
                                                        <input type="radio" class="form-check-input" name="grants[<?= $mid ?>]" value="<?= $rid ?>" data-role="<?= esc($rc) ?>" data-module="<?= $mid ?>">
                                                    </td>
                                                <?php endforeach; ?>
                                                <td class="text-center">
                                                    <input type="radio" class="form-check-input" name="grants[<?= $mid ?>]" value="0" data-role="" data-module="<?= $mid ?>" checked>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
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
    const modalEl = document.getElementById('groupModal');
    modalEl?.addEventListener('show.bs.modal', (ev) => {
        const t = ev.relatedTarget; if (!t) return;
        const title = document.getElementById('groupModalTitle');
        const setV = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
        const setC = (id, v) => { const el = document.getElementById(id); if (el) el.checked = v; };
        // Reset every "None" radio.
        modalEl.querySelectorAll('input[type=radio][value="0"]').forEach(r => r.checked = true);
        if (t.classList.contains('js-new-group')) {
            title.innerHTML = '<i class="bi bi-plus-lg me-1"></i>New role group';
            setV('groupModalId', '0'); setV('groupModalName', ''); setV('groupModalDesc', '');
            setC('groupModalActive', true);
        } else if (t.classList.contains('js-edit-group')) {
            let d = {}; try { d = JSON.parse(t.getAttribute('data-payload') || '{}'); } catch (e) {}
            title.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit role group';
            setV('groupModalId', d.id);
            setV('groupModalName', d.name || ''); setV('groupModalDesc', d.description || '');
            setC('groupModalActive', d.is_active === 1);
            (d.grants || []).forEach(g => {
                const radio = modalEl.querySelector(`input[type=radio][name="grants[${g.module_id}]"][value="${g.module_role_id}"]`);
                if (radio) radio.checked = true;
            });
        }
    });
})();
</script>

<?php render_footer(); ?>

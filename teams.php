<?php
/**
 * Teams master — admin-only CRUD.
 *
 * A team is {name, head_user_id, description} + an N:M member list of
 * internal users. Decision points in the Meetings module can be
 * assigned to one or more teams; the Own-Tasks sync then creates a
 * single task on the team head's seat (or fans out to every member
 * if the decision has the fan_out_teams flag set).
 *
 * Access: administrators + anyone with admin on the "administration"
 * module. Deactivate-never-delete.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/teams_helpers.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
teams_bootstrap();

if (!is_manage_admin($viewer) && !user_can_admin_module($viewerId, 'administration')) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">You do not have access to the Teams master.</div>';
    render_footer(); exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['teams_flash']) && is_array($_SESSION['teams_flash'])) {
    $flashMessage = (string) ($_SESSION['teams_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['teams_flash']['type'] ?? 'success');
    unset($_SESSION['teams_flash']);
}
$flashAndBack = static function (string $msg, string $type = 'success'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['teams_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /teams.php');
    exit;
};

/* ---------- POST: save ---------- */
if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();
    $editId      = (int) ($_POST['id'] ?? 0);
    $name        = trim((string) ($_POST['name'] ?? ''));
    $headUserId  = (int) ($_POST['head_user_id'] ?? 0);
    $description = trim((string) ($_POST['description'] ?? ''));
    $isActive    = isset($_POST['is_active']) ? 1 : 0;
    $memberIds   = array_values(array_unique(array_map('intval', (array) ($_POST['member_ids'] ?? []))));
    $memberIds   = array_values(array_filter($memberIds, static fn($i) => $i > 0));

    if ($name === '') $flashAndBack('Team name is required.', 'danger');

    $db = db();
    $db->query('START TRANSACTION');
    try {
        if ($editId > 0) {
            $db->prepare('UPDATE team SET name = ?, head_user_id = ?, description = ?, is_active = ?, updated_at = NOW(), updated_by = ? WHERE id = ?')
                ->execute([$name, $headUserId > 0 ? $headUserId : null, $description === '' ? null : $description, $isActive, $viewerId, $editId]);
            $teamId = $editId;
        } else {
            $db->prepare('INSERT INTO team (name, head_user_id, description, is_active, created_at, updated_at, created_by, updated_by)
                VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?)')
                ->execute([$name, $headUserId > 0 ? $headUserId : null, $description === '' ? null : $description, $isActive, $viewerId, $viewerId]);
            $teamId = $db->lastInsertId();
        }
        // Rebuild member list wholesale — simpler than diffing.
        $db->prepare('DELETE FROM team_member WHERE team_id = ?')->execute([$teamId]);
        $ins = $db->prepare('INSERT INTO team_member (team_id, user_id, created_at) VALUES (?, ?, NOW())');
        foreach ($memberIds as $uid) $ins->execute([$teamId, $uid]);
        // Head is a member implicitly — add if not already in the list
        // so the head always appears on the team card.
        if ($headUserId > 0 && !in_array($headUserId, $memberIds, true)) {
            try { $ins->execute([$teamId, $headUserId]); } catch (Throwable $e) { /* unique key already */ }
        }
        $db->query('COMMIT');
        $flashAndBack($editId > 0 ? 'Team updated.' : 'Team added.');
    } catch (Throwable $e) {
        try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
        $flashAndBack('Save failed: ' . $e->getMessage(), 'danger');
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_check_or_die();
    $tid = (int) ($_POST['id'] ?? 0);
    if ($tid <= 0) $flashAndBack('Missing id.', 'danger');
    db()->prepare('UPDATE team SET is_active = 1 - is_active, updated_at = NOW(), updated_by = ? WHERE id = ?')
        ->execute([$viewerId, $tid]);
    $flashAndBack('Toggled.');
}

/* ---------- List ---------- */
$filterName = trim((string) ($_GET['q'] ?? ''));
$teams = teams_list_active_or_all($filterName);

/* Users list for the modal pickers — all active internal users. */
$users = [];
try {
    $users = db()->query('SELECT id, name FROM users WHERE active_status = 1 ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) { /* ignore */ }

render_header('Teams master', ['main_container_class' => 'container-xl']);
render_page_header('Teams master', [
    'icon'     => 'bi-people',
    'subtitle' => 'Groups of internal users with a team head. Meeting decision points can be assigned to a team — the task lands on the team head\'s seat by default, who then splits work via sub-activities. A per-decision fan-out option creates a task for every team member instead.',
    'actions'  => '<button type="button" class="btn btn-primary js-new-team" data-bs-toggle="modal" data-bs-target="#teamModal"><i class="bi bi-plus-lg me-1"></i>New team</button>
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
            <input class="form-control" name="q" value="<?= esc($filterName) ?>" placeholder="Team name or description">
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
            <a class="btn btn-light" href="/teams.php">Reset</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people text-primary me-1"></i>Teams</span>
        <span class="status-chip status-info"><?= number_format(count($teams)) ?> row<?= count($teams) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Name</th>
                    <th>Head</th>
                    <th>Members</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($teams === []): ?>
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No teams defined yet.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($teams as $t):
                    $active = ((int) $t['is_active']) === 1;
                    $memberIds = array_map(static fn($m) => (int) $m['user_id'], $t['members'] ?? []);
                    $payload = htmlspecialchars(json_encode([
                        'id'           => (int) $t['id'],
                        'name'         => (string) $t['name'],
                        'head_user_id' => (int) ($t['head_user_id'] ?? 0),
                        'description'  => (string) ($t['description'] ?? ''),
                        'is_active'    => (int) $t['is_active'],
                        'member_ids'   => $memberIds,
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                ?>
                    <tr class="<?= $active ? '' : 'text-muted' ?>">
                        <td><?= $i++ ?></td>
                        <td class="fw-semibold"><?= esc((string) $t['name']) ?></td>
                        <td class="small"><?= esc((string) ($t['head_name'] ?? '')) ?></td>
                        <td class="small">
                            <?php foreach ($t['members'] as $m): ?>
                                <span class="badge bg-light text-dark border me-1 mb-1"><?= esc((string) $m['name']) ?></span>
                            <?php endforeach; ?>
                            <?php if ($t['members'] === []): ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= esc(mb_substr((string) ($t['description'] ?? ''), 0, 160)) ?></td>
                        <td>
                            <?php if ($active): ?><span class="badge text-bg-success">Active</span>
                            <?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <button type="button" class="btn btn-sm btn-outline-primary js-edit-team"
                                        data-payload="<?= $payload ?>"
                                        data-bs-toggle="modal" data-bs-target="#teamModal">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Toggle status?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
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

<div class="modal fade" id="teamModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="tModalId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="tModalTitle"><i class="bi bi-plus-lg me-1"></i>New team</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="tModalName">Team name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="tModalName" name="name" required maxlength="200">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="tModalHead">Team head (Internal user)</label>
                            <select class="form-select" id="tModalHead" name="head_user_id">
                                <option value="0">— select head —</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int) $u['id'] ?>"><?= esc((string) $u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="small text-muted mt-1">Decisions assigned to this team land on the head's seat by default.</div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label" for="tModalDesc">Description</label>
                            <textarea class="form-control" id="tModalDesc" name="description" rows="2"></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Members</label>
                            <input type="text" class="form-control mb-2" id="tModalMemberSearch" placeholder="Search members by name…">
                            <div class="border rounded p-2" style="max-height: 260px; overflow-y: auto;" id="tModalMemberList">
                                <?php foreach ($users as $u): ?>
                                    <div class="form-check tm-row" data-name="<?= esc(strtolower((string) $u['name'])) ?>">
                                        <input class="form-check-input tm-cb" type="checkbox" name="member_ids[]" value="<?= (int) $u['id'] ?>" id="tmU<?= (int) $u['id'] ?>">
                                        <label class="form-check-label small" for="tmU<?= (int) $u['id'] ?>"><?= esc((string) $u['name']) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="small text-muted mt-1">The team head is added automatically — tick other members here.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="tModalActive" name="is_active" value="1" checked>
                                <label class="form-check-label" for="tModalActive">Active</label>
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
    const modalEl = document.getElementById('teamModal');
    const search  = document.getElementById('tModalMemberSearch');
    const list    = document.getElementById('tModalMemberList');
    search?.addEventListener('input', () => {
        const q = search.value.toLowerCase().trim();
        list.querySelectorAll('.tm-row').forEach(row => {
            row.style.display = (q === '' || row.getAttribute('data-name').includes(q)) ? '' : 'none';
        });
    });
    const setV = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
    const setC = (id, v) => { const el = document.getElementById(id); if (el) el.checked = v; };
    modalEl?.addEventListener('show.bs.modal', (ev) => {
        const t = ev.relatedTarget; if (!t) return;
        const title = document.getElementById('tModalTitle');
        // Reset
        list.querySelectorAll('.tm-cb').forEach(cb => cb.checked = false);
        if (t.classList.contains('js-new-team')) {
            title.innerHTML = '<i class="bi bi-plus-lg me-1"></i>New team';
            setV('tModalId','0'); setV('tModalName',''); setV('tModalHead','0'); setV('tModalDesc','');
            setC('tModalActive', true);
        } else if (t.classList.contains('js-edit-team')) {
            let d = {}; try { d = JSON.parse(t.getAttribute('data-payload') || '{}'); } catch (e) {}
            title.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit team';
            setV('tModalId', d.id);
            setV('tModalName', d.name || '');
            setV('tModalHead', String(d.head_user_id || 0));
            setV('tModalDesc', d.description || '');
            setC('tModalActive', d.is_active === 1);
            (d.member_ids || []).forEach(uid => {
                const cb = document.getElementById('tmU' + uid);
                if (cb) cb.checked = true;
            });
        }
    });
})();
</script>

<?php render_footer(); ?>

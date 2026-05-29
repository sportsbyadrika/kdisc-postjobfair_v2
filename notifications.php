<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$user = current_user();
if (is_district_user($user) && false) {
    // District users can also view notifications targeted to them; gate left
    // open so the visibility filter below handles scoping.
}

// Self-migrations for the new notification fields on the activities table.
$activitiesCols = array_column(db()->query("SHOW COLUMNS FROM activities")->fetchAll(), 'Field');
if (!in_array('to_whom', $activitiesCols, true)) {
    db()->query("ALTER TABLE activities ADD COLUMN to_whom ENUM('State CRM','State DSM','District User') NULL AFTER owner_user_id");
}
if (!in_array('read_by', $activitiesCols, true)) {
    db()->query("ALTER TABLE activities ADD COLUMN read_by ENUM('any','specific') NOT NULL DEFAULT 'any' AFTER to_whom");
}
if (!in_array('target_user_id', $activitiesCols, true)) {
    db()->query("ALTER TABLE activities ADD COLUMN target_user_id INT NULL AFTER read_by");
}

$rolesByToWhom = [
    'State CRM' => 'crm_member',
    'State DSM' => 'state_dsm',
    'District User' => 'district_user',
];

function notifications_normalise_district_list(?string $value): array
{
    $value = trim((string) $value);
    if ($value === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn($v) => $v !== ''));
}

function notifications_user_in_target_audience(array $user, ?string $toWhom, ?int $targetUserId, string $readBy, array $rolesByToWhom): bool
{
    if ($toWhom === null || $toWhom === '') return true;
    $targetRole = $rolesByToWhom[$toWhom] ?? null;
    if ($targetRole === null) return true;
    if (($user['role'] ?? '') !== $targetRole) return false;
    if ($readBy === 'specific') {
        return $targetUserId === (int) ($user['id'] ?? 0);
    }
    return true; // "any" - any user in that role.
}

$flash = null;

if (is_post()) {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $module = $_POST['module_name'] ?? 'crm';
    $title = trim($_POST['title'] ?? '');
    $details = trim($_POST['details'] ?? '');
    $status = $_POST['status'] ?? 'open';
    $active = isset($_POST['active_status']) ? 1 : 0;
    $toWhom = trim((string) ($_POST['to_whom'] ?? ''));
    if ($toWhom !== '' && !array_key_exists($toWhom, $rolesByToWhom)) {
        $toWhom = '';
    }
    $readBy = in_array($_POST['read_by'] ?? 'any', ['any', 'specific'], true) ? $_POST['read_by'] : 'any';
    $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
    if ($readBy !== 'specific' || $targetUserId <= 0) {
        $targetUserId = null;
    }

    if ($action === 'add') {
        $stmt = db()->prepare('INSERT INTO activities (module_name, title, details, status, owner_user_id, to_whom, read_by, target_user_id, active_status, created_at, updated_at, modified_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)');
        $stmt->execute([$module, $title, $details, $status, $user['id'], $toWhom !== '' ? $toWhom : null, $readBy, $targetUserId, $active, $user['id']]);
        $flash = 'Notification added.';
    }
    if ($action === 'edit') {
        $stmt = db()->prepare('UPDATE activities SET module_name=?, title=?, details=?, status=?, to_whom=?, read_by=?, target_user_id=?, active_status=?, updated_at=NOW(), modified_by=? WHERE id=?');
        $stmt->execute([$module, $title, $details, $status, $toWhom !== '' ? $toWhom : null, $readBy, $targetUserId, $active, $user['id'], $id]);
        $flash = 'Notification updated.';
    }
    if ($action === 'deactivate') {
        $stmt = db()->prepare('UPDATE activities SET active_status=0, updated_at=NOW(), modified_by=? WHERE id=?');
        $stmt->execute([$user['id'], $id]);
        $flash = 'Notification deactivated.';
    }
}

$filterModule = $_GET['module_name'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$sql = 'SELECT a.*, u.name AS owner_name, t.name AS target_user_name FROM activities a JOIN users u ON u.id = a.owner_user_id LEFT JOIN users t ON t.id = a.target_user_id WHERE 1=1';
$params = [];

// Visibility: admins (incl. State DSM) see everything; others see notifications
// either they own, or that are addressed to their role group with read_by =
// "any" or specifically to their user id.
if (!is_admin($user)) {
    $role = (string) ($user['role'] ?? '');
    $uid = (int) ($user['id'] ?? 0);
    $roleToWhom = array_search($role, $rolesByToWhom, true) ?: '';
    $sql .= ' AND (a.owner_user_id = ? OR (a.to_whom = ? AND (a.read_by = ' . "'any'" . ' OR a.target_user_id = ?)))';
    $params[] = $uid;
    $params[] = $roleToWhom;
    $params[] = $uid;
}
if ($filterModule !== '') {
    $sql .= ' AND a.module_name = ?';
    $params[] = $filterModule;
}
if ($filterStatus !== '') {
    $sql .= ' AND a.status = ?';
    $params[] = $filterStatus;
}
$sql .= ' ORDER BY a.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Build user picker grouped by target role.
$usersByRoleStmt = db()->query("SELECT id, name, role, assigned_districts FROM users WHERE active_status = 1 ORDER BY name ASC");
$usersByRole = ['State CRM' => [], 'State DSM' => [], 'District User' => []];
foreach ($usersByRoleStmt->fetchAll() as $u) {
    foreach ($rolesByToWhom as $label => $r) {
        if ($u['role'] === $r) {
            $usersByRole[$label][] = $u;
        }
    }
}

render_header('Notifications');
render_page_header('Notifications', [
    'icon' => 'bi-bell',
    'subtitle' => 'Escalation and Notifications.',
    'actions' => '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#activityModal" onclick="openAddModal()"><i class="bi bi-plus-lg me-1"></i>Add Notification</button>',
]);
?>
<?php if ($flash): ?><div class="alert alert-success d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i><span><?= esc($flash) ?></span></div><?php endif; ?>
<form class="filter-bar">
    <div class="row g-3 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label">Module</label>
            <select class="form-select" name="module_name">
                <option value="">All Modules</option>
                <option value="crm" <?= $filterModule==='crm'?'selected':'' ?>>CRM</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Status</label>
            <select class="form-select" name="status"><option value="">All Status</option><option value="open" <?= $filterStatus==='open'?'selected':'' ?>>Open</option><option value="in_progress" <?= $filterStatus==='in_progress'?'selected':'' ?>>In Progress</option><option value="closed" <?= $filterStatus==='closed'?'selected':'' ?>>Closed</option></select>
        </div>
        <div class="col-12 col-md-3">
            <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
            <a class="btn btn-light" href="/notifications.php">Reset</a>
        </div>
    </div>
</form>
<div class="card table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th>Module</th><th>Title</th><th>To Whom</th><th>Read By</th><th>Status</th><th>Owner</th><th class="text-end">Actions</th></tr></thead>
<tbody>
<?php if ($rows === []): ?>
<tr><td colspan="7"><div class="empty-state"><i class="bi bi-bell-slash"></i>No notifications found.</div></td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
<?php
$statusChip = ['open' => 'status-pending', 'in_progress' => 'status-info', 'closed' => 'status-yes'][$r['status']] ?? 'status-neutral';
$statusText = ucwords(str_replace('_', ' ', (string) $r['status']));
$readByDisplay = ($r['read_by'] === 'specific' && !empty($r['target_user_name'])) ? ('Specific: ' . $r['target_user_name']) : ucfirst((string) ($r['read_by'] ?? 'any'));
?>
<tr>
    <td><span class="status-chip status-neutral"><?= esc(strtoupper((string) $r['module_name'])) ?></span></td>
    <td class="fw-semibold"><?= esc($r['title']) ?><?php if (!empty($r['details'])): ?><div class="small text-muted"><?= esc($r['details']) ?></div><?php endif; ?></td>
    <td><?= $r['to_whom'] ? '<span class="status-chip status-info">' . esc((string) $r['to_whom']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
    <td><?= esc($readByDisplay) ?></td>
    <td><span class="status-chip <?= $statusChip ?>"><?= esc($statusText) ?></span></td>
    <td><?= esc($r['owner_name']) ?></td>
    <td>
        <div class="d-flex gap-1 justify-content-end">
            <?php if (((int) $r['owner_user_id'] === (int) $user['id']) || is_admin($user)): ?>
                <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i> Edit</button>
                <?php if ($r['active_status']): ?>
                    <form method="post" class="d-inline"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Close</button></form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>

<div class="modal fade" id="activityModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
<form method="post" id="activityForm">
<div class="modal-header"><h5 class="modal-title" id="activityModalTitle">Add Notification</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="action" id="formAction" value="add"><input type="hidden" name="id" id="activityId">
<div class="row g-2">
    <div class="col-md-4"><label class="form-label">Module</label><select class="form-select" name="module_name" id="module_name"><option value="crm">CRM</option></select></div>
    <div class="col-md-8"><label class="form-label">Title</label><input class="form-control" name="title" id="title" required></div>
    <div class="col-md-12"><label class="form-label">Details</label><textarea class="form-control" name="details" id="details"></textarea></div>
    <div class="col-md-4"><label class="form-label">To Whom</label>
        <select class="form-select" name="to_whom" id="to_whom">
            <option value="">All / Open</option>
            <option value="State CRM">State CRM</option>
            <option value="State DSM">State DSM</option>
            <option value="District User">District User</option>
        </select>
    </div>
    <div class="col-md-4"><label class="form-label">Read By</label>
        <select class="form-select" name="read_by" id="read_by">
            <option value="any">Any (anyone in that role)</option>
            <option value="specific">Specific user</option>
        </select>
    </div>
    <div class="col-md-4" id="targetUserCol" style="display:none;"><label class="form-label">Target User</label>
        <select class="form-select" name="target_user_id" id="target_user_id"><option value="">Select</option></select>
    </div>
    <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status" id="status"><option value="open">Open</option><option value="in_progress">In Progress</option><option value="closed">Closed</option></select></div>
    <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="active_status" id="active" checked><label class="form-check-label" for="active">Active</label></div></div>
</div>
</div>
<div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Save</button></div>
</form>
</div></div></div>

<script>
const usersByRole = <?= json_encode($usersByRole) ?>;

function refreshTargetUserOptions(selectedId) {
    const toWhom = document.getElementById('to_whom').value;
    const readBy = document.getElementById('read_by').value;
    const col = document.getElementById('targetUserCol');
    const sel = document.getElementById('target_user_id');
    if (readBy !== 'specific' || !toWhom) {
        col.style.display = 'none';
        sel.innerHTML = '<option value="">Select</option>';
        return;
    }
    const list = usersByRole[toWhom] || [];
    sel.innerHTML = '<option value="">Select</option>' + list.map((u) => {
        const districts = (u.assigned_districts || '').trim();
        const districtsHint = districts && toWhom === 'District User' ? ' (' + districts + ')' : '';
        return `<option value="${u.id}" ${String(u.id) === String(selectedId || '') ? 'selected' : ''}>${u.name}${districtsHint}</option>`;
    }).join('');
    col.style.display = '';
}
document.getElementById('to_whom').addEventListener('change', () => refreshTargetUserOptions());
document.getElementById('read_by').addEventListener('change', () => refreshTargetUserOptions());

function openAddModal() {
    document.getElementById('activityModalTitle').innerText = 'Add Notification';
    document.getElementById('formAction').value = 'add';
    document.getElementById('activityForm').reset();
    document.getElementById('active').checked = true;
    document.getElementById('module_name').value = 'crm';
    refreshTargetUserOptions();
}
function openEditModal(item) {
    document.getElementById('activityModalTitle').innerText = 'Edit Notification';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('activityId').value = item.id;
    document.getElementById('module_name').value = item.module_name || 'crm';
    document.getElementById('title').value = item.title;
    document.getElementById('details').value = item.details;
    document.getElementById('status').value = item.status;
    document.getElementById('active').checked = item.active_status == 1;
    document.getElementById('to_whom').value = item.to_whom || '';
    document.getElementById('read_by').value = item.read_by || 'any';
    refreshTargetUserOptions(item.target_user_id);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('activityModal')).show();
}
</script>
<?php render_footer(); ?>

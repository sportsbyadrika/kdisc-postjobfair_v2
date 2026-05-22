<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$user = current_user();
if (is_district_user($user)) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}
$flash = null;

if (is_post()) {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $module = $_POST['module_name'] ?? 'project';
    $title = trim($_POST['title'] ?? '');
    $details = trim($_POST['details'] ?? '');
    $status = $_POST['status'] ?? 'open';
    $active = isset($_POST['active_status']) ? 1 : 0;

    if ($action === 'add') {
        $stmt = db()->prepare('INSERT INTO activities (module_name, title, details, status, owner_user_id, active_status, created_at, updated_at, modified_by) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)');
        $stmt->execute([$module, $title, $details, $status, $user['id'], $active, $user['id']]);
        $flash = 'Activity added.';
    }
    if ($action === 'edit') {
        $stmt = db()->prepare('UPDATE activities SET module_name=?, title=?, details=?, status=?, active_status=?, updated_at=NOW(), modified_by=? WHERE id=?');
        $stmt->execute([$module, $title, $details, $status, $active, $user['id'], $id]);
        $flash = 'Activity updated.';
    }
    if ($action === 'deactivate') {
        $stmt = db()->prepare('UPDATE activities SET active_status=0, updated_at=NOW(), modified_by=? WHERE id=?');
        $stmt->execute([$user['id'], $id]);
        $flash = 'Activity deactivated.';
    }
}

$filterModule = $_GET['module_name'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$sql = 'SELECT a.*, u.name AS owner_name FROM activities a JOIN users u ON u.id = a.owner_user_id WHERE 1=1';
$params = [];
if ($user['role'] !== 'administrator') {
    $sql .= ' AND a.owner_user_id = ?';
    $params[] = $user['id'];
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

render_header('Activities');
render_page_header('Activities', [
    'icon' => 'bi-list-task',
    'subtitle' => 'Track project, CRM and report tasks and their progress.',
    'actions' => '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#activityModal" onclick="openAddModal()"><i class="bi bi-plus-lg me-1"></i>Add Activity</button>',
]);
?>
<?php if ($flash): ?><div class="alert alert-success d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i><span><?= esc($flash) ?></span></div><?php endif; ?>
<form class="filter-bar">
    <div class="row g-3 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label">Module</label>
            <select class="form-select" name="module_name"><option value="">All Modules</option><option value="project" <?= $filterModule==='project'?'selected':'' ?>>Project</option><option value="crm" <?= $filterModule==='crm'?'selected':'' ?>>CRM</option><option value="report" <?= $filterModule==='report'?'selected':'' ?>>Report</option></select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Status</label>
            <select class="form-select" name="status"><option value="">All Status</option><option value="open" <?= $filterStatus==='open'?'selected':'' ?>>Open</option><option value="in_progress" <?= $filterStatus==='in_progress'?'selected':'' ?>>In Progress</option><option value="closed" <?= $filterStatus==='closed'?'selected':'' ?>>Closed</option></select>
        </div>
        <div class="col-12 col-md-3">
            <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
            <a class="btn btn-light" href="/activities.php">Reset</a>
        </div>
    </div>
</form>
<div class="card table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th>Module</th><th>Title</th><th>Status</th><th>Owner</th><th>Active</th><th class="text-end">Actions</th></tr></thead>
<tbody>
<?php if ($rows === []): ?>
<tr><td colspan="6"><div class="empty-state"><i class="bi bi-clipboard-x"></i>No activities found.</div></td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
<?php
$statusChip = ['open' => 'status-pending', 'in_progress' => 'status-info', 'closed' => 'status-yes'][$r['status']] ?? 'status-neutral';
$statusText = ucwords(str_replace('_', ' ', (string) $r['status']));
?>
<tr>
    <td><span class="status-chip status-neutral"><?= esc(ucfirst((string) $r['module_name'])) ?></span></td>
    <td class="fw-semibold"><?= esc($r['title']) ?></td>
    <td><span class="status-chip <?= $statusChip ?>"><?= esc($statusText) ?></span></td>
    <td><?= esc($r['owner_name']) ?></td>
    <td><span class="status-chip <?= $r['active_status']?'status-yes':'status-neutral' ?>"><?= $r['active_status']?'Yes':'No' ?></span></td>
    <td>
        <div class="d-flex gap-1 justify-content-end">
        <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i> Edit</button>
        <?php if ($r['active_status']): ?><form method="post" class="d-inline"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Deactivate</button></form><?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>

<div class="modal fade" id="activityModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
<form method="post" id="activityForm">
<div class="modal-header"><h5 class="modal-title" id="activityModalTitle">Add Activity</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="action" id="formAction" value="add"><input type="hidden" name="id" id="activityId">
<div class="row g-2">
<div class="col-md-4"><label class="form-label">Module</label><select class="form-select" name="module_name" id="module_name"><option value="project">Project</option><option value="crm">CRM</option><option value="report">Report</option></select></div>
<div class="col-md-8"><label class="form-label">Title</label><input class="form-control" name="title" id="title" required></div>
<div class="col-md-12"><label class="form-label">Details</label><textarea class="form-control" name="details" id="details"></textarea></div>
<div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status" id="status"><option value="open">Open</option><option value="in_progress">In Progress</option><option value="closed">Closed</option></select></div>
<div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="active_status" id="active" checked><label class="form-check-label" for="active">Active</label></div></div>
</div>
</div>
<div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
</form>
</div></div></div>

<script>
function openAddModal() {
    document.getElementById('activityModalTitle').innerText = 'Add Activity';
    document.getElementById('formAction').value = 'add';
    document.getElementById('activityForm').reset();
    document.getElementById('active').checked = true;
}
function openEditModal(item) {
    document.getElementById('activityModalTitle').innerText = 'Edit Activity';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('activityId').value = item.id;
    document.getElementById('module_name').value = item.module_name;
    document.getElementById('title').value = item.title;
    document.getElementById('details').value = item.details;
    document.getElementById('status').value = item.status;
    document.getElementById('active').checked = item.active_status == 1;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('activityModal')).show();
}
</script>
<?php render_footer(); ?>

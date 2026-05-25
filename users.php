<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_admin();

$user = current_user();
$flash = null;

if (is_post()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $role = $_POST['role'] ?? 'crm_member';
        $mobile = trim($_POST['mobile_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $active = isset($_POST['active_status']) ? 1 : 0;

        if ($action === 'add') {
            $password = $_POST['password'] ?? '';
            $stmt = db()->prepare('INSERT INTO users (name, role, mobile_number, email, address, password_hash, active_status, created_at, updated_at, modified_by) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)');
            $stmt->execute([$name, $role, $mobile, $email, $address, password_hash($password, PASSWORD_DEFAULT), $active, $user['id']]);
            $flash = 'User added.';
        } else {
            $params = [$name, $role, $mobile, $email, $address, $active, $user['id'], $id];
            if (!empty($_POST['password'])) {
                $stmt = db()->prepare('UPDATE users SET name=?, role=?, mobile_number=?, email=?, address=?, active_status=?, password_hash=?, updated_at=NOW(), modified_by=? WHERE id=?');
                $params = [$name, $role, $mobile, $email, $address, $active, password_hash($_POST['password'], PASSWORD_DEFAULT), $user['id'], $id];
            } else {
                $stmt = db()->prepare('UPDATE users SET name=?, role=?, mobile_number=?, email=?, address=?, active_status=?, updated_at=NOW(), modified_by=? WHERE id=?');
            }
            $stmt->execute($params);
            $flash = 'User updated.';
        }
    }

    if ($action === 'deactivate') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('UPDATE users SET active_status = 0, updated_at = NOW(), modified_by = ? WHERE id = ?');
        $stmt->execute([$user['id'], $id]);
        $flash = 'User deactivated.';
    }
}

$filterRole = $_GET['role'] ?? '';
$filterStatus = $_GET['active_status'] ?? '';
$filterName = trim((string) ($_GET['name'] ?? ''));
$filterMobile = trim((string) ($_GET['mobile_number'] ?? ''));
$sql = 'SELECT id, name, role, mobile_number, email, address, active_status FROM users WHERE 1=1';
$params = [];
if ($filterRole !== '') {
    $sql .= ' AND role = ?';
    $params[] = $filterRole;
}
if ($filterStatus !== '') {
    $sql .= ' AND active_status = ?';
    $params[] = (int) $filterStatus;
}
if ($filterName !== '') {
    $sql .= ' AND name LIKE ?';
    $params[] = '%' . $filterName . '%';
}
if ($filterMobile !== '') {
    $sql .= ' AND mobile_number LIKE ?';
    $params[] = '%' . $filterMobile . '%';
}
$sql .= ' ORDER BY id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

render_header('Users');
render_page_header('User Management', [
    'icon' => 'bi-people-fill',
    'subtitle' => 'Create and manage Administrator, CRM and District user accounts.',
    'actions' => '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openAddModal()"><i class="bi bi-plus-lg me-1"></i>Add User</button>',
]);
?>
<?php if ($flash): ?><div class="alert alert-success d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i><span><?= esc($flash) ?></span></div><?php endif; ?>
<form class="filter-bar">
    <div class="row g-3 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label">Name</label>
            <input class="form-control" name="name" value="<?= esc($filterName) ?>" placeholder="Search name">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Mobile Number</label>
            <input class="form-control" name="mobile_number" value="<?= esc($filterMobile) ?>" inputmode="numeric" placeholder="Search mobile">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Role</label>
            <select class="form-select" name="role"><option value="">All Roles</option><option value="administrator" <?= $filterRole==='administrator'?'selected':'' ?>>Administrator</option><option value="crm_member" <?= $filterRole==='crm_member'?'selected':'' ?>>CRM Member</option><option value="district_user" <?= $filterRole==='district_user'?'selected':'' ?>>District User</option></select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Status</label>
            <select class="form-select" name="active_status"><option value="">All Status</option><option value="1" <?= $filterStatus==='1'?'selected':'' ?>>Active</option><option value="0" <?= $filterStatus==='0'?'selected':'' ?>>Inactive</option></select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
            <a class="btn btn-light" href="/users.php">Reset</a>
        </div>
    </div>
</form>
<div class="card table-card">
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead><tr><th>Name</th><th>Role</th><th>Mobile</th><th>Email</th><th>Address</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php if ($users === []): ?>
        <tr><td colspan="7"><div class="empty-state"><i class="bi bi-people"></i>No users found for the selected filters.</div></td></tr>
    <?php endif; ?>
    <?php foreach ($users as $u): ?>
        <tr>
            <td class="fw-semibold"><?= esc($u['name']) ?></td>
            <td><span class="status-chip status-neutral"><?= esc(role_label($u['role'])) ?></span></td>
            <td><?= esc($u['mobile_number']) ?></td><td><?= esc($u['email']) ?></td><td><?= esc($u['address']) ?></td>
            <td><span class="status-chip <?= $u['active_status']?'status-yes':'status-neutral' ?>"><?= $u['active_status']?'Active':'Inactive' ?></span></td>
            <td>
                <div class="d-flex gap-1 justify-content-end">
                <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= json_encode($u, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i> Edit</button>
                <?php if ($u['active_status']): ?>
                <form method="post" class="d-inline"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn btn-sm btn-outline-danger" onclick="return confirm('Deactivate this user?')"><i class="bi bi-person-x"></i> Deactivate</button></form>
                <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
      <form method="post" id="userForm">
        <div class="modal-header"><h5 class="modal-title" id="userModalTitle">Add User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="userId">
            <div class="row g-2">
                <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" id="name" required></div>
                <div class="col-md-6"><label class="form-label">Role</label><select class="form-select" name="role" id="role"><option value="administrator">Administrator</option><option value="crm_member">CRM Member</option><option value="district_user">District User</option></select></div>
                <div class="col-md-6"><label class="form-label">Mobile</label><input class="form-control" name="mobile_number" id="mobile" required></div>
                <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" id="email"></div>
                <div class="col-md-12"><label class="form-label">Address</label><textarea class="form-control" name="address" id="address"></textarea></div>
                <div class="col-md-6"><label class="form-label">Password</label><input class="form-control" type="password" name="password" id="password"></div>
                <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="active_status" id="active" checked><label class="form-check-label" for="active">Active</label></div></div>
            </div>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
      </form>
  </div></div>
</div>
<script>
function openAddModal() {
    document.getElementById('userModalTitle').innerText = 'Add User';
    document.getElementById('formAction').value = 'add';
    document.getElementById('userId').value = '';
    document.getElementById('userForm').reset();
    document.getElementById('active').checked = true;
}
function openEditModal(user) {
    document.getElementById('userModalTitle').innerText = 'Edit User';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('userId').value = user.id;
    document.getElementById('name').value = user.name;
    document.getElementById('role').value = user.role;
    document.getElementById('mobile').value = user.mobile_number;
    document.getElementById('email').value = user.email;
    document.getElementById('address').value = user.address;
    document.getElementById('password').value = '';
    document.getElementById('active').checked = user.active_status == 1;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal')).show();
}
</script>
<?php render_footer(); ?>

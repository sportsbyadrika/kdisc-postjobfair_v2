<?php
/**
 * Users administration page.
 *
 * Admin-only. Lists every user (super-admin rows are hidden unless the
 * viewer is super-admin themselves). Edit modal collects every field
 * except password; the row has a dedicated Reset Password icon for
 * password changes. Photo upload uses Cropper.js (CDN) to produce a
 * passport-sized JPEG that lands in uploads/user_photo/<id>.jpg.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/user_photo_helpers.php';
require_admin();

/* ---------- One-time self-migrations ---------- */
$hasAssignedDistrictsCol = db()->query("SHOW COLUMNS FROM users LIKE 'assigned_districts'")->fetchAll() !== [];
if (!$hasAssignedDistrictsCol) {
    db()->query("ALTER TABLE users ADD COLUMN assigned_districts TEXT NULL AFTER address");
}
// New columns for Phase A (photo + designation + office + super admin).
try {
    $cols = [];
    foreach (db()->query('SHOW COLUMNS FROM users')->fetchAll() as $c) $cols[strtolower((string) $c['Field'])] = true;
    if (!isset($cols['photo_path']))     db()->query("ALTER TABLE users ADD COLUMN photo_path VARCHAR(400) NULL AFTER avatar_colour");
    if (!isset($cols['designation']))    db()->query("ALTER TABLE users ADD COLUMN designation VARCHAR(200) NULL AFTER photo_path");
    if (!isset($cols['office_id']))      db()->query("ALTER TABLE users ADD COLUMN office_id INT NULL AFTER designation");
    if (!isset($cols['is_super_admin'])) db()->query("ALTER TABLE users ADD COLUMN is_super_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER active_status");
} catch (Throwable $e) { /* ALTER refused — new fields silently absent on hosted install */ }
task_tracker_bootstrap();

/* Upload dir + save_cropped_photo() come from includes/user_photo_helpers.php. */

$user = current_user();
$viewerIsSuperAdmin = (int) ($user['is_super_admin'] ?? 0) === 1;
$flash = null; $flashType = 'success';

function parse_assigned_districts(): ?string
{
    if (isset($_POST['all_districts'])) return null;
    $arr = $_POST['assigned_districts'] ?? [];
    if (!is_array($arr)) return null;
    $clean = array_values(array_filter(array_map(static fn($v) => trim((string) $v), $arr), static fn($v) => $v !== ''));
    return $clean === [] ? null : implode(',', $clean);
}

/* ---------- POST handlers ---------- */
if (is_post()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id          = (int) ($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $role        = $_POST['role'] ?? 'crm_member';
        $mobile      = trim($_POST['mobile_number'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $address     = trim($_POST['address'] ?? '');
        $designation = trim((string) ($_POST['designation'] ?? ''));
        $officeId    = (int) ($_POST['office_id'] ?? 0);
        $active      = isset($_POST['active_status']) ? 1 : 0;
        $isSuperReq  = isset($_POST['is_super_admin']) ? 1 : 0;
        // Only super-admin can promote another user to super-admin.
        $isSuper     = $viewerIsSuperAdmin ? $isSuperReq : 0;
        $assignedDistricts = parse_assigned_districts();
        $photoData   = (string) ($_POST['photo_cropped_data'] ?? '');

        if ($action === 'add') {
            // Default password = the mobile number (user resets on first login via Reset Password modal if admin changes it).
            $defaultPw = $mobile !== '' ? $mobile : 'changeme123';
            $stmt = db()->prepare('INSERT INTO users
                (name, role, mobile_number, email, address, designation, office_id, assigned_districts,
                 password_hash, active_status, is_super_admin, created_at, updated_at, modified_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)');
            $stmt->execute([$name, $role, $mobile, $email,
                $address === '' ? null : $address,
                $designation === '' ? null : $designation,
                $officeId > 0 ? $officeId : null,
                $assignedDistricts,
                password_hash($defaultPw, PASSWORD_DEFAULT),
                $active, $isSuper, $user['id']]);
            $id = (int) db()->lastInsertId();
            $flash = 'User added. Initial password = mobile number — ask them to change it, or reset it here.';
        } else {
            // Protect: a non-super-admin can't edit a super-admin's row.
            if (!$viewerIsSuperAdmin) {
                $owner = db()->prepare('SELECT is_super_admin FROM users WHERE id = ?');
                $owner->execute([$id]);
                if ((int) ($owner->fetchColumn() ?: 0) === 1) {
                    $flash = 'Only a super-admin can edit a super-admin user.'; $flashType = 'danger';
                    goto skip_update;
                }
            }
            $stmt = db()->prepare('UPDATE users SET name=?, role=?, mobile_number=?, email=?, address=?,
                designation=?, office_id=?, assigned_districts=?, active_status=?, is_super_admin=?,
                updated_at=NOW(), modified_by=? WHERE id=?');
            $stmt->execute([$name, $role, $mobile, $email,
                $address === '' ? null : $address,
                $designation === '' ? null : $designation,
                $officeId > 0 ? $officeId : null,
                $assignedDistricts, $active, $isSuper, $user['id'], $id]);
            $flash = 'User updated.';
        }
        skip_update:

        // Photo — only when a fresh crop came in.
        if ($id > 0 && $photoData !== '') {
            $stored = save_cropped_photo($photoData, $id);
            if ($stored !== null) {
                // Drop the previous file on disk so we don't orphan it.
                $prev = db()->prepare('SELECT photo_path FROM users WHERE id = ?');
                $prev->execute([$id]);
                $prevPath = (string) ($prev->fetchColumn() ?: '');
                if ($prevPath !== '' && $prevPath !== $stored && is_file(__DIR__ . '/' . ltrim($prevPath, '/'))) {
                    @unlink(__DIR__ . '/' . ltrim($prevPath, '/'));
                }
                db()->prepare('UPDATE users SET photo_path = ?, updated_at = NOW() WHERE id = ?')->execute([$stored, $id]);
            }
        }

        // Persist module grants + role-group memberships.
        try {
            $modGrants = (array) ($_POST['module_grants'] ?? []);
            $groupIds  = array_map('intval', (array) ($_POST['role_groups'] ?? []));
            $validMod  = []; foreach (rbac_all_modules() as $m) $validMod[(int) $m['id']] = true;
            $validRole = []; foreach (rbac_all_module_roles() as $mid => $roles) foreach ($roles as $r) $validRole[$mid][(int) $r['id']] = true;
            db()->prepare('DELETE FROM user_module_role WHERE user_id = ?')->execute([$id]);
            $insM = db()->prepare('INSERT INTO user_module_role (user_id, module_id, module_role_id, created_at, created_by) VALUES (?, ?, ?, NOW(), ?)');
            foreach ($modGrants as $mid => $rid) {
                $mid = (int) $mid; $rid = (int) $rid;
                if ($mid <= 0 || $rid <= 0) continue;
                if (!isset($validMod[$mid]) || !isset($validRole[$mid][$rid])) continue;
                $insM->execute([$id, $mid, $rid, $user['id']]);
            }
            db()->prepare('DELETE FROM user_role_group WHERE user_id = ?')->execute([$id]);
            $insG = db()->prepare('INSERT INTO user_role_group (user_id, role_group_id, created_at, created_by) VALUES (?, ?, NOW(), ?)');
            foreach (array_unique($groupIds) as $gid) if ($gid > 0) $insG->execute([$id, $gid, $user['id']]);
        } catch (Throwable $e) {
            $flash = 'User saved, but module grants could not be persisted: ' . $e->getMessage();
            $flashType = 'warning';
        }
    }

    if ($action === 'reset_password') {
        $id  = (int) ($_POST['id'] ?? 0);
        $pw  = (string) ($_POST['password'] ?? '');
        $pw2 = (string) ($_POST['password_confirm'] ?? '');
        if ($id <= 0)             { $flash = 'Missing user id.'; $flashType = 'danger'; }
        elseif (strlen($pw) < 6)  { $flash = 'Password must be at least 6 characters.'; $flashType = 'danger'; }
        elseif ($pw !== $pw2)     { $flash = 'The two passwords do not match.'; $flashType = 'danger'; }
        else {
            // Super-admin protection as above.
            $owner = db()->prepare('SELECT is_super_admin FROM users WHERE id = ?');
            $owner->execute([$id]);
            if ((int) ($owner->fetchColumn() ?: 0) === 1 && !$viewerIsSuperAdmin) {
                $flash = 'Only a super-admin can reset a super-admin\'s password.'; $flashType = 'danger';
            } else {
                db()->prepare('UPDATE users SET password_hash = ?, updated_at = NOW(), modified_by = ? WHERE id = ?')
                    ->execute([password_hash($pw, PASSWORD_DEFAULT), $user['id'], $id]);
                $flash = 'Password reset for user.';
            }
        }
    }

    if ($action === 'deactivate') {
        $id = (int) ($_POST['id'] ?? 0);
        $owner = db()->prepare('SELECT is_super_admin FROM users WHERE id = ?');
        $owner->execute([$id]);
        if ((int) ($owner->fetchColumn() ?: 0) === 1 && !$viewerIsSuperAdmin) {
            $flash = 'Only a super-admin can deactivate a super-admin.'; $flashType = 'danger';
        } else {
            db()->prepare('UPDATE users SET active_status = 0, updated_at = NOW(), modified_by = ? WHERE id = ?')
                ->execute([$user['id'], $id]);
            $flash = 'User deactivated.';
        }
    }
}

/* ---------- List query ---------- */
$filterRole   = $_GET['role'] ?? '';
$filterStatus = $_GET['active_status'] ?? '';
$filterName   = trim((string) ($_GET['name'] ?? ''));
$filterMobile = trim((string) ($_GET['mobile_number'] ?? ''));
$sql = "SELECT u.id, u.name, u.role, u.mobile_number, u.email, u.address, u.assigned_districts,
        u.photo_path, u.designation, u.office_id, u.avatar_colour, u.active_status, u.is_super_admin,
        o.name AS office_name
    FROM users u
    LEFT JOIN office_hierarchy_nodes o ON o.id = u.office_id AND o.level_type = 'office'
    WHERE 1=1";
$params = [];
if (!$viewerIsSuperAdmin) {
    $sql .= ' AND (u.is_super_admin = 0 OR u.id = ?)'; // viewer sees self even if super
    $params[] = (int) $user['id'];
}
if ($filterRole   !== '') { $sql .= ' AND u.role = ?'; $params[] = $filterRole; }
if ($filterStatus !== '') { $sql .= ' AND u.active_status = ?'; $params[] = (int) $filterStatus; }
if ($filterName   !== '') { $sql .= ' AND u.name LIKE ?'; $params[] = '%' . $filterName . '%'; }
if ($filterMobile !== '') { $sql .= ' AND u.mobile_number LIKE ?'; $params[] = '%' . $filterMobile . '%'; }
$sql .= ' ORDER BY u.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$rbacModules = rbac_all_modules();
$rbacRoles   = rbac_all_module_roles();
$roleGroups  = [];
try { $roleGroups = db()->query('SELECT id, name, description, is_active FROM role_group WHERE is_active = 1 ORDER BY name ASC')->fetchAll(); } catch (Throwable $e) {}
$grantsByUser = []; $groupsByUser = [];
try {
    foreach (db()->query('SELECT user_id, module_id, module_role_id FROM user_module_role')->fetchAll() as $r)
        $grantsByUser[(int) $r['user_id']][(int) $r['module_id']] = (int) $r['module_role_id'];
    foreach (db()->query('SELECT user_id, role_group_id FROM user_role_group')->fetchAll() as $r)
        $groupsByUser[(int) $r['user_id']][] = (int) $r['role_group_id'];
} catch (Throwable $e) {}

$districtOptions = array_map(
    static fn(array $row): string => (string) $row['Candidate_District'],
    db()->query("SELECT DISTINCT Candidate_District FROM job_fair_result WHERE Candidate_District IS NOT NULL AND TRIM(Candidate_District) <> '' AND Candidate_District <> 'State Level' ORDER BY Candidate_District ASC")->fetchAll()
);
array_unshift($districtOptions, 'State Level');

// Office master — only level_type = 'office' rows from office_hierarchy_nodes.
$officeOptions = [];
try {
    $officeOptions = db()->query("SELECT id, name FROM office_hierarchy_nodes
        WHERE level_type = 'office' AND active_status = 1
        ORDER BY name ASC")->fetchAll();
} catch (Throwable $e) {}

// Shared avatar renderer lives in includes/layout.php (user_avatar_html).
// Alias keeps this file's call sites short.
$renderUserAvatar = static fn(array $u, int $size = 36): string => user_avatar_html($u, $size);

render_header('Users');
render_page_header('User Management', [
    'icon' => 'bi-people-fill',
    'subtitle' => 'Create and manage user accounts. Photos replace coloured avatars; passwords are reset from the row\'s key icon.',
    'actions' => '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openAddModal()"><i class="bi bi-plus-lg me-1"></i>Add User</button>',
]);
?>

<?php if ($flash): ?><div class="alert alert-<?= esc($flashType) ?> d-flex align-items-center gap-2"><i class="bi bi-info-circle-fill"></i><span><?= esc($flash) ?></span></div><?php endif; ?>

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
            <select class="form-select" name="role"><option value="">All Roles</option><option value="administrator" <?= $filterRole==='administrator'?'selected':'' ?>>Administrator</option><option value="dsm_admin" <?= $filterRole==='dsm_admin'?'selected':'' ?>>DSM Admin</option><option value="state_dsm" <?= $filterRole==='state_dsm'?'selected':'' ?>>State DSM</option><option value="crm_member" <?= $filterRole==='crm_member'?'selected':'' ?>>CRM Member</option><option value="district_user" <?= $filterRole==='district_user'?'selected':'' ?>>District User</option><option value="district_pmu" <?= $filterRole==='district_pmu'?'selected':'' ?>>District PMU</option><option value="state_pmu" <?= $filterRole==='state_pmu'?'selected':'' ?>>State PMU</option><option value="edms" <?= $filterRole==='edms'?'selected':'' ?>>EDMS</option><option value="kdisc_hq" <?= $filterRole==='kdisc_hq'?'selected':'' ?>>KDISC HQ</option></select>
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
    <thead><tr><th>Name</th><th>Role</th><th>Designation</th><th>Office</th><th>Mobile</th><th>Districts</th><th>Status</th><th class="text-end" style="width: 140px;">Actions</th></tr></thead>
    <tbody>
    <?php if ($users === []): ?>
        <tr><td colspan="8"><div class="empty-state"><i class="bi bi-people"></i>No users found for the selected filters.</div></td></tr>
    <?php endif; ?>
    <?php foreach ($users as $u): ?>
        <?php
            $assigned = trim((string) ($u['assigned_districts'] ?? ''));
            $assignedList = $assigned === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $assigned))));
            $uid = (int) $u['id'];
            $userPayload = array_merge((array) $u, [
                'module_grants' => (object) ($grantsByUser[$uid] ?? []),
                'role_groups'   => $groupsByUser[$uid] ?? [],
            ]);
        ?>
        <tr>
            <td class="fw-semibold">
                <span class="d-inline-flex align-items-center gap-2">
                    <?= $renderUserAvatar($u, 36) ?>
                    <span>
                        <?= esc($u['name']) ?>
                        <?php if ((int) ($u['is_super_admin'] ?? 0) === 1): ?><span class="badge text-bg-warning ms-1" title="Super admin"><i class="bi bi-shield-fill-check"></i></span><?php endif; ?>
                        <?php if (!empty($u['email'])): ?><div class="small text-muted"><?= esc($u['email']) ?></div><?php endif; ?>
                    </span>
                </span>
            </td>
            <td><span class="status-chip status-neutral"><?= esc(role_label($u['role'])) ?></span></td>
            <td class="small"><?= esc((string) ($u['designation'] ?? '—')) ?></td>
            <td class="small"><?= esc((string) ($u['office_name'] ?? '—')) ?></td>
            <td class="small"><?= esc($u['mobile_number']) ?></td>
            <td>
                <?php if ($assignedList === []): ?>
                    <span class="status-chip status-info">All Districts</span>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($assignedList as $district): ?>
                            <span class="status-chip status-neutral"><?= esc($district) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </td>
            <td><span class="status-chip <?= $u['active_status']?'status-yes':'status-neutral' ?>"><?= $u['active_status']?'Active':'Inactive' ?></span></td>
            <td>
                <div class="d-flex gap-1 justify-content-end">
                    <button class="btn btn-sm btn-outline-primary"
                            onclick='openEditModal(<?= json_encode($userPayload, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'
                            title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-secondary"
                            onclick='openResetModal(<?= $uid ?>, <?= json_encode((string) $u['name']) ?>)'
                            title="Reset password"><i class="bi bi-key"></i></button>
                    <?php if ($u['active_status']): ?>
                    <form method="post" class="d-inline"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?= $uid ?>"><button class="btn btn-sm btn-outline-danger" onclick="return confirm('Deactivate this user?')" title="Deactivate"><i class="bi bi-person-x"></i></button></form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<!-- ====================================================================
     EDIT / ADD USER MODAL
     ==================================================================== -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl"><div class="modal-content">
      <form method="post" id="userForm" enctype="multipart/form-data">
        <div class="modal-header"><h5 class="modal-title" id="userModalTitle">Add User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="userId">
            <input type="hidden" name="photo_cropped_data" id="photoCroppedData" value="">

            <div class="row g-3">
                <!-- Left column: identity, role, districts, photo -->
                <div class="col-lg-7">
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label">Name <span class="text-danger">*</span></label><input class="form-control" name="name" id="name" required></div>
                        <div class="col-md-6"><label class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select" name="role" id="role">
                                <option value="administrator">Administrator</option>
                                <option value="dsm_admin">DSM Admin</option>
                                <option value="state_dsm">State DSM</option>
                                <option value="crm_member">CRM Member</option>
                                <option value="district_user">District User</option>
                                <option value="district_pmu">District PMU</option>
                                <option value="state_pmu">State PMU</option>
                                <option value="edms">EDMS</option>
                                <option value="kdisc_hq">KDISC HQ</option>
                            </select>
                        </div>
                        <div class="col-md-6"><label class="form-label">Mobile <span class="text-danger">*</span></label><input class="form-control" name="mobile_number" id="mobile" required></div>
                        <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" id="email"></div>
                        <div class="col-md-6"><label class="form-label">Designation</label><input class="form-control" name="designation" id="designation" maxlength="200" placeholder="e.g. Programme Manager"></div>
                        <div class="col-md-6"><label class="form-label">Office</label>
                            <select class="form-select" name="office_id" id="office_id">
                                <option value="0">— select office —</option>
                                <?php foreach ($officeOptions as $o): ?>
                                    <option value="<?= (int) $o['id'] ?>"><?= esc((string) $o['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12"><label class="form-label">Address</label><textarea class="form-control" name="address" id="address" rows="2"></textarea></div>
                    </div>

                    <!-- Districts + Photo on one row -->
                    <div class="row g-3 mt-1">
                        <div class="col-md-7">
                            <label class="form-label d-flex justify-content-between align-items-center">
                                <span>Districts</span>
                                <span class="form-check m-0">
                                    <input class="form-check-input" type="checkbox" name="all_districts" id="all_districts" value="1">
                                    <label class="form-check-label small" for="all_districts">All Districts</label>
                                </span>
                            </label>
                            <div class="border rounded p-2" id="districtGrid" style="max-height: 240px; overflow-y: auto;">
                                <div class="row g-1">
                                    <?php foreach ($districtOptions as $district): ?>
                                        <div class="col-6">
                                            <div class="form-check">
                                                <input class="form-check-input js-district-cb" type="checkbox" name="assigned_districts[]" value="<?= esc($district) ?>" id="dist_<?= esc(preg_replace('/[^A-Za-z0-9]/', '_', $district)) ?>">
                                                <label class="form-check-label small" for="dist_<?= esc(preg_replace('/[^A-Za-z0-9]/', '_', $district)) ?>"><?= esc($district) ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-text">Tick <strong>All Districts</strong> to grant access to every district (ticked boxes below are ignored).</div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Passport photo</label>
                            <div class="border rounded p-2 text-center" style="background:#f8fafc;">
                                <div id="photoPreviewWrap" style="width: 140px; height: 180px; margin: 0 auto 8px auto; background: #e5e7eb; border: 1px solid #cbd5e1; display:flex; align-items:center; justify-content:center; color:#94a3b8; overflow: hidden;">
                                    <span id="photoPreviewPlaceholder" class="small">No photo</span>
                                    <img id="photoPreviewImg" alt="" style="display:none; width:100%; height:100%; object-fit:cover;">
                                </div>
                                <input type="file" accept="image/jpeg,image/png,image/webp" id="photoFile" class="form-control form-control-sm">
                                <div class="small text-muted mt-1">Choose a JPG/PNG — crop opens next. Saves ~400×514 (passport ratio).</div>
                            </div>
                        </div>

                        <div class="col-md-6"><div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="active_status" id="active" checked>
                            <label class="form-check-label" for="active">Active</label>
                        </div></div>
                        <?php if ($viewerIsSuperAdmin): ?>
                        <div class="col-md-6"><div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="is_super_admin" id="is_super_admin" value="1">
                            <label class="form-check-label" for="is_super_admin"><strong>Super admin</strong> <span class="small text-muted">(hidden from the users list for non-super viewers)</span></label>
                        </div></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right column: module grants + role groups -->
                <div class="col-lg-5">
                    <?php if ($rbacModules !== []): ?>
                    <div class="border rounded p-2 h-100 bg-light-subtle">
                        <div class="fw-semibold mb-2"><i class="bi bi-shield-check me-1"></i>Module grants</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th>Module</th>
                                        <th class="text-center" title="Admin">A</th>
                                        <th class="text-center" title="Reviewer">R</th>
                                        <th class="text-center" title="User">U</th>
                                        <th class="text-center" title="None">—</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rbacModules as $m):
                                        $mid = (int) $m['id'];
                                        $roles = $rbacRoles[$mid] ?? [];
                                        $rid = static function (string $code) use ($roles): int {
                                            foreach ($roles as $r) if ((string) $r['code'] === $code) return (int) $r['id'];
                                            return 0;
                                        };
                                    ?>
                                        <tr>
                                            <td class="fw-semibold"><?= esc((string) $m['name']) ?></td>
                                            <?php foreach (['admin','reviewer','user'] as $rc): ?>
                                                <td class="text-center"><input type="radio" class="form-check-input js-mgrant" name="module_grants[<?= $mid ?>]" value="<?= $rid($rc) ?>" data-module="<?= $mid ?>"></td>
                                            <?php endforeach; ?>
                                            <td class="text-center"><input type="radio" class="form-check-input js-mgrant" name="module_grants[<?= $mid ?>]" value="0" data-module="<?= $mid ?>" checked></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($roleGroups !== []): ?>
                            <div class="fw-semibold mb-1 mt-3"><i class="bi bi-collection me-1"></i>Role groups</div>
                            <div class="small text-muted mb-2">Effective role = highest of direct grants + every group the user is in.</div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($roleGroups as $g): ?>
                                    <label class="form-check">
                                        <input class="form-check-input js-rgroup" type="checkbox" name="role_groups[]" value="<?= (int) $g['id'] ?>">
                                        <span class="form-check-label small"><?= esc((string) $g['name']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Save</button></div>
      </form>
  </div></div>
</div>

<!-- Photo crop modal (Cropper.js) -->
<div class="modal fade" id="cropModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-crop me-1"></i>Crop photo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="small text-muted mb-2">Drag the crop handle to frame the face — passport ratio (3.5:4.5) is enforced.</div>
            <div style="max-height: 60vh; overflow: hidden;">
                <img id="cropSource" src="" alt="" style="max-width:100%; display:block;">
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="cropApply"><i class="bi bi-check2 me-1"></i>Apply crop</button></div>
    </div></div>
</div>

<!-- Reset password modal -->
<div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" id="resetUserId" value="0">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-key text-warning me-1"></i>Reset password · <span id="resetUserName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">New password <span class="text-danger">*</span></label><input class="form-control" type="password" name="password" id="resetPw" required minlength="6"></div>
                    <div class="col-md-6"><label class="form-label">Confirm <span class="text-danger">*</span></label><input class="form-control" type="password" name="password_confirm" id="resetPw2" required minlength="6"></div>
                    <div class="col-12 small text-muted">Minimum 6 characters. The user will need to use this password on their next login — tell them to change it from their profile.</div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning"><i class="bi bi-key me-1"></i>Reset</button></div>
        </form>
    </div></div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
<script>
/* ---------- Districts: All-check toggles the grid ---------- */
function syncDistrictControls() {
    const allCb = document.getElementById('all_districts');
    const grid = document.getElementById('districtGrid');
    const cbs = grid.querySelectorAll('.js-district-cb');
    if (allCb.checked) { cbs.forEach(c => { c.checked = false; c.disabled = true; }); }
    else               { cbs.forEach(c => { c.disabled = false; }); }
}
document.getElementById('all_districts').addEventListener('change', syncDistrictControls);

/* ---------- Module grants reset ---------- */
function resetModuleGrants() {
    document.querySelectorAll('input.js-mgrant[value="0"]').forEach(r => r.checked = true);
    document.querySelectorAll('input.js-rgroup').forEach(c => c.checked = false);
}

/* ---------- Open Add / Edit modals ---------- */
function openAddModal() {
    document.getElementById('userModalTitle').innerText = 'Add User';
    document.getElementById('formAction').value = 'add';
    document.getElementById('userId').value = '';
    document.getElementById('userForm').reset();
    document.getElementById('photoCroppedData').value = '';
    document.getElementById('photoPreviewImg').style.display = 'none';
    document.getElementById('photoPreviewImg').src = '';
    document.getElementById('photoPreviewPlaceholder').style.display = '';
    document.getElementById('active').checked = true;
    document.getElementById('all_districts').checked = true;
    syncDistrictControls();
    resetModuleGrants();
}

function openEditModal(user) {
    document.getElementById('userModalTitle').innerText = 'Edit User';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('userId').value = user.id;
    document.getElementById('name').value = user.name || '';
    document.getElementById('role').value = user.role || 'crm_member';
    document.getElementById('mobile').value = user.mobile_number || '';
    document.getElementById('email').value = user.email || '';
    document.getElementById('address').value = user.address || '';
    document.getElementById('designation').value = user.designation || '';
    document.getElementById('office_id').value = String(user.office_id || 0);
    document.getElementById('active').checked = Number(user.active_status) === 1;
    const superCb = document.getElementById('is_super_admin');
    if (superCb) superCb.checked = Number(user.is_super_admin) === 1;

    document.getElementById('photoCroppedData').value = '';
    const img = document.getElementById('photoPreviewImg');
    const ph  = document.getElementById('photoPreviewPlaceholder');
    if (user.photo_path) { img.src = '/' + user.photo_path; img.style.display = ''; ph.style.display = 'none'; }
    else { img.src = ''; img.style.display = 'none'; ph.style.display = ''; }

    const allCb = document.getElementById('all_districts');
    const assigned = (user.assigned_districts || '').trim();
    const set = new Set(assigned === '' ? [] : assigned.split(',').map(s => s.trim()));
    allCb.checked = assigned === '';
    document.querySelectorAll('.js-district-cb').forEach(cb => cb.checked = set.has(cb.value));
    syncDistrictControls();

    resetModuleGrants();
    const grants = user.module_grants || {};
    Object.keys(grants).forEach(mid => {
        const rid = String(grants[mid]);
        const radio = document.querySelector(`input.js-mgrant[name="module_grants[${mid}]"][value="${rid}"]`);
        if (radio) radio.checked = true;
    });
    (user.role_groups || []).forEach(gid => {
        const box = document.querySelector(`input.js-rgroup[value="${gid}"]`);
        if (box) box.checked = true;
    });

    bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal')).show();
}

/* ---------- Photo + Cropper.js ---------- */
let cropperInstance = null;
document.getElementById('photoFile').addEventListener('change', (e) => {
    const file = e.target.files[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = (ev) => {
        document.getElementById('cropSource').src = ev.target.result;
        const modal = new bootstrap.Modal(document.getElementById('cropModal'));
        modal.show();
        document.getElementById('cropModal').addEventListener('shown.bs.modal', () => {
            if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
            cropperInstance = new Cropper(document.getElementById('cropSource'), {
                aspectRatio: 3.5 / 4.5, viewMode: 1, autoCropArea: 0.9, background: false,
            });
        }, { once: true });
    };
    reader.readAsDataURL(file);
});
document.getElementById('cropApply').addEventListener('click', () => {
    if (!cropperInstance) return;
    const canvas = cropperInstance.getCroppedCanvas({ width: 400, height: 514, imageSmoothingQuality: 'high' });
    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    document.getElementById('photoCroppedData').value = dataUrl;
    const img = document.getElementById('photoPreviewImg');
    img.src = dataUrl; img.style.display = '';
    document.getElementById('photoPreviewPlaceholder').style.display = 'none';
    bootstrap.Modal.getInstance(document.getElementById('cropModal')).hide();
    cropperInstance.destroy(); cropperInstance = null;
    document.getElementById('photoFile').value = '';
});

/* ---------- Reset password modal ---------- */
function openResetModal(id, name) {
    document.getElementById('resetUserId').value = id;
    document.getElementById('resetUserName').textContent = name || '';
    document.getElementById('resetPw').value = '';
    document.getElementById('resetPw2').value = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('resetModal')).show();
}
</script>

<?php render_footer(); ?>

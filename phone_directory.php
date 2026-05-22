<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

require_auth();

$user = current_user();
$isAdmin = is_admin($user);
$flash = null;

if ($isAdmin && is_post()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $team = trim($_POST['team'] ?? '');
        $mobile = trim($_POST['mobile_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $active = isset($_POST['active_status']) ? 1 : 0;

        if ($action === 'add') {
            $stmt = db()->prepare('INSERT INTO phone_directory (name, designation, team, mobile_number, email, location, active_status, created_at, updated_at, modified_by) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)');
            $stmt->execute([$name, $designation, $team, $mobile, $email, $location, $active, $user['id']]);
            $flash = 'Phone directory entry added.';
        } else {
            $stmt = db()->prepare('UPDATE phone_directory SET name = ?, designation = ?, team = ?, mobile_number = ?, email = ?, location = ?, active_status = ?, updated_at = NOW(), modified_by = ? WHERE id = ?');
            $stmt->execute([$name, $designation, $team, $mobile, $email, $location, $active, $user['id'], $id]);
            $flash = 'Phone directory entry updated.';
        }
    }

    if ($action === 'deactivate') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('UPDATE phone_directory SET active_status = 0, updated_at = NOW(), modified_by = ? WHERE id = ?');
        $stmt->execute([$user['id'], $id]);
        $flash = 'Phone directory entry deactivated.';
    }
}

$filterTeam = trim($_GET['team'] ?? '');
$filterName = trim($_GET['name'] ?? '');
$filterMobile = trim($_GET['mobile_number'] ?? '');
$filterLocation = trim($_GET['location'] ?? '');
$filterStatus = $_GET['active_status'] ?? '';

$teamStmt = db()->query('SELECT DISTINCT team FROM phone_directory WHERE team IS NOT NULL AND team <> "" ORDER BY team');
$teamRows = $teamStmt->fetchAll();
$teams = array_values(array_filter(array_map(static function (array $row): string {
    return trim((string) ($row['team'] ?? ''));
}, $teamRows), static function (string $team): bool {
    return $team !== '';
}));

$sql = 'SELECT id, name, designation, team, mobile_number, email, location, active_status FROM phone_directory WHERE 1 = 1';
$params = [];

if ($filterTeam !== '') {
    $sql .= ' AND team = ?';
    $params[] = $filterTeam;
}
if ($filterName !== '') {
    $sql .= ' AND name LIKE ?';
    $params[] = '%' . $filterName . '%';
}
if ($filterMobile !== '') {
    $sql .= ' AND mobile_number LIKE ?';
    $params[] = '%' . $filterMobile . '%';
}
if ($filterLocation !== '') {
    $sql .= ' AND location LIKE ?';
    $params[] = '%' . $filterLocation . '%';
}
if ($isAdmin && $filterStatus !== '') {
    $sql .= ' AND active_status = ?';
    $params[] = (int) $filterStatus;
} elseif (!$isAdmin) {
    $sql .= ' AND active_status = 1';
}

$sql .= ' ORDER BY name ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

render_header('Phone Directory');
render_page_header('Phone Directory', [
    'icon' => 'bi-person-rolodex',
    'subtitle' => 'Contact details for team members across aggregators, employers and districts.',
    'actions' => $isAdmin
        ? '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#phoneDirectoryModal" onclick="openAddModal()"><i class="bi bi-plus-lg me-1"></i>Add Entry</button>'
        : '',
]);
?>

<?php if ($flash): ?><div class="alert alert-success d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i><span><?= esc($flash) ?></span></div><?php endif; ?>

<form class="filter-bar row g-3 align-items-end">
    <div class="col-12 col-md-3">
        <label class="form-label mb-1">Team</label>
        <select class="form-select" name="team">
            <option value="">All Teams</option>
            <?php foreach ($teams as $team): ?>
                <option value="<?= esc($team) ?>" <?= $filterTeam === $team ? 'selected' : '' ?>><?= esc($team) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-2">
        <label class="form-label mb-1">Name</label>
        <input class="form-control" name="name" value="<?= esc($filterName) ?>" placeholder="Search name">
    </div>
    <div class="col-12 col-md-2">
        <label class="form-label mb-1">Mobile Number</label>
        <input class="form-control" name="mobile_number" value="<?= esc($filterMobile) ?>" placeholder="Search mobile">
    </div>
    <div class="col-12 col-md-2">
        <label class="form-label mb-1">Location</label>
        <input class="form-control" name="location" value="<?= esc($filterLocation) ?>" placeholder="Search location">
    </div>
    <?php if ($isAdmin): ?>
        <div class="col-12 col-md-2">
            <label class="form-label mb-1">Status</label>
            <select class="form-select" name="active_status">
                <option value="">All Status</option>
                <option value="1" <?= $filterStatus === '1' ? 'selected' : '' ?>>Active</option>
                <option value="0" <?= $filterStatus === '0' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
    <?php endif; ?>
    <div class="col-12 col-md-2 d-flex align-items-end gap-2">
        <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
        <a class="btn btn-light" href="/phone_directory.php">Reset</a>
    </div>
</form>

<div class="card table-card">
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead>
            <tr>
                <th>Name</th>
                <th>Designation</th>
                <th>Team</th>
                <th>Mobile Number</th>
                <th>Email</th>
                <th>Location</th>
                <?php if ($isAdmin): ?>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($entries)): ?>
                <tr>
                    <td colspan="<?= $isAdmin ? '8' : '6' ?>"><div class="empty-state"><i class="bi bi-person-x"></i>No directory entries found.</div></td>
                </tr>
            <?php endif; ?>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td class="fw-semibold"><?= esc($entry['name']) ?></td>
                    <td><?= esc($entry['designation']) ?></td>
                    <td><?= esc($entry['team']) ?></td>
                    <td><?= esc($entry['mobile_number']) ?></td>
                    <td><?= esc($entry['email']) ?></td>
                    <td><?= esc($entry['location']) ?></td>
                    <?php if ($isAdmin): ?>
                        <td>
                            <span class="status-chip <?= $entry['active_status'] ? 'status-yes' : 'status-neutral' ?>">
                                <?= $entry['active_status'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex gap-1 justify-content-end">
                            <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= json_encode($entry, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i> Edit</button>
                            <?php if ($entry['active_status']): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="deactivate">
                                    <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Deactivate this entry?')"><i class="bi bi-x-circle"></i> Deactivate</button>
                                </form>
                            <?php endif; ?>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="phoneDirectoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="phoneDirectoryForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="phoneDirectoryModalTitle">Add Directory Entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="entryId">
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" id="name" required></div>
                        <div class="col-md-6"><label class="form-label">Designation</label><input class="form-control" name="designation" id="designation" required></div>
                        <div class="col-md-6"><label class="form-label">Team</label><input class="form-control" name="team" id="team" required></div>
                        <div class="col-md-6"><label class="form-label">Mobile Number</label><input class="form-control" name="mobile_number" id="mobile" required></div>
                        <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" id="email"></div>
                        <div class="col-md-6"><label class="form-label">Location</label><input class="form-control" name="location" id="location" required></div>
                        <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="active_status" id="active" checked><label class="form-check-label" for="active">Active</label></div></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
                    <button class="btn btn-primary" type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function openAddModal() {
    document.getElementById('phoneDirectoryModalTitle').innerText = 'Add Directory Entry';
    document.getElementById('formAction').value = 'add';
    document.getElementById('entryId').value = '';
    document.getElementById('phoneDirectoryForm').reset();
    document.getElementById('active').checked = true;
}

function openEditModal(entry) {
    document.getElementById('phoneDirectoryModalTitle').innerText = 'Edit Directory Entry';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('entryId').value = entry.id;
    document.getElementById('name').value = entry.name;
    document.getElementById('designation').value = entry.designation;
    document.getElementById('team').value = entry.team;
    document.getElementById('mobile').value = entry.mobile_number;
    document.getElementById('email').value = entry.email;
    document.getElementById('location').value = entry.location;
    document.getElementById('active').checked = entry.active_status == 1;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('phoneDirectoryModal')).show();
}
</script>
<?php endif; ?>
<?php render_footer(); ?>

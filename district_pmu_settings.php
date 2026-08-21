<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/district_pmu_helpers.php';
require_admin();

$user = current_user();
if (!is_manage_admin($user)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only Administrator and DSM Admin can manage District PMU masters.</div>';
    render_footer();
    exit;
}

district_pmu_bootstrap();

$flashMessage = null;
$flashType    = 'success';

$rawTab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'types');
if (!in_array($rawTab, ['types', 'subtypes', 'authorities'], true)) { $rawTab = 'types'; }

// Small helper — inserts, updates or toggles a master row.
$mutateMaster = static function (string $table, string $action, array $post) use (&$flashMessage, &$flashType): void {
    $id     = (int) ($post['id'] ?? 0);
    $name   = trim((string) ($post['name'] ?? ''));
    $sort   = (int) ($post['sort_order'] ?? 0);
    $active = (int) ($post['active'] ?? 1);
    $extraCol = $post['_extra_col'] ?? null;
    $extraVal = isset($post['_extra_val']) ? (int) $post['_extra_val'] : null;

    if ($action === 'save' && $name === '') {
        $flashMessage = 'Name is required.';
        $flashType = 'danger';
        return;
    }

    try {
        if ($action === 'save' && $id > 0) {
            $sql = "UPDATE $table SET name = ?, sort_order = ?, active = ?" . ($extraCol ? ", $extraCol = ?" : '') . " WHERE id = ?";
            $params = [$name, $sort, $active];
            if ($extraCol) { $params[] = $extraVal; }
            $params[] = $id;
            db()->prepare($sql)->execute($params);
            $flashMessage = 'Updated.';
        } elseif ($action === 'save') {
            $cols = ['name', 'sort_order', 'active'];
            $vals = [$name, $sort, $active];
            if ($extraCol) { $cols[] = $extraCol; $vals[] = $extraVal; }
            $sql = "INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
            db()->prepare($sql)->execute($vals);
            $flashMessage = 'Added.';
        } elseif ($action === 'toggle' && $id > 0) {
            db()->prepare("UPDATE $table SET active = 1 - active WHERE id = ?")->execute([$id]);
            $flashMessage = 'Toggled.';
        }
    } catch (Throwable $e) {
        $flashMessage = 'Save failed: ' . $e->getMessage();
        $flashType = 'danger';
    }
};

if (is_post()) {
    $action = (string) ($_POST['action'] ?? '');
    $tabPost = (string) ($_POST['tab'] ?? '');
    if ($tabPost === 'types') {
        $mutateMaster('district_pmu_asset_types', $action, $_POST);
        $rawTab = 'types';
    } elseif ($tabPost === 'subtypes') {
        $post = $_POST;
        $post['_extra_col'] = 'asset_type_id';
        $post['_extra_val'] = (int) ($_POST['asset_type_id'] ?? 0);
        $mutateMaster('district_pmu_asset_subtypes', $action, $post);
        $rawTab = 'subtypes';
    } elseif ($tabPost === 'authorities') {
        $mutateMaster('district_pmu_owning_authorities', $action, $_POST);
        $rawTab = 'authorities';
    }
}

$assetTypes         = db()->query('SELECT * FROM district_pmu_asset_types ORDER BY sort_order ASC, name ASC')->fetchAll();
$assetSubtypes      = db()->query('SELECT s.*, t.name AS type_name FROM district_pmu_asset_subtypes s LEFT JOIN district_pmu_asset_types t ON t.id = s.asset_type_id ORDER BY t.sort_order ASC, s.sort_order ASC, s.name ASC')->fetchAll();
$owningAuthorities  = db()->query('SELECT * FROM district_pmu_owning_authorities ORDER BY sort_order ASC, name ASC')->fetchAll();

render_header('District PMU · Masters', ['main_container_class' => 'container-fluid']);
render_page_header('District PMU · Masters', [
    'icon' => 'bi-diagram-2',
    'subtitle' => 'Maintain the asset types, subtypes and owning authorities used across every District PMU\'s asset register.',
    'actions' => '<a class="btn btn-light" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $rawTab === 'types' ? 'active' : '' ?>" href="?tab=types">Asset Types</a></li>
    <li class="nav-item"><a class="nav-link <?= $rawTab === 'subtypes' ? 'active' : '' ?>" href="?tab=subtypes">Asset Subtypes</a></li>
    <li class="nav-item"><a class="nav-link <?= $rawTab === 'authorities' ? 'active' : '' ?>" href="?tab=authorities">Owning Authorities</a></li>
</ul>

<?php if ($rawTab === 'types'): ?>
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-plus-circle text-primary me-1"></i>Add / Edit asset type</div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="tab" value="types">
                <input type="hidden" name="id" value="0">
                <div class="col-md-4"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
                <div class="col-md-2"><label class="form-label">Sort order</label><input type="number" class="form-control" name="sort_order" value="10"></div>
                <div class="col-md-2"><label class="form-label">Active</label><select class="form-select" name="active"><option value="1">Yes</option><option value="0">No</option></select></div>
                <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add asset type</button></div>
            </form>
        </div>
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Sl No</th><th>Name</th><th>Sort</th><th>Active</th><th class="text-end">Actions</th></tr></thead><tbody>
        <?php $i = 1; foreach ($assetTypes as $t): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><form method="post" class="d-flex gap-2">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="tab" value="types">
                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <input type="hidden" name="active" value="<?= (int) $t['active'] ?>">
                    <input class="form-control form-control-sm" name="name" value="<?= esc((string) $t['name']) ?>">
                    <input type="number" class="form-control form-control-sm" style="max-width:90px;" name="sort_order" value="<?= (int) $t['sort_order'] ?>">
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-save"></i></button>
                </form></td>
                <td><?= (int) $t['sort_order'] ?></td>
                <td><?= ((int) $t['active']) === 1 ? '<span class="status-chip status-success">Active</span>' : '<span class="status-chip status-neutral">Inactive</span>' ?></td>
                <td class="text-end">
                    <form method="post" class="d-inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="tab" value="types"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-toggle-on"></i> Toggle</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div></div>
<?php elseif ($rawTab === 'subtypes'): ?>
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-plus-circle text-primary me-1"></i>Add asset subtype</div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="tab" value="subtypes">
                <input type="hidden" name="id" value="0">
                <div class="col-md-3"><label class="form-label">Asset type</label><select class="form-select" name="asset_type_id" required>
                    <option value="">Select…</option>
                    <?php foreach ($assetTypes as $t): ?><option value="<?= (int) $t['id'] ?>"><?= esc((string) $t['name']) ?></option><?php endforeach; ?>
                </select></div>
                <div class="col-md-4"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
                <div class="col-md-2"><label class="form-label">Sort order</label><input type="number" class="form-control" name="sort_order" value="10"></div>
                <div class="col-md-2"><label class="form-label">Active</label><select class="form-select" name="active"><option value="1">Yes</option><option value="0">No</option></select></div>
                <div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary"><i class="bi bi-plus-lg"></i></button></div>
            </form>
        </div>
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Sl No</th><th>Type</th><th>Name</th><th>Sort</th><th>Active</th><th class="text-end">Actions</th></tr></thead><tbody>
        <?php $i = 1; foreach ($assetSubtypes as $st): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= esc((string) ($st['type_name'] ?? '')) ?></td>
                <td><form method="post" class="d-flex gap-2">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="tab" value="subtypes">
                    <input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
                    <input type="hidden" name="active" value="<?= (int) $st['active'] ?>">
                    <input type="hidden" name="asset_type_id" value="<?= (int) $st['asset_type_id'] ?>">
                    <input class="form-control form-control-sm" name="name" value="<?= esc((string) $st['name']) ?>">
                    <input type="number" class="form-control form-control-sm" style="max-width:90px;" name="sort_order" value="<?= (int) $st['sort_order'] ?>">
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-save"></i></button>
                </form></td>
                <td><?= (int) $st['sort_order'] ?></td>
                <td><?= ((int) $st['active']) === 1 ? '<span class="status-chip status-success">Active</span>' : '<span class="status-chip status-neutral">Inactive</span>' ?></td>
                <td class="text-end">
                    <form method="post" class="d-inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="tab" value="subtypes"><input type="hidden" name="id" value="<?= (int) $st['id'] ?>"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-toggle-on"></i></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div></div>
<?php else: /* authorities */ ?>
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-plus-circle text-primary me-1"></i>Add owning authority</div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="tab" value="authorities">
                <input type="hidden" name="id" value="0">
                <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
                <div class="col-md-2"><label class="form-label">Sort order</label><input type="number" class="form-control" name="sort_order" value="10"></div>
                <div class="col-md-2"><label class="form-label">Active</label><select class="form-select" name="active"><option value="1">Yes</option><option value="0">No</option></select></div>
                <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add</button></div>
            </form>
        </div>
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Sl No</th><th>Name</th><th>Sort</th><th>Active</th><th class="text-end">Actions</th></tr></thead><tbody>
        <?php $i = 1; foreach ($owningAuthorities as $a): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><form method="post" class="d-flex gap-2">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="tab" value="authorities">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <input type="hidden" name="active" value="<?= (int) $a['active'] ?>">
                    <input class="form-control form-control-sm" name="name" value="<?= esc((string) $a['name']) ?>">
                    <input type="number" class="form-control form-control-sm" style="max-width:90px;" name="sort_order" value="<?= (int) $a['sort_order'] ?>">
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-save"></i></button>
                </form></td>
                <td><?= (int) $a['sort_order'] ?></td>
                <td><?= ((int) $a['active']) === 1 ? '<span class="status-chip status-success">Active</span>' : '<span class="status-chip status-neutral">Inactive</span>' ?></td>
                <td class="text-end">
                    <form method="post" class="d-inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="tab" value="authorities"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-toggle-on"></i></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div></div>
<?php endif; ?>

<?php render_footer(); ?>

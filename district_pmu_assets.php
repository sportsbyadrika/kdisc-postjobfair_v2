<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/district_pmu_helpers.php';
require_pmu_user();
district_pmu_bootstrap();

$user      = current_user();
$userId    = (int) $user['id'];
$districts = district_pmu_user_districts($user);
$district  = district_pmu_current_district($user);

$flashMessage = null;
$flashType    = 'success';

// Masters + owning authorities. Only ACTIVE rows shown in the add/edit
// dropdowns; the list view still resolves inactive rows for historical
// entries via a LEFT JOIN below.
$assetTypes = db()->query('SELECT id, name FROM district_pmu_asset_types WHERE active = 1 ORDER BY sort_order ASC, name ASC')->fetchAll();
$assetSubtypes = db()->query('SELECT id, asset_type_id, name FROM district_pmu_asset_subtypes WHERE active = 1 ORDER BY sort_order ASC, name ASC')->fetchAll();
$owningAuthorities = db()->query('SELECT id, name FROM district_pmu_owning_authorities WHERE active = 1 ORDER BY sort_order ASC, name ASC')->fetchAll();

// Build a subtype-by-type map for the JS cascade dropdown.
$subtypesByType = [];
foreach ($assetSubtypes as $st) {
    $subtypesByType[(int) $st['asset_type_id']][] = ['id' => (int) $st['id'], 'name' => (string) $st['name']];
}

// Delete handler — locked rows (submission_id IS NOT NULL) refuse.
if (is_post() && ($_POST['action'] ?? '') === 'delete') {
    $delId = (int) ($_POST['id'] ?? 0);
    if ($delId > 0 && $district !== '') {
        $stmt = db()->prepare('DELETE FROM district_pmu_assets WHERE id = ? AND district = ? AND submission_id IS NULL');
        $stmt->execute([$delId, $district]);
        if ($stmt->affectedRows() > 0) {
            $flashMessage = 'Asset row deleted.';
        } else {
            $flashMessage = 'Row already submitted — delete is locked. Only unsubmitted rows can be removed.';
            $flashType = 'warning';
        }
    }
}

// Bulk submission handler — locks the selected rows behind a fresh
// submission_number that appears on the printed report. Rows already
// tied to a submission are silently skipped so a stale re-post can't
// re-lock or reassign an approved row.
if (is_post() && ($_POST['action'] ?? '') === 'submit_selected') {
    if ($district === '') {
        $flashMessage = 'No district selected.';
        $flashType = 'danger';
    } else {
        $rawIds = $_POST['asset_ids'] ?? [];
        if (!is_array($rawIds)) { $rawIds = [$rawIds]; }
        $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn(int $v): bool => $v > 0)));
        if ($ids === []) {
            $flashMessage = 'Pick at least one un-submitted row before clicking Submit.';
            $flashType = 'warning';
        } else {
            // Sanity check: reject any id whose row isn't in this district
            // or that's already been submitted. Same query the UPDATE uses,
            // just as a pre-count so the flash number is honest.
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $chkStmt = db()->prepare("SELECT id FROM district_pmu_assets
                WHERE district = ? AND submission_id IS NULL AND id IN ($ph)");
            $chkStmt->execute([$district, ...$ids]);
            $eligible = array_map(static fn(array $r): int => (int) $r['id'], $chkStmt->fetchAll());

            if ($eligible === []) {
                $flashMessage = 'None of the selected rows were eligible — they may already be submitted.';
                $flashType = 'warning';
            } else {
                db()->query('START TRANSACTION');
                try {
                    $now = date('Y-m-d H:i:s');
                    // Insert the submission shell so we get the id.
                    $ins = db()->prepare('INSERT INTO district_pmu_asset_submissions
                        (submission_number, district, submitted_by, submitted_at, asset_count, notes)
                        VALUES (?, ?, ?, ?, ?, ?)');
                    $ins->execute([null, $district, $userId, $now, count($eligible), null]);
                    $subId  = (int) db()->lastInsertId();
                    $subNum = district_pmu_submission_number($subId, date('Ymd'));
                    db()->prepare('UPDATE district_pmu_asset_submissions SET submission_number = ? WHERE id = ?')
                        ->execute([$subNum, $subId]);

                    // Lock the asset rows to this submission.
                    $phe = implode(',', array_fill(0, count($eligible), '?'));
                    $upd = db()->prepare("UPDATE district_pmu_assets
                        SET submission_id = ?, submitted_at = ?
                        WHERE district = ? AND submission_id IS NULL AND id IN ($phe)");
                    $upd->execute([$subId, $now, $district, ...$eligible]);

                    db()->query('COMMIT');
                    $flashMessage = sprintf(
                        'Submission %s created with %d asset row(s). These rows are now locked from further edits.',
                        $subNum, count($eligible)
                    );
                } catch (Throwable $e) {
                    db()->query('ROLLBACK');
                    $flashMessage = 'Submission failed: ' . $e->getMessage();
                    $flashType = 'danger';
                }
            }
        }
    }
}

// Save (insert or update) handler
$editingRow = null;
$editingId = (int) ($_GET['edit'] ?? 0);
if (is_post() && ($_POST['action'] ?? '') === 'save') {
    $rowId       = (int) ($_POST['id'] ?? 0);
    $assetTypeId = (int) ($_POST['asset_type_id'] ?? 0);
    $subtypeId   = (int) ($_POST['subtype_id']    ?? 0);
    $description = trim((string) ($_POST['description'] ?? ''));
    $authId      = (int) ($_POST['owning_authority_id'] ?? 0);
    $quantity    = max(0, (int) ($_POST['quantity'] ?? 0));
    $remarks     = trim((string) ($_POST['remarks'] ?? ''));
    $concerned   = trim((string) ($_POST['concerned_person'] ?? ''));

    if ($assetTypeId <= 0 || $subtypeId <= 0) {
        $flashMessage = 'Please pick an Asset type and Sub type.';
        $flashType = 'danger';
        // preserve entered values so the form re-renders them
        $editingRow = [
            'id' => $rowId,
            'asset_type_id' => $assetTypeId,
            'subtype_id' => $subtypeId,
            'description' => $description,
            'owning_authority_id' => $authId,
            'quantity' => $quantity,
            'remarks' => $remarks,
            'concerned_person' => $concerned,
        ];
        $editingId = $rowId;
    } else {
        // Cross-check: subtype must belong to the chosen type — otherwise
        // JS was bypassed and we quietly reject.
        $chk = db()->prepare('SELECT id FROM district_pmu_asset_subtypes WHERE id = ? AND asset_type_id = ?');
        $chk->execute([$subtypeId, $assetTypeId]);
        if ($chk->fetch() === false) {
            $flashMessage = 'Selected subtype does not belong to the chosen asset type.';
            $flashType = 'danger';
        } else {
            if ($district === '') {
                $flashMessage = 'No district selected — assets are stored per district.';
                $flashType = 'danger';
            } elseif ($rowId > 0) {
                $u = db()->prepare('UPDATE district_pmu_assets
                    SET asset_type_id = ?, subtype_id = ?, description = ?, owning_authority_id = ?,
                        quantity = ?, remarks = ?, concerned_person = ?, updated_at = NOW()
                    WHERE id = ? AND district = ? AND submission_id IS NULL');
                $u->execute([
                    $assetTypeId, $subtypeId, $description === '' ? null : $description,
                    $authId > 0 ? $authId : null, $quantity,
                    $remarks === '' ? null : $remarks, $concerned === '' ? null : $concerned,
                    $rowId, $district,
                ]);
                if ($u->affectedRows() > 0) {
                    $flashMessage = 'Asset row updated.';
                } else {
                    $flashMessage = 'That row is already submitted and cannot be edited.';
                    $flashType = 'warning';
                }
            } else {
                $u = db()->prepare('INSERT INTO district_pmu_assets
                    (user_id, district, asset_type_id, subtype_id, description, owning_authority_id, quantity, remarks, concerned_person, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                $u->execute([
                    $userId, $district, $assetTypeId, $subtypeId, $description === '' ? null : $description,
                    $authId > 0 ? $authId : null, $quantity,
                    $remarks === '' ? null : $remarks, $concerned === '' ? null : $concerned,
                ]);
                $flashMessage = 'Asset added.';
            }
            $editingId = 0; $editingRow = null;
        }
    }
}

if ($editingId > 0 && $editingRow === null && $district !== '') {
    $stmt = db()->prepare('SELECT * FROM district_pmu_assets WHERE id = ? AND district = ?');
    $stmt->execute([$editingId, $district]);
    $r = $stmt->fetch();
    if ($r !== false) {
        if ($r['submission_id'] !== null) {
            $flashMessage = 'That row belongs to submission #' . (int) $r['submission_id'] . ' and is locked from edits.';
            $flashType = 'warning';
            $editingId = 0; // fall back to the empty Add form
        } else {
            $editingRow = $r;
        }
    }
}
// Same guard on the save path — if a saved row is locked between form
// open and submit, we reject silently rather than clobber the lock.
if (is_post() && ($_POST['action'] ?? '') === 'save' && (int) ($_POST['id'] ?? 0) > 0 && $editingRow === null) {
    // Above branch already errored + reset — nothing to do here.
}

// Filters + list
$typeFilter    = (int) ($_GET['type']    ?? 0);
$subtypeFilter = (int) ($_GET['subtype'] ?? 0);
$authFilter    = (int) ($_GET['auth']    ?? 0);
$conds  = ['a.district = ?'];
$params = [$district !== '' ? $district : '\0__none__'];
if ($typeFilter > 0)    { $conds[] = 'a.asset_type_id = ?';       $params[] = $typeFilter; }
if ($subtypeFilter > 0) { $conds[] = 'a.subtype_id = ?';          $params[] = $subtypeFilter; }
if ($authFilter > 0)    { $conds[] = 'a.owning_authority_id = ?'; $params[] = $authFilter; }
$whereSql = 'WHERE ' . implode(' AND ', $conds);

// CSV download
if (($_GET['download'] ?? '') === 'csv') {
    $stmt = db()->prepare("SELECT a.id, t.name AS type_name, s.name AS subtype_name, a.description,
            auth.name AS authority_name, a.quantity, a.remarks, a.concerned_person, a.updated_at
        FROM district_pmu_assets a
        LEFT JOIN district_pmu_asset_types t ON t.id = a.asset_type_id
        LEFT JOIN district_pmu_asset_subtypes s ON s.id = a.subtype_id
        LEFT JOIN district_pmu_owning_authorities auth ON auth.id = a.owning_authority_id
        $whereSql
        ORDER BY t.sort_order, s.sort_order, a.id");
    $stmt->execute($params);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="district_pmu_assets_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Sl No', 'Type', 'Sub type', 'Description', 'Owning Authority', 'Quantity', 'Remarks', 'Concerned Person', 'Last Updated']);
    $i = 1;
    while ($r = $stmt->fetch()) {
        fputcsv($out, [
            $i++,
            (string) ($r['type_name'] ?? ''),
            (string) ($r['subtype_name'] ?? ''),
            (string) ($r['description'] ?? ''),
            (string) ($r['authority_name'] ?? ''),
            (int) ($r['quantity'] ?? 0),
            (string) ($r['remarks'] ?? ''),
            (string) ($r['concerned_person'] ?? ''),
            (string) ($r['updated_at'] ?? ''),
        ]);
    }
    fclose($out); exit;
}

$listSql = "SELECT a.*, t.name AS type_name, s.name AS subtype_name, auth.name AS authority_name,
        sub.submission_number AS submission_number
    FROM district_pmu_assets a
    LEFT JOIN district_pmu_asset_types t ON t.id = a.asset_type_id
    LEFT JOIN district_pmu_asset_subtypes s ON s.id = a.subtype_id
    LEFT JOIN district_pmu_owning_authorities auth ON auth.id = a.owning_authority_id
    LEFT JOIN district_pmu_asset_submissions sub ON sub.id = a.submission_id
    $whereSql
    ORDER BY t.sort_order ASC, s.sort_order ASC, a.id DESC";
$listStmt = db()->prepare($listSql);
$listStmt->execute($params);
$assetRows = $listStmt->fetchAll();
$unsubmittedCount = 0;
foreach ($assetRows as $ar) { if ($ar['submission_id'] === null) $unsubmittedCount++; }

// Filter dropdown options — subtype list on the filter shows every
// active subtype, filtered client-side by chosen type.
$csvUrl = '/district_pmu_assets.php?' . http_build_query(array_filter([
    'district' => $district,
    'type' => $typeFilter, 'subtype' => $subtypeFilter, 'auth' => $authFilter, 'download' => 'csv',
], static fn($v): bool => $v !== 0 && $v !== '' && $v !== null));

render_header('District PMU · Asset Register', ['main_container_class' => 'container-fluid']);
render_page_header('District PMU · Asset Register', [
    'icon' => 'bi-box-seam',
    'subtitle' => 'IT and Non-IT assets held by the ' . ($district !== '' ? esc($district) : 'selected') . ' district PMU office.',
    'actions' => district_pmu_render_district_switcher($user, $district, [
            'type' => $typeFilter, 'subtype' => $subtypeFilter, 'auth' => $authFilter,
        ])
        . '<a class="btn btn-light ms-2 me-1" href="' . esc($csvUrl) . '"><i class="bi bi-download me-1"></i>Download CSV</a>'
        . '<a class="btn btn-light" href="/district_pmu_dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($district === ''): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        Your user account has no assigned district. Ask an Administrator to set one before adding assets — assets are stored per district.
    </div>
<?php endif; ?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-plus-circle text-primary me-1"></i><?= $editingRow ? 'Edit asset' : 'Add asset' ?></span>
        <?php if ($editingRow): ?>
            <a class="btn btn-sm btn-light" href="/district_pmu_assets.php">Cancel edit</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="post" class="row g-3" id="assetForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editingRow['id'] ?? 0) ?>">
            <input type="hidden" name="district" value="<?= esc($district) ?>">
            <div class="col-md-3">
                <label class="form-label">Asset type <span class="text-danger">*</span></label>
                <select class="form-select" name="asset_type_id" id="assetType" required>
                    <option value="">Select…</option>
                    <?php foreach ($assetTypes as $t): ?>
                        <option value="<?= (int) $t['id'] ?>" <?= (int) ($editingRow['asset_type_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= esc((string) $t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Sub type <span class="text-danger">*</span></label>
                <select class="form-select" name="subtype_id" id="assetSubtype" required>
                    <option value="">Pick a type first…</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Owning Authority</label>
                <select class="form-select" name="owning_authority_id">
                    <option value="0">— none —</option>
                    <?php foreach ($owningAuthorities as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= (int) ($editingRow['owning_authority_id'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>><?= esc((string) $a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Number of items</label>
                <input type="number" min="0" class="form-control" name="quantity" value="<?= (int) ($editingRow['quantity'] ?? 0) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Description</label>
                <input type="text" class="form-control" name="description" value="<?= esc((string) ($editingRow['description'] ?? '')) ?>" placeholder="Make / model / serial / any identifier">
            </div>
            <div class="col-md-6">
                <label class="form-label">Concerned person handled</label>
                <input type="text" class="form-control" name="concerned_person" value="<?= esc((string) ($editingRow['concerned_person'] ?? '')) ?>" placeholder="Name of the person the asset is with">
            </div>
            <div class="col-12">
                <label class="form-label">Remarks</label>
                <textarea class="form-control" name="remarks" rows="2"><?= esc((string) ($editingRow['remarks'] ?? '')) ?></textarea>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2">
                <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i><?= $editingRow ? 'Save changes' : 'Add asset' ?></button>
            </div>
        </form>
        <script>
        (function () {
            const bySubtypeByType = <?= json_encode($subtypesByType, JSON_UNESCAPED_UNICODE) ?>;
            const typeSel    = document.getElementById('assetType');
            const subtypeSel = document.getElementById('assetSubtype');
            const preselectSubtype = <?= (int) ($editingRow['subtype_id'] ?? 0) ?>;
            const refresh = () => {
                const t = parseInt(typeSel.value, 10) || 0;
                const opts = bySubtypeByType[t] || [];
                subtypeSel.innerHTML = opts.length === 0
                    ? '<option value="">— no active subtypes —</option>'
                    : '<option value="">Select…</option>' + opts.map((o) => `<option value="${o.id}">${o.name.replace(/</g,'&lt;')}</option>`).join('');
                if (preselectSubtype) subtypeSel.value = String(preselectSubtype);
            };
            typeSel.addEventListener('change', refresh);
            refresh();
        })();
        </script>
    </div>
</div>

<form method="get" class="card mb-3">
    <input type="hidden" name="district" value="<?= esc($district) ?>">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Filter · Type</label>
                <select class="form-select" name="type">
                    <option value="0">All</option>
                    <?php foreach ($assetTypes as $t): ?>
                        <option value="<?= (int) $t['id'] ?>" <?= $typeFilter === (int) $t['id'] ? 'selected' : '' ?>><?= esc((string) $t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Filter · Sub type</label>
                <select class="form-select" name="subtype">
                    <option value="0">All</option>
                    <?php foreach ($assetSubtypes as $st): ?>
                        <option value="<?= (int) $st['id'] ?>" <?= $subtypeFilter === (int) $st['id'] ? 'selected' : '' ?>><?= esc((string) $st['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Filter · Owning Authority</label>
                <select class="form-select" name="auth">
                    <option value="0">All</option>
                    <?php foreach ($owningAuthorities as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= $authFilter === (int) $a['id'] ? 'selected' : '' ?>><?= esc((string) $a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
                <a class="btn btn-light" href="/district_pmu_assets.php">Reset</a>
            </div>
        </div>
    </div>
</form>

<form method="post" id="submitAssetsForm" onsubmit="return confirm('Lock the selected rows behind a new submission number? They cannot be edited or deleted afterwards.');">
    <input type="hidden" name="action" value="submit_selected">
    <input type="hidden" name="district" value="<?= esc($district) ?>">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-box-seam text-primary me-1"></i>Assets</span>
            <div class="d-flex align-items-center gap-2">
                <span class="status-chip status-info"><?= number_format(count($assetRows)) ?> row<?= count($assetRows) === 1 ? '' : 's' ?></span>
                <?php if ($unsubmittedCount > 0): ?>
                    <button type="submit" class="btn btn-sm btn-warning" id="submitSelectedBtn" disabled>
                        <i class="bi bi-check2-square me-1"></i>Submit selected <span class="badge bg-dark ms-1" id="submitSelectedCount">0</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:34px;"><input type="checkbox" id="assetsSelectAll" title="Select all un-submitted rows"></th>
                        <th>Sl No</th>
                        <th>Type</th>
                        <th>Sub type</th>
                        <th>Description</th>
                        <th>Owning Authority</th>
                        <th class="text-end">Quantity</th>
                        <th>Concerned Person</th>
                        <th>Remarks</th>
                        <th>Submission</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($assetRows === []): ?>
                        <tr><td colspan="11"><div class="empty-state"><i class="bi bi-inbox"></i>No assets recorded yet. Use the form above to add one.</div></td></tr>
                    <?php endif; ?>
                    <?php $i = 1; foreach ($assetRows as $r): ?>
                        <?php $locked = $r['submission_id'] !== null; ?>
                        <tr class="<?= $locked ? 'table-light' : '' ?>">
                            <td>
                                <?php if (!$locked): ?>
                                    <input type="checkbox" class="js-asset-row" name="asset_ids[]" value="<?= (int) $r['id'] ?>">
                                <?php else: ?>
                                    <i class="bi bi-lock-fill text-muted" title="Submitted — locked"></i>
                                <?php endif; ?>
                            </td>
                            <td><?= $i++ ?></td>
                            <td><span class="status-chip status-<?= str_contains(strtolower((string) $r['type_name']), 'non') ? 'neutral' : 'info' ?>"><?= esc((string) ($r['type_name'] ?? '')) ?></span></td>
                            <td class="fw-semibold"><?= esc((string) ($r['subtype_name'] ?? '')) ?></td>
                            <td class="small text-muted"><?= nl2br(esc((string) ($r['description'] ?? ''))) ?></td>
                            <td class="small"><?= esc((string) ($r['authority_name'] ?? '—')) ?></td>
                            <td class="text-end fw-bold"><?= number_format((int) ($r['quantity'] ?? 0)) ?></td>
                            <td><?= esc((string) ($r['concerned_person'] ?? '')) ?></td>
                            <td class="small text-muted"><?= nl2br(esc((string) ($r['remarks'] ?? ''))) ?></td>
                            <td class="small">
                                <?php if ($locked): ?>
                                    <span class="badge text-bg-success" title="Submitted at <?= esc((string) ($r['submitted_at'] ?? '')) ?>"><?= esc((string) ($r['submission_number'] ?? '—')) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if (!$locked): ?>
                                    <div class="d-inline-flex gap-1">
                                        <a class="btn btn-sm btn-outline-primary" href="/district_pmu_assets.php?district=<?= urlencode($district) ?>&amp;edit=<?= (int) $r['id'] ?>#assetForm"><i class="bi bi-pencil-square"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger js-delete-asset" data-id="<?= (int) $r['id'] ?>" title="Delete this asset row"><i class="bi bi-trash"></i></button>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">Locked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>
<script>
(function () {
    // Select-all + submit-button enable/disable.
    const selAll  = document.getElementById('assetsSelectAll');
    const btn     = document.getElementById('submitSelectedBtn');
    const badge   = document.getElementById('submitSelectedCount');
    const rows    = () => Array.from(document.querySelectorAll('.js-asset-row'));
    const refresh = () => {
        const n = rows().filter((c) => c.checked).length;
        if (badge) badge.textContent = String(n);
        if (btn)   btn.disabled = (n === 0);
    };
    selAll?.addEventListener('change', () => {
        rows().forEach((c) => { c.checked = selAll.checked; });
        refresh();
    });
    rows().forEach((c) => c.addEventListener('change', refresh));
    refresh();

    // Delete via delegation — building the form on the fly keeps the
    // per-row markup free of nested <form>s (which HTML forbids).
    const currentDistrict = <?= json_encode($district, JSON_UNESCAPED_UNICODE) ?>;
    document.addEventListener('click', (ev) => {
        const btnDel = ev.target.closest('.js-delete-asset');
        if (!btnDel) return;
        ev.preventDefault();
        if (!confirm('Delete this asset row?')) return;
        const f = document.createElement('form');
        f.method = 'post';
        const mk = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; return i; };
        f.appendChild(mk('action', 'delete'));
        f.appendChild(mk('id', btnDel.getAttribute('data-id')));
        f.appendChild(mk('district', currentDistrict));
        document.body.appendChild(f);
        f.submit();
    });
})();
</script>

<?php render_footer(); ?>

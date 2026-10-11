<?php
/**
 * LOI & Jobs · Employers list.
 *
 * Filterable list of employers (SBU, District, Sector, LOI received).
 * Any LOI user can view the list; LOI admin can add / edit / toggle
 * active. The row's Open button jumps to loi_employer_view.php where
 * Jobs + Interviews + Candidate roster + Audit live.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/loi_jobs_helpers.php';
require_once __DIR__ . '/includes/india_locations_helpers.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
loi_jobs_bootstrap();
india_locations_bootstrap();
$stateRows = india_states_list();

if (!user_can_access_module($viewerId, 'loi_jobs') && !is_admin($viewer)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">You do not have access to the LOI & Jobs module.</div>';
    render_footer(); exit;
}

$canAdmin = is_manage_admin($viewer) || user_can_admin_module($viewerId, 'loi_jobs');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['loi_employers_flash']) && is_array($_SESSION['loi_employers_flash'])) {
    $flashMessage = (string) ($_SESSION['loi_employers_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['loi_employers_flash']['type'] ?? 'success');
    unset($_SESSION['loi_employers_flash']);
}
$flashAndBack = static function (string $msg, string $type = 'success', int $id = 0): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['loi_employers_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: ' . ($id > 0 ? '/loi_employer_view.php?id=' . $id : '/loi_employers.php'));
    exit;
};

if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();
    if (!$canAdmin) $flashAndBack('Only an LOI admin can add or edit employers.', 'danger');
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') $flashAndBack('Employer name is required.', 'danger');
    $sbuId    = (int) ($_POST['sbu_id'] ?? 0);
    $addr     = trim((string) ($_POST['address_of_communication'] ?? ''));
    $state    = trim((string) ($_POST['state'] ?? ''));
    $district = trim((string) ($_POST['district'] ?? ''));
    $cnum     = trim((string) ($_POST['contact_number'] ?? ''));
    $cmail    = trim((string) ($_POST['contact_email'] ?? ''));
    $hrName   = trim((string) ($_POST['hr_manager_name'] ?? ''));
    $hrMob    = trim((string) ($_POST['hr_manager_mobile'] ?? ''));
    $hrMail   = trim((string) ($_POST['hr_manager_email'] ?? ''));
    $sector   = trim((string) ($_POST['sector'] ?? ''));
    $loiRecv  = isset($_POST['loi_received']) ? 1 : 0;
    $loiDate  = trim((string) ($_POST['loi_received_date'] ?? ''));
    $regDwms  = isset($_POST['registered_in_dwms']) ? 1 : 0;
    $jobsDwms = isset($_POST['jobs_added_in_dwms']) ? 1 : 0;
    $notes    = trim((string) ($_POST['notes'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $loiDate  = ($loiDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $loiDate)) ? $loiDate : null;

    try {
        if ($id > 0) {
            db()->prepare('UPDATE loi_employer SET
                    sbu_id = ?, name = ?, address_of_communication = ?, state = ?, district = ?,
                    contact_number = ?, contact_email = ?,
                    hr_manager_name = ?, hr_manager_mobile = ?, hr_manager_email = ?,
                    sector = ?, loi_received = ?, loi_received_date = ?,
                    registered_in_dwms = ?, jobs_added_in_dwms = ?,
                    notes = ?, is_active = ?, updated_at = NOW(), updated_by = ?
                WHERE id = ?')
                ->execute([
                    $sbuId > 0 ? $sbuId : null, $name,
                    $addr === '' ? null : $addr,
                    $state === '' ? null : $state, $district === '' ? null : $district,
                    $cnum === '' ? null : $cnum, $cmail === '' ? null : $cmail,
                    $hrName === '' ? null : $hrName, $hrMob === '' ? null : $hrMob, $hrMail === '' ? null : $hrMail,
                    $sector === '' ? null : $sector, $loiRecv, $loiDate,
                    $regDwms, $jobsDwms,
                    $notes === '' ? null : $notes, $isActive, $viewerId, $id,
                ]);
            $flashAndBack('Employer updated.', 'success', $id);
        } else {
            db()->prepare('INSERT INTO loi_employer
                (sbu_id, name, address_of_communication, state, district, contact_number, contact_email,
                 hr_manager_name, hr_manager_mobile, hr_manager_email,
                 sector, loi_received, loi_received_date,
                 registered_in_dwms, jobs_added_in_dwms, notes,
                 is_active, created_at, updated_at, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), ?, ?)')
                ->execute([
                    $sbuId > 0 ? $sbuId : null, $name,
                    $addr === '' ? null : $addr,
                    $state === '' ? null : $state, $district === '' ? null : $district,
                    $cnum === '' ? null : $cnum, $cmail === '' ? null : $cmail,
                    $hrName === '' ? null : $hrName, $hrMob === '' ? null : $hrMob, $hrMail === '' ? null : $hrMail,
                    $sector === '' ? null : $sector, $loiRecv, $loiDate,
                    $regDwms, $jobsDwms, $notes === '' ? null : $notes,
                    $viewerId, $viewerId,
                ]);
            $newId = db()->lastInsertId();
            $flashAndBack('Employer added. You can now add Jobs, Interviews and Candidates on the detail page.', 'success', $newId);
        }
    } catch (Throwable $e) {
        $flashAndBack('Save failed: ' . $e->getMessage(), 'danger');
    }
}
if (is_post() && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_check_or_die();
    if (!$canAdmin) $flashAndBack('Only an LOI admin can toggle active state.', 'danger');
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) $flashAndBack('Missing id.', 'danger');
    db()->prepare('UPDATE loi_employer SET is_active = 1 - is_active, updated_at = NOW(), updated_by = ? WHERE id = ?')
        ->execute([$viewerId, $id]);
    $flashAndBack('Toggled.');
}

$sbuRows = loi_sbu_list(false);
$sbuById = []; foreach ($sbuRows as $s) $sbuById[(int) $s['id']] = (string) $s['name'];

$fSbu    = (int)    ($_GET['sbu']    ?? 0);
$fQuery  = trim((string) ($_GET['q']  ?? ''));
$fSector = trim((string) ($_GET['sector'] ?? ''));
$fLoi    = (string) ($_GET['loi']    ?? '');
$fStatus = (string) ($_GET['status'] ?? 'active');

$sql = 'SELECT e.*, s.name AS sbu_name,
        (SELECT COUNT(*) FROM loi_job j WHERE j.employer_id = e.id AND j.is_active = 1) AS job_count,
        (SELECT COUNT(*) FROM loi_interview i WHERE i.employer_id = e.id AND i.is_active = 1) AS interview_count
    FROM loi_employer e
    LEFT JOIN loi_sbu s ON s.id = e.sbu_id
    WHERE 1=1';
$params = [];
if ($fSbu > 0)    { $sql .= ' AND e.sbu_id = ?'; $params[] = $fSbu; }
if ($fSector !== ''){$sql .= ' AND e.sector LIKE ?'; $params[] = '%' . $fSector . '%'; }
if ($fLoi === '1'){ $sql .= ' AND e.loi_received = 1'; }
elseif ($fLoi === '0'){ $sql .= ' AND e.loi_received = 0'; }
if ($fStatus === 'active')   { $sql .= ' AND e.is_active = 1'; }
elseif ($fStatus === 'inactive') { $sql .= ' AND e.is_active = 0'; }
if ($fQuery !== ''){ $sql .= ' AND (e.name LIKE ? OR e.district LIKE ? OR e.contact_email LIKE ? OR e.contact_number LIKE ? OR e.hr_manager_name LIKE ?)';
    $like = '%' . $fQuery . '%';
    array_push($params, $like, $like, $like, $like, $like); }
$sql .= ' ORDER BY e.is_active DESC, e.name ASC';

$rows = [];
try {
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (Throwable $e) { /* empty */ }

render_header('LOI & Jobs · Employers', ['main_container_class' => 'container-xl']);
render_page_header('LOI & Jobs · Employers', [
    'icon'     => 'bi-building',
    'subtitle' => 'Employers tracked by our team. Click an employer to manage jobs, interviews and candidate roster.',
    'actions'  => ($canAdmin ? '<button type="button" class="btn btn-primary js-new-emp" data-bs-toggle="modal" data-bs-target="#empModal"><i class="bi bi-plus-lg me-1"></i>New employer</button>' : '')
        . '<a class="btn btn-light ms-2" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<form method="get" class="filter-bar">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Search</label>
            <input class="form-control" name="q" value="<?= esc($fQuery) ?>" placeholder="Name, district, email, mobile, HR">
        </div>
        <div class="col-md-2">
            <label class="form-label">SBU</label>
            <select class="form-select" name="sbu">
                <option value="0">All</option>
                <?php foreach ($sbuRows as $s): if (!$s['is_active'] && $fSbu !== (int) $s['id']) continue; ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $fSbu === (int) $s['id'] ? 'selected' : '' ?>><?= esc((string) $s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Sector</label>
            <input class="form-control" name="sector" value="<?= esc($fSector) ?>" placeholder="Sector">
        </div>
        <div class="col-md-2">
            <label class="form-label">LOI</label>
            <select class="form-select" name="loi">
                <option value="" <?= $fLoi === '' ? 'selected' : '' ?>>All</option>
                <option value="1" <?= $fLoi === '1' ? 'selected' : '' ?>>Received</option>
                <option value="0" <?= $fLoi === '0' ? 'selected' : '' ?>>Not received</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $v): ?>
                    <option value="<?= esc($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= esc($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-funnel"></i></button>
            <a class="btn btn-light" href="/loi_employers.php">Reset</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-building text-primary me-1"></i>Employers</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> row<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl</th>
                    <th>Employer</th>
                    <th>SBU</th>
                    <th>District / State</th>
                    <th>Sector</th>
                    <th>LOI</th>
                    <th>DWMS</th>
                    <th class="text-center">Jobs</th>
                    <th class="text-center">Interviews</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="11"><div class="empty-state"><i class="bi bi-inbox"></i>No employers match the current filters.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($rows as $r):
                    $active = ((int) $r['is_active']) === 1;
                    $payload = htmlspecialchars(json_encode([
                        'id'                 => (int) $r['id'],
                        'sbu_id'             => (int) ($r['sbu_id'] ?? 0),
                        'name'               => (string) $r['name'],
                        'address_of_communication' => (string) ($r['address_of_communication'] ?? ''),
                        'state'              => (string) ($r['state'] ?? ''),
                        'district'           => (string) ($r['district'] ?? ''),
                        'contact_number'     => (string) ($r['contact_number'] ?? ''),
                        'contact_email'      => (string) ($r['contact_email'] ?? ''),
                        'hr_manager_name'    => (string) ($r['hr_manager_name'] ?? ''),
                        'hr_manager_mobile'  => (string) ($r['hr_manager_mobile'] ?? ''),
                        'hr_manager_email'   => (string) ($r['hr_manager_email'] ?? ''),
                        'sector'             => (string) ($r['sector'] ?? ''),
                        'loi_received'       => (int) ($r['loi_received'] ?? 0),
                        'loi_received_date'  => (string) ($r['loi_received_date'] ?? ''),
                        'registered_in_dwms' => (int) ($r['registered_in_dwms'] ?? 0),
                        'jobs_added_in_dwms' => (int) ($r['jobs_added_in_dwms'] ?? 0),
                        'notes'              => (string) ($r['notes'] ?? ''),
                        'is_active'          => (int) ($r['is_active'] ?? 0),
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                ?>
                    <tr class="<?= $active ? '' : 'text-muted' ?>">
                        <td><?= $i++ ?></td>
                        <td class="fw-semibold"><a href="/loi_employer_view.php?id=<?= (int) $r['id'] ?>" class="text-decoration-none"><?= esc((string) $r['name']) ?></a>
                            <?php if (!empty($r['hr_manager_name'])): ?><div class="small text-muted"><?= esc((string) $r['hr_manager_name']) ?></div><?php endif; ?>
                        </td>
                        <td class="small"><?= esc((string) ($r['sbu_name'] ?? '')) ?></td>
                        <td class="small"><?= esc((string) ($r['district'] ?? '')) ?><?php if (!empty($r['state'])): ?><div class="text-muted"><?= esc((string) $r['state']) ?></div><?php endif; ?></td>
                        <td class="small"><?= esc((string) ($r['sector'] ?? '')) ?></td>
                        <td class="small">
                            <?php if ((int) $r['loi_received'] === 1): ?><span class="badge text-bg-success">Received</span>
                                <?php if (!empty($r['loi_received_date'])): ?><div class="text-muted"><?= esc(date('d/m/Y', strtotime((string) $r['loi_received_date']))) ?></div><?php endif; ?>
                            <?php else: ?><span class="badge text-bg-secondary">—</span><?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ((int) $r['registered_in_dwms'] === 1): ?><i class="bi bi-check2-circle text-success me-1" title="Registered in DWMS"></i>Emp<?php endif; ?>
                            <?php if ((int) $r['jobs_added_in_dwms'] === 1): ?><div><i class="bi bi-check2-circle text-success me-1" title="Jobs added in DWMS"></i>Jobs</div><?php endif; ?>
                            <?php if ((int) $r['registered_in_dwms'] === 0 && (int) $r['jobs_added_in_dwms'] === 0): ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-center"><?= (int) $r['job_count'] ?></td>
                        <td class="text-center"><?= (int) $r['interview_count'] ?></td>
                        <td>
                            <?php if ($active): ?><span class="badge text-bg-success">Active</span>
                            <?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a class="btn btn-sm btn-outline-primary" href="/loi_employer_view.php?id=<?= (int) $r['id'] ?>" title="Open"><i class="bi bi-box-arrow-up-right"></i></a>
                                <?php if ($canAdmin): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary js-edit-emp"
                                            data-payload="<?= $payload ?>"
                                            data-bs-toggle="modal" data-bs-target="#empModal">
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
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canAdmin): ?>
<div class="modal fade" id="empModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="eModalId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="eModalTitle"><i class="bi bi-plus-lg me-1"></i>New employer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Employer name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="eModalName" name="name" required maxlength="200">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">SBU</label>
                            <select class="form-select" id="eModalSbu" name="sbu_id">
                                <option value="0">— select —</option>
                                <?php foreach ($sbuRows as $s): if (!$s['is_active']) continue; ?>
                                    <option value="<?= (int) $s['id'] ?>"><?= esc((string) $s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sector</label>
                            <input type="text" class="form-control" id="eModalSector" name="sector" maxlength="120">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Address of communication</label>
                            <textarea class="form-control" id="eModalAddr" name="address_of_communication" rows="2" placeholder="Full postal address used for official correspondence"></textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">State / UT</label>
                            <select class="form-select" id="eModalState" name="state">
                                <option value="">— select state / UT —</option>
                                <?php
                                $lastKind = null;
                                foreach ($stateRows as $s):
                                    if ($lastKind !== null && $lastKind !== $s['kind']) {
                                        echo '<option disabled>──────────</option>';
                                    }
                                    $lastKind = $s['kind'];
                                ?>
                                    <option value="<?= esc((string) $s['name']) ?>"><?= esc((string) $s['name']) ?><?= $s['kind'] === 'ut' ? ' (UT)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">District</label>
                            <select class="form-select" id="eModalDist" name="district">
                                <option value="">— select a state first —</option>
                            </select>
                            <div class="small text-muted" id="eModalDistHint" style="display:none;"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contact number</label>
                            <input type="text" class="form-control" id="eModalCnum" name="contact_number" maxlength="40">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Contact email</label>
                            <input type="email" class="form-control" id="eModalCmail" name="contact_email" maxlength="200">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">HR manager / SPOC</label>
                            <input type="text" class="form-control" id="eModalHrName" name="hr_manager_name" maxlength="200">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">HR mobile</label>
                            <input type="text" class="form-control" id="eModalHrMob" name="hr_manager_mobile" maxlength="40">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">HR email</label>
                            <input type="email" class="form-control" id="eModalHrMail" name="hr_manager_email" maxlength="200">
                        </div>

                        <div class="col-md-3">
                            <div class="form-check mt-md-4">
                                <input class="form-check-input" type="checkbox" id="eModalLoi" name="loi_received" value="1">
                                <label class="form-check-label" for="eModalLoi">LOI received</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">LOI received date</label>
                            <input type="date" class="form-control" id="eModalLoiDate" name="loi_received_date">
                        </div>
                        <div class="col-md-3">
                            <div class="form-check mt-md-4">
                                <input class="form-check-input" type="checkbox" id="eModalRegDwms" name="registered_in_dwms" value="1">
                                <label class="form-check-label" for="eModalRegDwms">Registered in DWMS</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check mt-md-4">
                                <input class="form-check-input" type="checkbox" id="eModalJobsDwms" name="jobs_added_in_dwms" value="1">
                                <label class="form-check-label" for="eModalJobsDwms">Jobs added in DWMS</label>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" id="eModalNotes" name="notes" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="eModalActive" name="is_active" value="1" checked>
                                <label class="form-check-label" for="eModalActive">Active</label>
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
    const modalEl = document.getElementById('empModal');
    const setV = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
    const setC = (id, v) => { const el = document.getElementById(id); if (el) el.checked = !!v; };
    const stateSel = document.getElementById('eModalState');
    const distSel  = document.getElementById('eModalDist');
    const hint     = document.getElementById('eModalDistHint');

    // Load districts for a state via AJAX, then (optionally) select
    // the given district value. Returns a promise so the edit flow
    // can wait before pre-selecting.
    const loadDistricts = (stateName, preselectDistrict) => {
        if (!distSel) return Promise.resolve();
        distSel.innerHTML = '<option value="">— loading… —</option>';
        hint.style.display = 'none'; hint.textContent = '';
        if (!stateName) {
            distSel.innerHTML = '<option value="">— select a state first —</option>';
            return Promise.resolve();
        }
        return fetch('/loi_locations_ajax.php?action=districts&state=' + encodeURIComponent(stateName), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(j => {
                const list = (j && j.ok && Array.isArray(j.districts)) ? j.districts : [];
                let html = '<option value="">— select district —</option>';
                let matched = false;
                list.forEach(d => {
                    const sel = (preselectDistrict && d === preselectDistrict);
                    if (sel) matched = true;
                    html += '<option value="' + d.replace(/"/g, '&quot;') + '"' + (sel ? ' selected' : '') + '>' + d.replace(/</g, '&lt;') + '</option>';
                });
                // If the stored district doesn't match any seeded entry
                // (older row, custom spelling), keep it as a one-off
                // option so the edit doesn't silently drop the value.
                if (preselectDistrict && !matched) {
                    html += '<option value="' + preselectDistrict.replace(/"/g, '&quot;') + '" selected>' + preselectDistrict.replace(/</g, '&lt;') + ' (not in master)</option>';
                    hint.style.display = ''; hint.textContent = 'This district is not in the India master list — admins can add it in a future SBU-style master page.';
                }
                distSel.innerHTML = html;
            })
            .catch(e => {
                distSel.innerHTML = '<option value="">— could not load —</option>';
            });
    };
    stateSel?.addEventListener('change', () => loadDistricts(stateSel.value, null));

    modalEl?.addEventListener('show.bs.modal', (ev) => {
        const t = ev.relatedTarget; if (!t) return;
        const title = document.getElementById('eModalTitle');
        if (t.classList.contains('js-new-emp')) {
            title.innerHTML = '<i class="bi bi-plus-lg me-1"></i>New employer';
            ['eModalId','eModalName','eModalSbu','eModalSector','eModalAddr','eModalState',
             'eModalCnum','eModalCmail','eModalHrName','eModalHrMob','eModalHrMail',
             'eModalLoiDate','eModalNotes'].forEach(k => setV(k, k === 'eModalId' ? '0' : (k === 'eModalSbu' ? '0' : '')));
            if (distSel) distSel.innerHTML = '<option value="">— select a state first —</option>';
            setC('eModalLoi', false); setC('eModalRegDwms', false); setC('eModalJobsDwms', false); setC('eModalActive', true);
        } else if (t.classList.contains('js-edit-emp')) {
            let d = {}; try { d = JSON.parse(t.getAttribute('data-payload') || '{}'); } catch (e) {}
            title.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit employer';
            setV('eModalId', String(d.id || 0));
            setV('eModalName', d.name); setV('eModalSbu', String(d.sbu_id || 0)); setV('eModalSector', d.sector);
            setV('eModalAddr', d.address_of_communication);
            setV('eModalState', d.state || '');
            // Fire the AJAX load after the modal is shown; the state
            // dropdown's value is set above, districts fetched here.
            loadDistricts(d.state || '', d.district || '');
            setV('eModalCnum', d.contact_number); setV('eModalCmail', d.contact_email);
            setV('eModalHrName', d.hr_manager_name); setV('eModalHrMob', d.hr_manager_mobile); setV('eModalHrMail', d.hr_manager_email);
            setV('eModalLoiDate', (d.loi_received_date || '').substring(0, 10));
            setV('eModalNotes', d.notes);
            setC('eModalLoi',       d.loi_received === 1);
            setC('eModalRegDwms',   d.registered_in_dwms === 1);
            setC('eModalJobsDwms',  d.jobs_added_in_dwms === 1);
            setC('eModalActive',    d.is_active === 1);
        }
    });
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>

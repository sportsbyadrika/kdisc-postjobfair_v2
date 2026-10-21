<?php
/**
 * LOI & Jobs · Bulk-import Employers from an XLSX file.
 *
 * Three paths through the page:
 *   GET  ?template=1  → stream the XLSX template with the expected columns
 *   GET  (no params)  → upload form + preview area
 *   POST action=preview → parse the upload, stash a preview in $_SESSION,
 *                         re-render the page showing it
 *   POST action=confirm → commit the stashed preview into loi_employer,
 *                         redirect back to the employers list page.
 *
 * Columns (header row must match this order; extra trailing cols
 * are tolerated):
 *   Name *                     — required
 *   SBU                        — name; matched against loi_sbu (nullable)
 *   DWMS Employer ID
 *   Address of communication
 *   State / UT                 — a name from the india_state master, or
 *                                "Outside India"
 *   District / Country name    — India district when state is Indian;
 *                                country name when state is "Outside India"
 *   Contact number
 *   Contact email
 *   HR manager / SPOC
 *   HR mobile
 *   HR email
 *   Employer Sector
 *   LOI received (Y/N)
 *   LOI received date (DD/MM/YYYY)
 *   Upload LOI document (Google Drive URL)
 *   Registered in DWMS (Y/N)
 *   Jobs added in DWMS (Y/N)
 *   Notes
 *   Active (Y/N)               — defaults to Y when blank
 *
 * Only an LOI admin can bulk-import.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/loi_jobs_helpers.php';
require_once __DIR__ . '/includes/india_locations_helpers.php';
require_once __DIR__ . '/includes/xlsx_writer.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
loi_jobs_bootstrap();
india_locations_bootstrap();

$canAdmin = is_manage_admin($viewer) || user_can_admin_module($viewerId, 'loi_jobs');
if (!$canAdmin) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only an LOI admin can bulk-import employers.</div>';
    render_footer(); exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
$sessionKey = 'loi_bulk_employer_preview_' . $viewerId;

/* ---------- Template download ---------- */
if (($_GET['template'] ?? '') === '1') {
    xlsx_send('loi_employers_template.xlsx', 'Employers',
        [
            'Name *', 'SBU', 'DWMS Employer ID', 'Address of communication',
            'State / UT', 'District / Country name',
            'Contact number', 'Contact email',
            'HR manager / SPOC', 'HR mobile', 'HR email',
            'Employer Sector',
            'LOI received (Y/N)', 'LOI received date (DD/MM/YYYY)',
            'Upload LOI document (Google Drive URL)',
            'Registered in DWMS (Y/N)', 'Jobs added in DWMS (Y/N)',
            'Notes', 'Active (Y/N)',
        ],
        [
            [
                'Acme Logistics Pvt Ltd', 'South Zone', 'EMP-01234', '12/3 MG Road, Kochi 682018',
                'Kerala', 'Ernakulam',
                '04842345678', 'hr@acme-logistics.in',
                'Priya Menon', '9876500001', 'priya@acme-logistics.in',
                'Logistics',
                'Y', '12/10/2026',
                'https://drive.google.com/file/d/EXAMPLE_LOI_DOC/view',
                'Y', 'Y',
                'LOI received on 12 Oct, mobilisation starts next week.', 'Y',
            ],
            [
                'Globex Consulting FZE', '', '', '# 1402 Business Bay Tower, Dubai',
                'Outside India', 'United Arab Emirates',
                '+971509123456', 'careers@globex.ae',
                'Rahul Nair', '+971509123457', 'rahul.n@globex.ae',
                'IT & Consulting',
                'N', '',
                '',
                'N', 'N',
                'International employer — LOI under negotiation.', 'Y',
            ],
        ]);
    exit;
}

/* ---------- Reference lookups for validation ---------- */
$sbuRows   = loi_sbu_list(false);                 // name → id lookup, both active + inactive so legacy names still match
$sbuByName = [];
foreach ($sbuRows as $s) $sbuByName[strtolower(trim((string) $s['name']))] = (int) $s['id'];

$stateRows = india_states_list();
$stateByName = [];
foreach ($stateRows as $s) $stateByName[strtolower(trim((string) $s['name']))] = (string) $s['name'];
$stateByName[strtolower('outside india')] = 'Outside India';

// Tiny local column-exists probe — avoids pulling in meetings_helpers.
$loiColExists = static function (string $table, string $column): bool {
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        foreach (db()->query('SHOW COLUMNS FROM ' . $table)->fetchAll() as $c) {
            if (strtolower((string) $c['Field']) === strtolower($column)) return $cache[$key] = true;
        }
    } catch (Throwable $e) { /* table missing */ }
    return $cache[$key] = false;
};
$hasLoiDocCol = $loiColExists('loi_employer', 'loi_document_url');

$parseDate = static function (string $raw): bool|string|null {
    $raw = trim($raw);
    if ($raw === '') return null;
    // Accept DD/MM/YYYY or DD-MM-YYYY or YYYY-MM-DD
    if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $raw, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) return $m[1] . '-' . $m[2] . '-' . $m[3];
    // Excel serial numbers (plain integer) — convert.
    if (ctype_digit($raw)) {
        $t = strtotime('1899-12-30 +' . (int) $raw . ' days');
        if ($t !== false) return date('Y-m-d', $t);
    }
    return false;
};
$parseBool = static function (string $raw, int $default = 0): int {
    $v = strtolower(trim($raw));
    if ($v === '') return $default;
    return in_array($v, ['y','yes','true','1','t','active'], true) ? 1 : 0;
};

/* ---------- Preview handler ---------- */
if (is_post() && ($_POST['action'] ?? '') === 'preview') {
    csrf_check_or_die();
    if (empty($_FILES['file']) || (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $flashMessage = 'Pick an .xlsx file first.';
        $flashType = 'danger';
    } else {
        try {
            $rows = xlsx_read((string) $_FILES['file']['tmp_name']);
        } catch (Throwable $e) {
            $rows = null;
            $flashMessage = 'Could not read the file: ' . $e->getMessage();
            $flashType = 'danger';
        }
        if ($rows !== null) {
            if (count($rows) < 2) {
                $flashMessage = 'File has no data rows (needs a header row + at least one data row).';
                $flashType = 'danger';
            } else {
                $preview = [];
                $rowNo = 1;
                foreach (array_slice($rows, 1) as $r) {
                    $rowNo++;
                    if (count(array_filter($r, static fn($c) => trim((string) $c) !== '')) === 0) continue;
                    $r = array_pad($r, 19, '');
                    $name       = trim((string) $r[0]);
                    $sbuName    = trim((string) $r[1]);
                    $dwmsId     = trim((string) $r[2]);
                    $address    = trim((string) $r[3]);
                    $stateIn    = trim((string) $r[4]);
                    $distIn     = trim((string) $r[5]);
                    $cnum       = trim((string) $r[6]);
                    $cmail      = trim((string) $r[7]);
                    $hrName     = trim((string) $r[8]);
                    $hrMob      = trim((string) $r[9]);
                    $hrMail     = trim((string) $r[10]);
                    $sector     = trim((string) $r[11]);
                    $loiRecv    = $parseBool((string) $r[12], 0);
                    $loiDateRaw = trim((string) $r[13]);
                    $loiDocUrl  = trim((string) $r[14]);
                    $regDwms    = $parseBool((string) $r[15], 0);
                    $jobsDwms   = $parseBool((string) $r[16], 0);
                    $notes      = trim((string) $r[17]);
                    $active     = $parseBool((string) $r[18], 1); // default Yes

                    $errors = [];
                    if ($name === '') $errors[] = 'Name is required.';

                    $sbuId = null;
                    if ($sbuName !== '') {
                        $sbuId = $sbuByName[strtolower($sbuName)] ?? null;
                        if ($sbuId === null) $errors[] = 'SBU "' . $sbuName . '" is not in the master (row will save with no SBU).';
                    }

                    $stateCanon = '';
                    if ($stateIn !== '') {
                        $stateCanon = $stateByName[strtolower($stateIn)] ?? '';
                        if ($stateCanon === '') $errors[] = 'State / UT "' . $stateIn . '" is not in the India master (use "Outside India" for overseas employers).';
                    }

                    $loiDate = null;
                    if ($loiDateRaw !== '') {
                        $parsed = $parseDate($loiDateRaw);
                        if ($parsed === false) $errors[] = 'LOI received date "' . $loiDateRaw . '" is not a valid date (use DD/MM/YYYY).';
                        else $loiDate = $parsed;
                    }

                    if ($cmail !== '' && !filter_var($cmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Contact email looks invalid (' . $cmail . ').';
                    if ($hrMail !== '' && !filter_var($hrMail, FILTER_VALIDATE_EMAIL)) $errors[] = 'HR email looks invalid (' . $hrMail . ').';
                    if ($loiDocUrl !== '' && !preg_match('~^https?://~i', $loiDocUrl)) $errors[] = 'LOI document URL must start with http:// or https://.';

                    $preview[] = [
                        'row_no'    => $rowNo,
                        'errors'    => $errors,
                        'name'      => $name,
                        'sbu_name'  => $sbuName,
                        'sbu_id'    => $sbuId,
                        'dwms_id'   => $dwmsId,
                        'address'   => $address,
                        'state'     => $stateCanon !== '' ? $stateCanon : $stateIn,
                        'district'  => $distIn,
                        'cnum'      => $cnum,
                        'cmail'     => $cmail,
                        'hr_name'   => $hrName,
                        'hr_mob'    => $hrMob,
                        'hr_mail'   => $hrMail,
                        'sector'    => $sector,
                        'loi_recv'  => $loiRecv,
                        'loi_date'  => $loiDate,
                        'loi_doc'   => $loiDocUrl,
                        'reg_dwms'  => $regDwms,
                        'jobs_dwms' => $jobsDwms,
                        'notes'     => $notes,
                        'active'    => $active,
                    ];
                }
                $_SESSION[$sessionKey] = $preview;
                $flashMessage = 'Parsed ' . count($preview) . ' row(s). Review the preview below and click Confirm to import.';
                $flashType = 'success';
            }
        }
    }
}

/* ---------- Confirm handler ---------- */
if (is_post() && ($_POST['action'] ?? '') === 'confirm') {
    csrf_check_or_die();
    $preview = $_SESSION[$sessionKey] ?? [];
    $force   = isset($_POST['force']);
    if ($preview === []) {
        $flashMessage = 'Nothing to confirm — upload a file first.';
        $flashType = 'danger';
    } else {
        $inserted = 0; $skipped = 0;
        $db = db();
        $db->query('START TRANSACTION');
        try {
            $hasLoiDoc  = $loiColExists('loi_employer', 'loi_document_url');
            $hasDwmsEmp = $loiColExists('loi_employer', 'dwms_employer_id');
            $insert = $db->prepare('INSERT INTO loi_employer
                (sbu_id, name, dwms_employer_id, address_of_communication, state, district, contact_number, contact_email,
                 hr_manager_name, hr_manager_mobile, hr_manager_email,
                 sector, loi_received, loi_received_date,
                 registered_in_dwms, jobs_added_in_dwms, notes,
                 is_active, created_at, updated_at, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)');
            foreach ($preview as $r) {
                // Fatal error: Name required. Soft errors push through
                // on force; without force they skip.
                $hasName = trim((string) $r['name']) !== '';
                if (!$hasName) { $skipped++; continue; }
                $hasSoftErrors = false;
                foreach ($r['errors'] as $e) {
                    if (strpos($e, 'Name is required') === 0) { /* fatal — handled above */ continue; }
                    $hasSoftErrors = true;
                }
                if ($hasSoftErrors && !$force) { $skipped++; continue; }

                $insert->execute([
                    $r['sbu_id'] ?: null,
                    $r['name'],
                    $hasDwmsEmp && $r['dwms_id'] !== '' ? $r['dwms_id'] : null,
                    $r['address']  === '' ? null : $r['address'],
                    $r['state']    === '' ? null : $r['state'],
                    $r['district'] === '' ? null : $r['district'],
                    $r['cnum']     === '' ? null : $r['cnum'],
                    $r['cmail']    === '' ? null : $r['cmail'],
                    $r['hr_name']  === '' ? null : $r['hr_name'],
                    $r['hr_mob']   === '' ? null : $r['hr_mob'],
                    $r['hr_mail']  === '' ? null : $r['hr_mail'],
                    $r['sector']   === '' ? null : $r['sector'],
                    (int) $r['loi_recv'],
                    $r['loi_date'],
                    (int) $r['reg_dwms'],
                    (int) $r['jobs_dwms'],
                    $r['notes']    === '' ? null : $r['notes'],
                    (int) $r['active'],
                    $viewerId, $viewerId,
                ]);
                $newId = (int) $db->lastInsertId();
                if ($hasLoiDoc && $r['loi_doc'] !== '') {
                    try { $db->prepare('UPDATE loi_employer SET loi_document_url = ? WHERE id = ?')->execute([$r['loi_doc'], $newId]); }
                    catch (Throwable $e) { /* ignore */ }
                }
                $inserted++;
            }
            $db->query('COMMIT');
            unset($_SESSION[$sessionKey]);
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['loi_employers_flash'] = ['msg' => 'Imported ' . $inserted . ' employer(s). ' . ($skipped > 0 ? ($skipped . ' row(s) skipped due to validation errors — enable "Force-import warning rows" and retry if you want them pushed through.') : ''), 'type' => 'success'];
            header('Location: /loi_employers.php'); exit;
        } catch (Throwable $e) {
            try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
            $flashMessage = 'Import failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

if (($_GET['clear'] ?? '') === '1') { unset($_SESSION[$sessionKey]); header('Location: /loi_bulk_import.php'); exit; }

$preview   = $_SESSION[$sessionKey] ?? [];
$errorRows = array_filter($preview, static fn($r) => $r['errors'] !== []);

render_header('LOI · Bulk upload employers', ['main_container_class' => 'container-xl']);
render_page_header('LOI · Bulk upload employers', [
    'icon'     => 'bi-file-earmark-arrow-up',
    'subtitle' => 'Import a batch of employers from an .xlsx file. Each row becomes one loi_employer record. Name is mandatory; every other field is optional.',
    'actions'  => '<a class="btn btn-success" href="/loi_bulk_import.php?template=1"><i class="bi bi-file-earmark-arrow-down me-1"></i>Download template</a>
        <a class="btn btn-light ms-2" href="/loi_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>',
]);
?>

<?php if ($flashMessage !== null): ?><div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card mb-3" id="bulkForm">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="preview">
    <div class="card-header"><i class="bi bi-1-circle text-primary me-1"></i>Upload</div>
    <div class="card-body">
        <div id="dropZone" class="border rounded p-5 text-center" style="background:#f8fafc; border-style: dashed !important; cursor: pointer;">
            <i class="bi bi-cloud-arrow-up" style="font-size: 2.5rem; color: #94a3b8;"></i>
            <div class="mt-2"><strong>Drag and drop</strong> an .xlsx file here, or <a href="#" id="pickFileBtn">browse</a>.</div>
            <div class="small text-muted mt-1">Only <code>.xlsx</code> files are accepted. Download the template to see the expected columns.</div>
            <div class="small text-success mt-2" id="pickedFileName" style="display:none;"></div>
            <input type="file" name="file" id="pickFile" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" style="display:none;">
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="small text-muted">Header row must match the template. Only <strong>Name</strong> is mandatory; other columns may be left blank.</div>
        <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Preview</button>
    </div>
</form>

<?php if ($preview !== []): ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-2-circle text-primary me-1"></i>Preview</span>
        <div>
            <span class="badge text-bg-light border me-1"><?= count($preview) ?> rows</span>
            <?php if ($errorRows !== []): ?><span class="badge text-bg-warning"><?= count($errorRows) ?> with warnings</span><?php endif; ?>
        </div>
    </div>
    <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
        <table class="table table-sm align-middle mb-0">
            <thead class="sticky-top bg-light">
                <tr>
                    <th>Row</th>
                    <th>Name *</th>
                    <th>SBU</th>
                    <th>DWMS ID</th>
                    <th>State</th>
                    <th>District / Country</th>
                    <th>Sector</th>
                    <th>LOI</th>
                    <th>Date</th>
                    <th>Active</th>
                    <th>Errors / warnings</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($preview as $r):
                    $fatal = trim((string) $r['name']) === '';
                    $hasErr = $r['errors'] !== [];
                    $rowClass = $fatal ? 'table-danger' : ($hasErr ? 'table-warning' : '');
                ?>
                    <tr class="<?= $rowClass ?>">
                        <td><?= (int) $r['row_no'] ?></td>
                        <td class="fw-semibold"><?= esc($r['name']) ?: '<span class="text-danger">—</span>' ?></td>
                        <td class="small"><?= esc($r['sbu_name']) ?></td>
                        <td class="small font-monospace"><?= esc($r['dwms_id']) ?></td>
                        <td class="small"><?= esc($r['state']) ?></td>
                        <td class="small"><?= esc($r['district']) ?></td>
                        <td class="small"><?= esc($r['sector']) ?></td>
                        <td class="small"><?= $r['loi_recv'] ? 'Y' : 'N' ?></td>
                        <td class="small"><?= $r['loi_date'] ? esc(date('d/m/Y', strtotime((string) $r['loi_date']))) : '' ?></td>
                        <td class="small"><?= $r['active'] ? 'Y' : 'N' ?></td>
                        <td class="small text-danger">
                            <?php foreach ($r['errors'] as $e): ?><div><?= esc($e) ?></div><?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <a class="btn btn-light" href="/loi_bulk_import.php?clear=1">Discard preview</a>
        <form method="post" class="d-flex align-items-center gap-3 flex-wrap">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="confirm">
            <?php if ($errorRows !== []): ?>
                <div class="form-check" title="Push warning rows through anyway. Rows with no Name are always skipped.">
                    <input class="form-check-input" type="checkbox" id="forceImport" name="force" value="1">
                    <label class="form-check-label small" for="forceImport">
                        <strong>Force-import warning rows</strong>
                        <span class="text-muted">(SBU / state mismatches saved as text; rows with no Name are always skipped)</span>
                    </label>
                </div>
            <?php endif; ?>
            <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Confirm &amp; import</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    const zone = document.getElementById('dropZone');
    const fin  = document.getElementById('pickFile');
    const name = document.getElementById('pickedFileName');
    const btn  = document.getElementById('pickFileBtn');
    const show = (f) => { name.textContent = 'Selected: ' + f.name; name.style.display = ''; };
    btn?.addEventListener('click', (e) => { e.preventDefault(); fin.click(); });
    zone?.addEventListener('click', () => fin.click());
    fin?.addEventListener('change', () => { if (fin.files[0]) show(fin.files[0]); });
    ['dragenter', 'dragover'].forEach(ev => zone?.addEventListener(ev, (e) => { e.preventDefault(); zone.style.background = '#eff6ff'; }));
    ['dragleave', 'drop'  ].forEach(ev => zone?.addEventListener(ev, (e) => { e.preventDefault(); zone.style.background = '#f8fafc'; }));
    zone?.addEventListener('drop', (e) => {
        if (e.dataTransfer?.files?.length) { fin.files = e.dataTransfer.files; show(fin.files[0]); }
    });
})();
</script>

<?php render_footer(); ?>

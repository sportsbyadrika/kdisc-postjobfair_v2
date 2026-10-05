<?php
/**
 * Meeting · Bulk-import Agenda + Decision rows from an XLSX file.
 *
 * Three paths through the page:
 *   GET  ?id=<meeting_id>&template=1  → stream the XLSX template
 *   GET  ?id=<meeting_id>             → upload form + preview area
 *   POST action=preview               → parse the upload, stash a
 *                                       preview in $_SESSION, re-
 *                                       render the page showing it
 *   POST action=confirm               → commit the stashed preview
 *                                       into meeting_agenda +
 *                                       meeting_decision, trigger
 *                                       Own-Tasks sync for the
 *                                       decisions, redirect back
 *                                       to the meeting edit page.
 *
 * The template is a single sheet with eight columns:
 *   Type · Heading/Title · Description · Due Date · Internal Users
 *     · External Users · Show in Own Tasks · Private Status
 *
 * Only the creator + admins can bulk-import.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/meetings_helpers.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';
require_once __DIR__ . '/includes/xlsx_writer.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
meetings_bootstrap();
task_tracker_bootstrap();

$isAdminAll = is_manage_admin($viewer) || user_can_admin_module($viewerId, 'meetings');

$meetingId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($meetingId <= 0) { header('Location: /meetings.php'); exit; }
$st = db()->prepare('SELECT * FROM meeting WHERE id = ? LIMIT 1');
$st->execute([$meetingId]);
$meeting = $st->fetch();
if ($meeting === false) { header('Location: /meetings.php'); exit; }
$isCreator = (int) $meeting['created_by'] === $viewerId;
if (!$isCreator && !$isAdminAll) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only the meeting creator or a Meetings admin can bulk-import.</div>';
    render_footer(); exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
$sessionKey = 'meeting_bulk_preview_' . $meetingId;

/* ---------- Template download ---------- */
if (($_GET['template'] ?? '') === '1') {
    $today = date('d/m/Y');
    xlsx_send('meeting_minutes_template.xlsx', 'Minutes',
        ['Type', 'Heading/Title', 'Description', 'Due Date (DD/MM/YYYY)', 'Internal Users (comma-separated names)', 'External Users (comma-separated names)', 'Show in Own Tasks (Y/N)', 'Private Status (Y/N)'],
        [
            ['Agenda',   'Welcome address',           'Opening remarks by the chair', '', '', '', '', ''],
            ['Agenda',   'Review of previous minutes', 'Walk through the action items from the last meeting', '', 'Admin', '', '', ''],
            ['Decision', 'Finalise contractor list',  'Approve the three contractors shortlisted by the panel', $today, 'Alice Kumar, Bob Rao', '', 'Y', 'N'],
            ['Decision', 'Private HR matter',         'Confidential discussion', $today, 'Alice Kumar', '', 'Y', 'Y'],
            ['', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', ''],
        ]);
}

/* ---------- Helpers ---------- */
$users = [];
try {
    foreach (db()->query('SELECT id, name FROM users WHERE active_status = 1 ORDER BY name ASC')->fetchAll() as $u) {
        $users[strtolower(trim((string) $u['name']))][] = (int) $u['id'];
    }
} catch (Throwable $e) { /* ignore */ }
$contacts = [];
try {
    foreach (db()->query('SELECT id, name, institution FROM contact WHERE is_active = 1 ORDER BY name ASC')->fetchAll() as $c) {
        $contacts[strtolower(trim((string) $c['name']))][] = ['id' => (int) $c['id'], 'inst' => (string) ($c['institution'] ?? '')];
    }
} catch (Throwable $e) { /* ignore */ }

$resolveNames = static function (string $csv, array $pool): array {
    $out = []; $warnings = [];
    foreach (preg_split('/[,;]/', $csv) as $raw) {
        $raw = trim($raw);
        if ($raw === '') continue;
        $key = strtolower($raw);
        if (!isset($pool[$key])) { $warnings[] = $raw; continue; }
        foreach ($pool[$key] as $row) {
            $id = is_array($row) ? (int) $row['id'] : (int) $row;
            if (!in_array($id, $out, true)) $out[] = $id;
        }
    }
    return ['ids' => $out, 'warnings' => $warnings];
};
$parseDate = static function (string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    // DD/MM/YYYY or DD-MM-YYYY
    if (preg_match('#^(\d{1,2})[/\-](\d{1,2})[/\-](\d{4})$#', $s, $m)) return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    // YYYY-MM-DD
    if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $s, $m)) return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    return false;
};

/* ---------- POST: preview ---------- */
if (is_post() && ($_POST['action'] ?? '') === 'preview') {
    csrf_check_or_die();
    if (empty($_FILES['file']) || (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $flashMessage = 'Pick an .xlsx file first (drag-and-drop or Choose file).';
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
                $flashMessage = 'File has no data rows (needs a header + at least one row).';
                $flashType = 'danger';
            } else {
                $header = $rows[0];
                if (count($header) < 8) {
                    $flashMessage = 'Header row must have 8 columns in this order: Type · Heading/Title · Description · Due Date · Internal Users · External Users · Show in Own Tasks · Private Status.';
                    $flashType = 'danger';
                } else {
                    $preview = [];
                    $rowNo = 1;
                    foreach (array_slice($rows, 1) as $r) {
                        $rowNo++;
                        if (count(array_filter($r, static fn($c) => trim((string) $c) !== '')) === 0) continue;
                        $r = array_pad($r, 8, '');
                        $type   = strtolower(trim((string) $r[0]));
                        $head   = trim((string) $r[1]);
                        $desc   = trim((string) $r[2]);
                        $dueRaw = trim((string) $r[3]);
                        $intCsv = trim((string) $r[4]);
                        $extCsv = trim((string) $r[5]);
                        $own    = strtoupper(trim((string) $r[6]));
                        $priv   = strtoupper(trim((string) $r[7]));

                        $errors = [];
                        if (!in_array($type, ['agenda', 'decision'], true)) $errors[] = 'Type must be "Agenda" or "Decision".';
                        if ($head === '') $errors[] = 'Heading/Title is required.';
                        $due = $parseDate($dueRaw);
                        if ($due === false) $errors[] = 'Due Date "' . $dueRaw . '" is not valid (use DD/MM/YYYY).';
                        $ri = $resolveNames($intCsv, $users);
                        $re = $resolveNames($extCsv, $contacts);
                        if ($ri['warnings'] !== []) $errors[] = 'Internal User not found: ' . implode('; ', $ri['warnings']);
                        if ($re['warnings'] !== []) $errors[] = 'External User not found: ' . implode('; ', $re['warnings']);

                        $preview[] = [
                            'row_no'      => $rowNo,
                            'type'        => $type,
                            'head'        => $head,
                            'desc'        => $desc,
                            'due_date'    => $due === false ? null : $due,
                            'int_user_ids'=> $ri['ids'],
                            'ext_user_ids'=> $re['ids'],
                            'create_own'  => $type === 'decision' ? ($own === 'N' || $own === 'NO' ? 0 : 1) : 1,
                            'private'     => $type === 'decision' ? ($priv === 'Y' || $priv === 'YES' ? 1 : 0) : 0,
                            'raw_int'     => $intCsv,
                            'raw_ext'     => $extCsv,
                            'errors'      => $errors,
                        ];
                    }
                    $_SESSION[$sessionKey] = $preview;
                    $flashMessage = 'Previewing ' . count($preview) . ' row(s). Rows with errors will be skipped on confirm.';
                    $flashType = 'info';
                }
            }
        }
    }
}

/* ---------- POST: confirm ---------- */
if (is_post() && ($_POST['action'] ?? '') === 'confirm') {
    csrf_check_or_die();
    $preview = $_SESSION[$sessionKey] ?? [];
    if ($preview === []) {
        $flashMessage = 'Nothing to confirm — upload a file first.';
        $flashType = 'danger';
    } else {
        $inserted = 0; $skipped = 0; $newDecisionIds = [];
        $db = db();
        $db->query('START TRANSACTION');
        try {
            // Starting sort_order for agenda + decision, respecting
            // what the meeting already has so bulk-imports slot in at
            // the tail instead of colliding on 1.
            $aSort = (int) $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM meeting_agenda WHERE meeting_id = ?')->execute([$meetingId]);
            $st = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) AS n FROM meeting_agenda WHERE meeting_id = ?'); $st->execute([$meetingId]);
            $aSort = (int) ($st->fetchColumn() ?: 0);
            $st = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) AS n FROM meeting_decision WHERE meeting_id = ?'); $st->execute([$meetingId]);
            $dSort = (int) ($st->fetchColumn() ?: 0);
            // Does the decision table have the flag columns?
            $hasFlags = false;
            try {
                $cols = [];
                foreach ($db->query('SHOW COLUMNS FROM meeting_decision')->fetchAll() as $c) $cols[strtolower((string) $c['Field'])] = true;
                $hasFlags = isset($cols['create_own_tasks']) && isset($cols['status_private']);
            } catch (Throwable $e) { /* ignore */ }

            $insAgenda = $db->prepare('INSERT INTO meeting_agenda (meeting_id, sort_order, title, description) VALUES (?, ?, ?, ?)');
            $insALead  = $db->prepare('INSERT INTO meeting_agenda_lead (agenda_id, user_id, seat_id, contact_id) VALUES (?, ?, ?, ?)');
            $insDec    = $hasFlags
                ? $db->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date, create_own_tasks, status_private) VALUES (?, ?, ?, ?, ?, ?, ?)')
                : $db->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date) VALUES (?, ?, ?, ?, ?)');
            $insDResp  = $db->prepare('INSERT INTO meeting_decision_responsible (decision_id, user_id, contact_id, seat_id) VALUES (?, ?, ?, ?)');

            foreach ($preview as $row) {
                if ($row['errors'] !== []) { $skipped++; continue; }
                if ($row['type'] === 'agenda') {
                    $aSort++;
                    $insAgenda->execute([$meetingId, $aSort, $row['head'], $row['desc'] === '' ? null : $row['desc']]);
                    $newId = $db->lastInsertId();
                    foreach ($row['int_user_ids'] as $uid) $insALead->execute([$newId, $uid, null, null]);
                    foreach ($row['ext_user_ids'] as $cid) $insALead->execute([$newId, null, null, $cid]);
                    // Keep legacy single-id columns populated with first entry.
                    $db->prepare('UPDATE meeting_agenda SET lead_user_id = ?, lead_contact_id = ? WHERE id = ?')
                        ->execute([$row['int_user_ids'][0] ?? null, $row['ext_user_ids'][0] ?? null, $newId]);
                    $inserted++;
                } else {
                    $dSort++;
                    $params = [$meetingId, $dSort, $row['head'], $row['desc'] === '' ? null : $row['desc'], $row['due_date']];
                    if ($hasFlags) { $params[] = $row['create_own']; $params[] = $row['private']; }
                    $insDec->execute($params);
                    $newId = $db->lastInsertId();
                    foreach ($row['int_user_ids'] as $uid) $insDResp->execute([$newId, $uid, null, null]);
                    foreach ($row['ext_user_ids'] as $cid) $insDResp->execute([$newId, null, $cid, null]);
                    if ($row['create_own']) $newDecisionIds[$newId] = $row['int_user_ids'];
                    $inserted++;
                }
            }
            $db->query('COMMIT');
            unset($_SESSION[$sessionKey]);

            // Fire Own-Tasks sync for each newly-created decision
            // outside the transaction so a sync failure doesn't
            // undo the committed decision rows.
            foreach ($newDecisionIds as $decId => $uids) {
                meetings_sync_decision_tasks((int) $decId, (array) $uids, $viewerId);
            }

            $_SESSION['meeting_view_flash'] = ['msg' => 'Imported ' . $inserted . ' row(s); skipped ' . $skipped . ' row(s) with errors.', 'type' => 'success'];
            header('Location: /meeting_edit.php?id=' . $meetingId); exit;
        } catch (Throwable $e) {
            try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
            $flashMessage = 'Commit failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

$preview = $_SESSION[$sessionKey] ?? [];
$errorRows = array_filter($preview, static fn($r) => $r['errors'] !== []);

render_header('Meetings · Bulk upload', ['main_container_class' => 'container-xl']);
render_page_header('Bulk upload minutes · ' . ($meeting['reference_no'] ?? ''), [
    'icon'     => 'bi-file-earmark-arrow-up',
    'subtitle' => 'Upload agenda items and decision points for this meeting from an .xlsx file.',
    'actions'  => '<a class="btn btn-success" href="/meeting_bulk_import.php?id=' . $meetingId . '&template=1"><i class="bi bi-file-earmark-arrow-down me-1"></i>Download template</a>
        <a class="btn btn-light ms-2" href="/meeting_edit.php?id=' . $meetingId . '"><i class="bi bi-arrow-left me-1"></i>Back to meeting</a>',
]);
?>

<?php if ($flashMessage !== null): ?><div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card mb-3" id="bulkForm">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="preview">
    <input type="hidden" name="id" value="<?= (int) $meetingId ?>">
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
        <div class="small text-muted">Expected header row: <code>Type · Heading/Title · Description · Due Date · Internal Users · External Users · Show in Own Tasks · Private Status</code></div>
        <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Preview</button>
    </div>
</form>

<?php if ($preview !== []): ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-2-circle text-primary me-1"></i>Preview</span>
        <div>
            <span class="badge text-bg-light border me-1"><?= count($preview) ?> rows</span>
            <?php if ($errorRows !== []): ?><span class="badge text-bg-warning"><?= count($errorRows) ?> will be skipped</span><?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr><th>Row</th><th>Type</th><th>Heading / Title</th><th>Description</th><th>Due</th><th>Internal</th><th>External</th><th>Own Tasks</th><th>Private</th><th>Errors</th></tr>
            </thead>
            <tbody>
                <?php foreach ($preview as $r):
                    $hasErr = $r['errors'] !== [];
                    $int = implode(', ', $r['int_user_ids']); // names could be looked up, but IDs are easier here
                    $ext = implode(', ', $r['ext_user_ids']);
                ?>
                    <tr class="<?= $hasErr ? 'table-warning' : '' ?>">
                        <td><?= (int) $r['row_no'] ?></td>
                        <td><span class="badge text-bg-<?= $r['type'] === 'decision' ? 'primary' : 'secondary' ?>"><?= esc(ucfirst($r['type'])) ?></span></td>
                        <td><?= esc($r['head']) ?></td>
                        <td class="small text-muted"><?= esc(mb_substr((string) $r['desc'], 0, 120)) ?></td>
                        <td class="small"><?= esc((string) ($r['due_date'] ?? '')) ?></td>
                        <td class="small"><?= esc($r['raw_int']) ?></td>
                        <td class="small"><?= esc($r['raw_ext']) ?></td>
                        <td class="small"><?= $r['type'] === 'decision' ? ((int) $r['create_own'] === 1 ? 'Yes' : 'No') : '—' ?></td>
                        <td class="small"><?= $r['type'] === 'decision' ? ((int) $r['private'] === 1 ? 'Yes' : 'No') : '—' ?></td>
                        <td class="small text-danger">
                            <?php foreach ($r['errors'] as $e): ?><div><?= esc($e) ?></div><?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a class="btn btn-light" href="/meeting_bulk_import.php?id=<?= $meetingId ?>&clear=1">Discard preview</a>
        <form method="post" class="d-inline">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="id" value="<?= $meetingId ?>">
            <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Confirm &amp; import</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (($_GET['clear'] ?? '') === '1') { unset($_SESSION[$sessionKey]); echo '<script>location.href="/meeting_bulk_import.php?id=' . (int) $meetingId . '";</script>'; exit; } ?>

<script>
(function () {
    const dz    = document.getElementById('dropZone');
    const input = document.getElementById('pickFile');
    const nameLabel = document.getElementById('pickedFileName');
    const show = (f) => {
        if (!f) return;
        nameLabel.textContent = '✓ ' + f.name + ' (' + Math.round(f.size / 1024) + ' KB)';
        nameLabel.style.display = 'block';
    };
    document.getElementById('pickFileBtn')?.addEventListener('click', (e) => { e.preventDefault(); input.click(); });
    dz.addEventListener('click', () => input.click());
    input.addEventListener('change', () => show(input.files[0]));
    ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, (e) => { e.preventDefault(); dz.style.background = '#dbeafe'; }));
    ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, (e) => { e.preventDefault(); dz.style.background = '#f8fafc'; }));
    dz.addEventListener('drop', (e) => {
        if (e.dataTransfer?.files?.length) {
            // Can't set input.files = ... directly in all browsers; use DataTransfer.
            const dt = new DataTransfer();
            dt.items.add(e.dataTransfer.files[0]);
            input.files = dt.files;
            show(input.files[0]);
        }
    });
})();
</script>

<?php render_footer(); ?>

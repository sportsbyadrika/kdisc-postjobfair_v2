<?php
/**
 * Meeting · Bulk-import Agenda + Decision + Next-Agenda rows from an
 * XLSX file.
 *
 * Three paths through the page:
 *   GET  ?id=<meeting_id>&template=1  → stream the XLSX template
 *   GET  ?id=<meeting_id>             → upload form + preview area
 *   POST action=preview               → parse the upload, stash a
 *                                       preview in $_SESSION, re-
 *                                       render the page showing it
 *   POST action=confirm               → commit the stashed preview
 *                                       into meeting_agenda +
 *                                       meeting_decision +
 *                                       meeting_next_agenda, trigger
 *                                       Own-Tasks sync for the
 *                                       decisions, redirect back to
 *                                       the meeting edit page.
 *
 * The template is a single sheet with eight columns:
 *   Type · Heading/Title · Description · Due Date · Internal Users
 *     · External Users · Show in Own Tasks · Private Status
 *
 * Type is one of: Agenda | Decision | Next Agenda. "Next Agenda" rows
 * only read Heading + Description — Due Date, Users, Own Tasks and
 * Private flags are ignored on them.
 *
 * Only the creator + admins can bulk-import.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/meetings_helpers.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';
require_once __DIR__ . '/includes/teams_helpers.php';
require_once __DIR__ . '/includes/xlsx_writer.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
meetings_bootstrap();
task_tracker_bootstrap();
teams_bootstrap();
$teamsHasTeamCol = teams_responsible_has_team_id();
$teamsHasFanOutCol = teams_decision_has_fan_out();

$isAdminAll = is_manage_admin($viewer) || user_can_admin_module($viewerId, 'meetings');

$meetingId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($meetingId <= 0) { header('Location: /meetings.php'); exit; }
$st = db()->prepare('SELECT * FROM meeting WHERE id = ? LIMIT 1');
$st->execute([$meetingId]);
$meeting = $st->fetch();
if ($meeting === false) { header('Location: /meetings.php'); exit; }
if (!meetings_can_edit($meeting, $viewer)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only the meeting creator, the chairperson, or a Meetings admin can bulk-import.</div>';
    render_footer(); exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
$sessionKey = 'meeting_bulk_preview_' . $meetingId;

/* ---------- Template download ---------- */
if (($_GET['template'] ?? '') === '1') {
    $today = date('d/m/Y');
    xlsx_send('meeting_minutes_template.xlsx', 'Minutes',
        ['Type (Agenda / Decision / Next Agenda)', 'Heading/Title', 'Description', 'Due Date (DD/MM/YYYY)', 'Internal Users (comma-separated names)', 'External Users (comma-separated names)', 'Show in Own Tasks (Y/N)', 'Private Status (Y/N)', 'Teams (comma-separated team names)', 'Fan out team tasks (Y/N)'],
        [
            ['Agenda',      'Welcome address',           'Opening remarks by the chair', '', '', '', '', '', '', ''],
            ['Agenda',      'Review of previous minutes', 'Walk through the action items from the last meeting', '', 'Admin', '', '', '', '', ''],
            ['Decision',    'Finalise contractor list',  'Approve the three contractors shortlisted by the panel', $today, 'Alice Kumar, Bob Rao', '', 'Y', 'N', '', ''],
            ['Decision',    'Private HR matter',         'Confidential discussion', $today, 'Alice Kumar', '', 'Y', 'Y', '', ''],
            ['Decision',    'Rollout plan review',       'Weekly review assigned to a team',           $today, '', '', 'Y', 'N', 'Procurement team', 'N'],
            ['Decision',    'Field inspection',          'Everyone in the field team gets a task',     $today, '', '', 'Y', 'N', 'Field team', 'Y'],
            ['Next Agenda', 'Budget utilisation review', 'Carry forward to the next meeting',           '', '', '', '', '', '', ''],
            ['Next Agenda', 'Vendor onboarding update',  'Status report from procurement',               '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', '', ''],
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
$teamsLookup = [];
try {
    foreach (db()->query('SELECT id, name FROM team WHERE is_active = 1 ORDER BY name ASC')->fetchAll() as $t) {
        $teamsLookup[strtolower(trim((string) $t['name']))][] = (int) $t['id'];
    }
} catch (Throwable $e) { /* teams table may not exist on legacy installs */ }

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
// Normalise a "Type" cell value to one of three canonical tokens or
// null when unrecognised. Accepts "next", "next agenda", "nextagenda",
// "next-agenda" and the plain "agenda" / "decision".
$normType = static function (string $raw): ?string {
    $t = strtolower(trim($raw));
    $t = preg_replace('/[\s\-_]+/', '', $t);
    if ($t === 'agenda') return 'agenda';
    if ($t === 'decision') return 'decision';
    if ($t === 'nextagenda' || $t === 'next') return 'next_agenda';
    return null;
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
                    $flashMessage = 'Header row must have at least 8 columns in this order: Type · Heading/Title · Description · Due Date · Internal Users · External Users · Show in Own Tasks · Private Status (optionally followed by Teams · Fan out team tasks).';
                    $flashType = 'danger';
                } else {
                    $preview = [];
                    $rowNo = 1;
                    foreach (array_slice($rows, 1) as $r) {
                        $rowNo++;
                        if (count(array_filter($r, static fn($c) => trim((string) $c) !== '')) === 0) continue;
                        $r = array_pad($r, 10, '');
                        $type   = $normType((string) $r[0]);
                        $head   = trim((string) $r[1]);
                        $desc   = trim((string) $r[2]);
                        $dueRaw = trim((string) $r[3]);
                        $intCsv = trim((string) $r[4]);
                        $extCsv = trim((string) $r[5]);
                        $own    = strtoupper(trim((string) $r[6]));
                        $priv   = strtoupper(trim((string) $r[7]));
                        $teamCsv= trim((string) ($r[8] ?? ''));
                        $fanRaw = strtoupper(trim((string) ($r[9] ?? '')));

                        $errors = [];
                        if ($type === null) $errors[] = 'Type must be "Agenda", "Decision" or "Next Agenda".';
                        if ($head === '') $errors[] = 'Heading/Title is required.';

                        // Date + users only matter for agenda/decision —
                        // next_agenda is a plain title/description list.
                        $due = null;
                        $ri = ['ids' => [], 'warnings' => []];
                        $re = ['ids' => [], 'warnings' => []];
                        $rt = ['ids' => [], 'warnings' => []];
                        if ($type !== 'next_agenda') {
                            $due = $parseDate($dueRaw);
                            if ($due === false) $errors[] = 'Due Date "' . $dueRaw . '" is not valid (use DD/MM/YYYY).';
                            $ri = $resolveNames($intCsv, $users);
                            $re = $resolveNames($extCsv, $contacts);
                            if ($ri['warnings'] !== []) $errors[] = 'Internal User not found: ' . implode('; ', $ri['warnings']);
                            if ($re['warnings'] !== []) $errors[] = 'External User not found: ' . implode('; ', $re['warnings']);
                            if ($teamCsv !== '') {
                                $rt = $resolveNames($teamCsv, $teamsLookup);
                                if (!$teamsHasTeamCol) $errors[] = 'Teams column present but teams schema not installed on this host.';
                                if ($rt['warnings'] !== []) $errors[] = 'Team not found: ' . implode('; ', $rt['warnings']);
                            }
                        }

                        $preview[] = [
                            'row_no'      => $rowNo,
                            'type'        => $type ?? '',
                            'head'        => $head,
                            'desc'        => $desc,
                            'due_date'    => $due === false ? null : $due,
                            'int_user_ids'=> $ri['ids'],
                            'ext_user_ids'=> $re['ids'],
                            'team_ids'    => $rt['ids'],
                            'create_own'  => $type === 'decision' ? ($own === 'N' || $own === 'NO' ? 0 : 1) : 1,
                            'private'     => $type === 'decision' ? ($priv === 'Y' || $priv === 'YES' ? 1 : 0) : 0,
                            'fan_out'     => $type === 'decision' ? ($fanRaw === 'Y' || $fanRaw === 'YES' ? 1 : 0) : 0,
                            'raw_int'     => $type === 'next_agenda' ? '' : $intCsv,
                            'raw_ext'     => $type === 'next_agenda' ? '' : $extCsv,
                            'raw_team'    => $type === 'next_agenda' ? '' : $teamCsv,
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
            // Starting sort_order for each list, respecting what the
            // meeting already has so bulk-imports slot in at the tail
            // instead of colliding on 1.
            $st = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) AS n FROM meeting_agenda WHERE meeting_id = ?'); $st->execute([$meetingId]);
            $aSort = (int) ($st->fetchColumn() ?: 0);
            $st = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) AS n FROM meeting_decision WHERE meeting_id = ?'); $st->execute([$meetingId]);
            $dSort = (int) ($st->fetchColumn() ?: 0);
            $st = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) AS n FROM meeting_next_agenda WHERE meeting_id = ?'); $st->execute([$meetingId]);
            $nSort = (int) ($st->fetchColumn() ?: 0);
            // Does the decision table have the flag + fan-out columns?
            $hasFlags = false; $hasFanOut = false;
            try {
                $cols = [];
                foreach ($db->query('SHOW COLUMNS FROM meeting_decision')->fetchAll() as $c) $cols[strtolower((string) $c['Field'])] = true;
                $hasFlags  = isset($cols['create_own_tasks']) && isset($cols['status_private']);
                $hasFanOut = isset($cols['fan_out_teams']);
            } catch (Throwable $e) { /* ignore */ }
            // And the responsible table — does it carry team_id?
            $hasRespTeam = false;
            try {
                $cols2 = [];
                foreach ($db->query('SHOW COLUMNS FROM meeting_decision_responsible')->fetchAll() as $c) $cols2[strtolower((string) $c['Field'])] = true;
                $hasRespTeam = isset($cols2['team_id']);
            } catch (Throwable $e) { /* ignore */ }

            $insAgenda = $db->prepare('INSERT INTO meeting_agenda (meeting_id, sort_order, title, description) VALUES (?, ?, ?, ?)');
            $insALead  = $db->prepare('INSERT INTO meeting_agenda_lead (agenda_id, user_id, seat_id, contact_id) VALUES (?, ?, ?, ?)');
            if ($hasFlags && $hasFanOut) {
                $insDec = $db->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date, create_own_tasks, status_private, fan_out_teams) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            } elseif ($hasFlags) {
                $insDec = $db->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date, create_own_tasks, status_private) VALUES (?, ?, ?, ?, ?, ?, ?)');
            } else {
                $insDec = $db->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date) VALUES (?, ?, ?, ?, ?)');
            }
            $insDResp  = $hasRespTeam
                ? $db->prepare('INSERT INTO meeting_decision_responsible (decision_id, user_id, contact_id, seat_id, team_id) VALUES (?, ?, ?, ?, ?)')
                : $db->prepare('INSERT INTO meeting_decision_responsible (decision_id, user_id, contact_id, seat_id) VALUES (?, ?, ?, ?)');
            $insNext   = $db->prepare('INSERT INTO meeting_next_agenda (meeting_id, sort_order, title, description) VALUES (?, ?, ?, ?)');

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
                } elseif ($row['type'] === 'decision') {
                    $dSort++;
                    $params = [$meetingId, $dSort, $row['head'], $row['desc'] === '' ? null : $row['desc'], $row['due_date']];
                    if ($hasFlags) { $params[] = $row['create_own']; $params[] = $row['private']; }
                    if ($hasFlags && $hasFanOut) { $params[] = (int) ($row['fan_out'] ?? 0); }
                    $insDec->execute($params);
                    $newId = $db->lastInsertId();
                    if ($hasRespTeam) {
                        foreach ($row['int_user_ids'] as $uid) $insDResp->execute([$newId, $uid, null, null, null]);
                        foreach ($row['ext_user_ids'] as $cid) $insDResp->execute([$newId, null, $cid, null, null]);
                        foreach (($row['team_ids'] ?? []) as $tid) $insDResp->execute([$newId, null, null, null, $tid]);
                    } else {
                        foreach ($row['int_user_ids'] as $uid) $insDResp->execute([$newId, $uid, null, null]);
                        foreach ($row['ext_user_ids'] as $cid) $insDResp->execute([$newId, null, $cid, null]);
                    }
                    // Mark for sync. We pass only the row's direct
                    // user IDs here; the sync call later asks
                    // meetings_decision_target_user_ids() for the
                    // team-expanded set anyway.
                    if ($row['create_own']) $newDecisionIds[$newId] = true;
                    $inserted++;
                } elseif ($row['type'] === 'next_agenda') {
                    $nSort++;
                    $insNext->execute([$meetingId, $nSort, $row['head'], $row['desc'] === '' ? null : $row['desc']]);
                    $inserted++;
                }
            }
            $db->query('COMMIT');
            unset($_SESSION[$sessionKey]);

            // Fire Own-Tasks sync for each newly-created decision
            // outside the transaction so a sync failure doesn't
            // undo the committed decision rows. The target user list
            // honours team membership and fan-out automatically.
            foreach (array_keys($newDecisionIds) as $decId) {
                $uids = meetings_decision_target_user_ids((int) $decId);
                meetings_sync_decision_tasks((int) $decId, $uids, $viewerId);
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

// Re-validate the stored preview against the LIVE users / contacts
// tables on every render. After an admin adds a missing external user
// via the quick-add modal below, reloading the page clears the warning
// without re-uploading the file. Next-agenda rows have no names so are
// left as-is.
foreach ($preview as &$r) {
    if (($r['type'] ?? '') === 'next_agenda') continue;
    $riRaw = (string) ($r['raw_int'] ?? '');
    $reRaw = (string) ($r['raw_ext'] ?? '');
    $rtRaw = (string) ($r['raw_team'] ?? '');
    $ri = $resolveNames($riRaw, $users);
    $re = $resolveNames($reRaw, $contacts);
    $rt = $rtRaw !== '' ? $resolveNames($rtRaw, $teamsLookup) : ['ids' => [], 'warnings' => []];
    $r['int_user_ids'] = $ri['ids'];
    $r['ext_user_ids'] = $re['ids'];
    $r['team_ids']     = $rt['ids'];
    // Drop old name-not-found errors, re-add them from the live resolve.
    $r['errors'] = array_values(array_filter($r['errors'], static fn($e) => strpos($e, 'User not found') === false && strpos($e, 'Team not found') === false));
    if ($ri['warnings'] !== []) $r['errors'][] = 'Internal User not found: ' . implode('; ', $ri['warnings']);
    if ($re['warnings'] !== []) $r['errors'][] = 'External User not found: ' . implode('; ', $re['warnings']);
    if ($rt['warnings'] !== []) $r['errors'][] = 'Team not found: ' . implode('; ', $rt['warnings']);
}
unset($r);
$_SESSION[$sessionKey] = $preview;

// Collect the unique list of unmatched names so we can show add-row
// shortcuts in the UI below.
$missingInternal = []; $missingExternal = []; $missingTeams = [];
foreach ($preview as $r) {
    foreach (preg_split('/[,;]/', (string) ($r['raw_int'] ?? '')) as $n) {
        $n = trim($n);
        if ($n !== '' && !isset($users[strtolower($n)]) && !in_array($n, $missingInternal, true)) $missingInternal[] = $n;
    }
    foreach (preg_split('/[,;]/', (string) ($r['raw_ext'] ?? '')) as $n) {
        $n = trim($n);
        if ($n !== '' && !isset($contacts[strtolower($n)]) && !in_array($n, $missingExternal, true)) $missingExternal[] = $n;
    }
    foreach (preg_split('/[,;]/', (string) ($r['raw_team'] ?? '')) as $n) {
        $n = trim($n);
        if ($n !== '' && !isset($teamsLookup[strtolower($n)]) && !in_array($n, $missingTeams, true)) $missingTeams[] = $n;
    }
}

$errorRows = array_filter($preview, static fn($r) => $r['errors'] !== []);

render_header('Meetings · Bulk upload', ['main_container_class' => 'container-xl']);
render_page_header('Bulk upload minutes · ' . ($meeting['reference_no'] ?? ''), [
    'icon'     => 'bi-file-earmark-arrow-up',
    'subtitle' => 'Upload agenda items, decision points and next-meeting agenda for this meeting from an .xlsx file.',
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
        <div class="small text-muted">Expected header row: <code>Type · Heading/Title · Description · Due Date · Internal Users · External Users · Show in Own Tasks · Private Status</code> &mdash; and optionally <code>Teams · Fan out team tasks</code>. Type is one of <code>Agenda</code>, <code>Decision</code>, <code>Next Agenda</code>.</div>
        <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Preview</button>
    </div>
</form>

<?php if ($missingExternal !== [] || $missingInternal !== [] || $missingTeams !== []): ?>
<div class="card mb-3 border-warning">
    <div class="card-header bg-warning-subtle"><i class="bi bi-exclamation-triangle text-warning me-1"></i>Names in the file that do not match the database</div>
    <div class="card-body">
        <?php if ($missingExternal !== []): ?>
        <div class="mb-2 small text-muted">These External User names are not in the Contacts master. Click <strong>Add</strong> to create a contact with that name — then the row re-validates automatically.</div>
        <div class="d-flex flex-wrap gap-2 mb-3" id="missingExtChips">
            <?php foreach ($missingExternal as $n): ?>
                <span class="badge bg-light text-dark border d-inline-flex align-items-center p-2" data-name="<?= esc($n) ?>">
                    <i class="bi bi-person-x text-danger me-1"></i><?= esc($n) ?>
                    <button type="button" class="btn btn-sm btn-outline-primary ms-2 py-0 px-2 js-add-ext" data-name="<?= esc($n) ?>"><i class="bi bi-plus-lg"></i> Add</button>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($missingInternal !== []): ?>
        <div class="mb-2 small text-muted">These Internal User names are not in the Users master. An administrator needs to create them from the <a href="/users.php" target="_blank" rel="noopener">Users</a> page (reset their password after creating), then this preview will re-validate on reload.</div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($missingInternal as $n): ?>
                <span class="badge bg-light text-dark border p-2"><i class="bi bi-person-x text-danger me-1"></i><?= esc($n) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($missingTeams !== []): ?>
        <div class="mb-2 small text-muted">These Team names are not in the Teams master. An administrator can define them on the <a href="/teams.php" target="_blank" rel="noopener">Teams</a> page (pick a team head and members), then this preview will re-validate on reload.</div>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($missingTeams as $n): ?>
                <span class="badge bg-light text-dark border p-2"><i class="bi bi-people-fill text-danger me-1"></i><?= esc($n) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

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
                <tr><th>Row</th><th>Type</th><th>Heading / Title</th><th>Description</th><th>Due</th><th>Internal</th><th>External</th><th>Teams</th><th>Own</th><th>Private</th><th>Fan&nbsp;out</th><th>Errors</th></tr>
            </thead>
            <tbody>
                <?php foreach ($preview as $r):
                    $hasErr = $r['errors'] !== [];
                    $type   = (string) ($r['type'] ?? '');
                    $tone   = $type === 'decision' ? 'primary' : ($type === 'next_agenda' ? 'info' : 'secondary');
                    $label  = $type === 'next_agenda' ? 'Next Agenda' : ucfirst($type);
                    $isNext = $type === 'next_agenda';
                ?>
                    <tr class="<?= $hasErr ? 'table-warning' : '' ?>">
                        <td><?= (int) $r['row_no'] ?></td>
                        <td><span class="badge text-bg-<?= $tone ?>"><?= esc($label) ?></span></td>
                        <td><?= esc($r['head']) ?></td>
                        <td class="small text-muted"><?= esc(mb_substr((string) $r['desc'], 0, 120)) ?></td>
                        <td class="small"><?= $isNext ? '<span class="text-muted">—</span>' : esc((string) ($r['due_date'] ?? '')) ?></td>
                        <td class="small"><?= $isNext ? '<span class="text-muted">—</span>' : esc((string) ($r['raw_int'] ?? '')) ?></td>
                        <td class="small"><?= $isNext ? '<span class="text-muted">—</span>' : esc((string) ($r['raw_ext'] ?? '')) ?></td>
                        <td class="small"><?= $isNext ? '<span class="text-muted">—</span>' : esc((string) ($r['raw_team'] ?? '')) ?></td>
                        <td class="small"><?= $type === 'decision' ? ((int) $r['create_own'] === 1 ? 'Yes' : 'No') : '—' ?></td>
                        <td class="small"><?= $type === 'decision' ? ((int) $r['private'] === 1 ? 'Yes' : 'No') : '—' ?></td>
                        <td class="small"><?= $type === 'decision' ? ((int) ($r['fan_out'] ?? 0) === 1 ? 'Yes' : 'No') : '—' ?></td>
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

<!-- Quick-add External User (contact) modal -->
<div class="modal fade" id="quickAddExtModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-plus text-primary me-1"></i>Add External User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label small">Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qaxName">
        </div>
        <div class="mb-2">
          <label class="form-label small">Institution</label>
          <input type="text" class="form-control" id="qaxInstitution">
        </div>
        <div class="mb-2">
          <label class="form-label small">Designation</label>
          <input type="text" class="form-control" id="qaxDesignation">
        </div>
        <div class="row g-2">
          <div class="col">
            <label class="form-label small">Email</label>
            <input type="email" class="form-control" id="qaxEmail">
          </div>
          <div class="col">
            <label class="form-label small">Mobile</label>
            <input type="text" class="form-control" id="qaxMobile">
          </div>
        </div>
        <div class="small text-muted mt-2">After saving, this page reloads and the external-user names in the preview re-validate automatically.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="qaxSaveBtn"><i class="bi bi-save me-1"></i>Save contact</button>
      </div>
    </div>
  </div>
</div>

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
            const dt = new DataTransfer();
            dt.items.add(e.dataTransfer.files[0]);
            input.files = dt.files;
            show(input.files[0]);
        }
    });
})();

// Quick-add External User wiring. Opens the modal pre-filled with the
// clicked name; on save, POSTs to contacts_ajax.php and then reloads so
// the server-side re-validation kicks in and clears the warning.
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('quickAddExtModal');
    if (!modalEl || !window.bootstrap?.Modal) return;
    const modal = new bootstrap.Modal(modalEl);
    document.querySelectorAll('.js-add-ext').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('qaxName').value = this.getAttribute('data-name') || '';
            ['qaxInstitution','qaxDesignation','qaxEmail','qaxMobile'].forEach(id => { document.getElementById(id).value = ''; });
            modal.show();
            setTimeout(() => document.getElementById('qaxName').focus(), 150);
        });
    });
    document.getElementById('qaxSaveBtn')?.addEventListener('click', function () {
        const name = document.getElementById('qaxName').value.trim();
        if (name === '') { document.getElementById('qaxName').focus(); return; }
        const fd = new FormData();
        fd.append('action', 'create');
        fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
        fd.append('name', name);
        fd.append('institution', document.getElementById('qaxInstitution').value.trim());
        fd.append('designation', document.getElementById('qaxDesignation').value.trim());
        fd.append('email', document.getElementById('qaxEmail').value.trim());
        fd.append('mobile', document.getElementById('qaxMobile').value.trim());
        this.disabled = true; this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving&hellip;';
        fetch('/contacts_ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(j => {
                if (!j.ok) throw new Error(j.error || 'Save failed');
                location.reload();
            })
            .catch(e => {
                alert('Could not save contact: ' + e.message);
                this.disabled = false; this.innerHTML = '<i class="bi bi-save me-1"></i>Save contact';
            });
    });
});
</script>

<?php render_footer(); ?>

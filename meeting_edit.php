<?php
/**
 * Meeting — create / edit form.
 *
 * Rules:
 *   Anyone can create a meeting.
 *   Only the creator + admins (Meetings-module admin OR admin-group
 *   role) can edit an existing meeting.
 *
 * The form persists in one big transaction. Every sub-collection
 * (participants, agenda, decisions + responsibility, next agenda,
 * URL attachments) is rewritten wholesale from the submitted state.
 * That keeps the client contract simple (send the desired state,
 * not a diff) and avoids ID juggling for freshly-added rows.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/meetings_helpers.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
meetings_bootstrap();

$isAdminAll = is_manage_admin($viewer) || user_can_admin_module($viewerId, 'meetings');

$id = (int) ($_GET['id'] ?? 0);
$existing = null;
if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM meeting WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $existing = $stmt->fetch() ?: null;
    if ($existing !== null) {
        $isCreator = $viewerId === (int) $existing['created_by'];
        if (!$isCreator && !$isAdminAll) {
            http_response_code(403);
            render_header('Access denied');
            render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
            echo '<div class="alert alert-danger">Only the meeting creator or a Meetings admin can edit this row.</div>';
            render_footer(); exit;
        }
    } else { $id = 0; }
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashAndReturn = static function (string $msg, string $type, int $meetingId): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['meeting_view_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: ' . ($meetingId > 0 ? '/meeting_view.php?id=' . $meetingId : '/meetings.php'));
    exit;
};

// Reference data.
$divisions = meetings_divisions();
$users = db()->query('SELECT id, name FROM users WHERE active_status = 1 ORDER BY name ASC')->fetchAll();
$contacts = db()->query('SELECT id, name, institution FROM contact WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
try {
    $seats = db()->query("SELECT id, name, seat_number FROM office_hierarchy_nodes
        WHERE level_type = 'seat' AND active_status = 1 ORDER BY name ASC")->fetchAll();
} catch (Throwable $e) { $seats = []; }

// Preload existing rows for edit.
$existingParticipants = []; $existingAgenda = []; $existingDecisions = []; $existingNextAgenda = []; $existingUrls = [];
$existingDecisionResp = []; // decision_id => [ [user_id?, contact_id?, seat_id?], ... ]
$existingAgendaLeads  = []; // agenda_id => [ [user_id?, seat_id?, contact_id?], ... ]
if ($existing !== null) {
    $mid = (int) $existing['id'];
    try {
        $st = db()->prepare('SELECT * FROM meeting_participant WHERE meeting_id = ? ORDER BY id ASC'); $st->execute([$mid]);
        $existingParticipants = $st->fetchAll();
        $st = db()->prepare('SELECT * FROM meeting_agenda WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC'); $st->execute([$mid]);
        $existingAgenda = $st->fetchAll();
        $st = db()->prepare('SELECT * FROM meeting_decision WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC'); $st->execute([$mid]);
        $existingDecisions = $st->fetchAll();
        $st = db()->prepare('SELECT * FROM meeting_next_agenda WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC'); $st->execute([$mid]);
        $existingNextAgenda = $st->fetchAll();
        $st = db()->prepare('SELECT * FROM meeting_url WHERE meeting_id = ? ORDER BY id ASC'); $st->execute([$mid]);
        $existingUrls = $st->fetchAll();
        $ids = array_map(static fn($d) => (int) $d['id'], $existingDecisions);
        if ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare("SELECT * FROM meeting_decision_responsible WHERE decision_id IN ($ph)");
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) $existingDecisionResp[(int) $r['decision_id']][] = $r;
        }
        // Agenda leads (M:N).
        $aids = array_map(static fn($a) => (int) $a['id'], $existingAgenda);
        if ($aids !== []) {
            $ph = implode(',', array_fill(0, count($aids), '?'));
            $st = db()->prepare("SELECT * FROM meeting_agenda_lead WHERE agenda_id IN ($ph)");
            $st->execute($aids);
            foreach ($st->fetchAll() as $r) $existingAgendaLeads[(int) $r['agenda_id']][] = $r;
        }
    } catch (Throwable $e) { /* ignore */ }
}

if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();

    $title       = trim((string) ($_POST['title'] ?? ''));
    $purpose     = trim((string) ($_POST['purpose'] ?? ''));
    $mdate       = trim((string) ($_POST['meeting_date'] ?? ''));
    $ftime       = trim((string) ($_POST['from_time'] ?? ''));
    $ttime       = trim((string) ($_POST['to_time'] ?? ''));
    $location    = trim((string) ($_POST['location'] ?? ''));
    $vlink       = trim((string) ($_POST['virtual_link'] ?? ''));
    $chairUser   = (int) ($_POST['chair_user_id'] ?? 0);
    $chairContact= (int) ($_POST['chair_contact_id'] ?? 0);
    $divisionId  = (int) ($_POST['division_id'] ?? 0);
    $statusRaw   = trim((string) ($_POST['status'] ?? 'scheduled'));
    $minutesBody = trim((string) ($_POST['minutes_body'] ?? ''));
    $nextDate    = trim((string) ($_POST['next_meeting_date'] ?? ''));
    $nextTime    = trim((string) ($_POST['next_meeting_time'] ?? ''));
    $prevId      = (int) ($_POST['previous_meeting_id'] ?? 0);

    if ($title === '') $flashAndReturn('Title is required.', 'danger', $id);
    if ($mdate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $mdate)) $flashAndReturn('Valid meeting date is required.', 'danger', $id);
    if (!in_array($statusRaw, ['scheduled','inprogress','completed','cancelled'], true)) $statusRaw = 'scheduled';

    $db = db();
    $db->query('START TRANSACTION');
    try {
        if ($id === 0) {
            $ref = meetings_generate_reference($divisionId);
            $divCode = '';
            foreach ($divisions as $d) if ((int) $d['id'] === $divisionId) { $divCode = (string) ($d['code'] ?? $d['name']); break; }
            $db->prepare('INSERT INTO meeting
                (reference_no, title, purpose, meeting_date, from_time, to_time, location, virtual_link,
                 chair_user_id, chair_contact_id, division_id, division_code_snapshot, status,
                 minutes_body, next_meeting_date, next_meeting_time, previous_meeting_id, is_active,
                 created_at, updated_at, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), ?, ?)')
                ->execute([
                    $ref, $title, $purpose === '' ? null : $purpose, $mdate,
                    $ftime === '' ? null : $ftime, $ttime === '' ? null : $ttime,
                    $location === '' ? null : $location, $vlink === '' ? null : $vlink,
                    $chairUser  > 0 ? $chairUser  : null,
                    $chairContact > 0 ? $chairContact : null,
                    $divisionId > 0 ? $divisionId : null,
                    $divCode === '' ? null : $divCode,
                    $statusRaw,
                    $minutesBody === '' ? null : $minutesBody,
                    $nextDate === '' ? null : $nextDate,
                    $nextTime === '' ? null : $nextTime,
                    $prevId > 0 ? $prevId : null,
                    $viewerId, $viewerId,
                ]);
            $id = $db->lastInsertId();
        } else {
            $db->prepare('UPDATE meeting SET
                    title = ?, purpose = ?, meeting_date = ?, from_time = ?, to_time = ?, location = ?, virtual_link = ?,
                    chair_user_id = ?, chair_contact_id = ?, division_id = ?, status = ?, minutes_body = ?,
                    next_meeting_date = ?, next_meeting_time = ?, previous_meeting_id = ?,
                    updated_at = NOW(), updated_by = ?
                WHERE id = ?')
                ->execute([
                    $title, $purpose === '' ? null : $purpose, $mdate,
                    $ftime === '' ? null : $ftime, $ttime === '' ? null : $ttime,
                    $location === '' ? null : $location, $vlink === '' ? null : $vlink,
                    $chairUser  > 0 ? $chairUser  : null,
                    $chairContact > 0 ? $chairContact : null,
                    $divisionId > 0 ? $divisionId : null,
                    $statusRaw,
                    $minutesBody === '' ? null : $minutesBody,
                    $nextDate === '' ? null : $nextDate,
                    $nextTime === '' ? null : $nextTime,
                    $prevId > 0 ? $prevId : null,
                    $viewerId, $id,
                ]);
        }

        // Rewrite all sub-collections. Delete first, then re-insert
        // from the posted arrays.
        foreach (['meeting_participant', 'meeting_agenda', 'meeting_next_agenda', 'meeting_url'] as $t) {
            $db->prepare("DELETE FROM $t WHERE meeting_id = ?")->execute([$id]);
        }
        // Decisions carry a M:N table — clean both.
        $decIds = [];
        $st = $db->prepare('SELECT id FROM meeting_decision WHERE meeting_id = ?');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $r) $decIds[] = (int) $r['id'];
        if ($decIds !== []) {
            $ph = implode(',', array_fill(0, count($decIds), '?'));
            $db->prepare("DELETE FROM meeting_decision_responsible WHERE decision_id IN ($ph)")->execute($decIds);
        }
        $db->prepare('DELETE FROM meeting_decision WHERE meeting_id = ?')->execute([$id]);

        // Participants
        $insP = $db->prepare('INSERT INTO meeting_participant
            (meeting_id, user_id, contact_id, is_mandatory, attended, role_label, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())');
        foreach ((array) ($_POST['participant'] ?? []) as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $cid = (int) ($row['contact_id'] ?? 0);
            if ($uid <= 0 && $cid <= 0) continue;
            $mand = ((string) ($row['is_mandatory'] ?? '0')) === '1' ? 1 : 0;
            $att  = ($row['attended'] ?? '') === '1' ? 1 : (($row['attended'] ?? '') === '0' ? 0 : null);
            $insP->execute([$id, $uid > 0 ? $uid : null, $cid > 0 ? $cid : null, $mand, $att, trim((string) ($row['role_label'] ?? '')) ?: null]);
        }

        // Agenda
        $insA = $db->prepare('INSERT INTO meeting_agenda
            (meeting_id, sort_order, title, description, lead_user_id, lead_seat_id, lead_contact_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $order = 0;
        foreach ((array) ($_POST['agenda'] ?? []) as $row) {
            $t = trim((string) ($row['title'] ?? ''));
            if ($t === '') continue;
            $insA->execute([
                $id, ++$order, $t,
                trim((string) ($row['description'] ?? '')) ?: null,
                ((int) ($row['lead_user_id'] ?? 0)) ?: null,
                ((int) ($row['lead_seat_id'] ?? 0)) ?: null,
                ((int) ($row['lead_contact_id'] ?? 0)) ?: null,
            ]);
        }

        // Decisions + M:N responsibility
        $insD = $db->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date) VALUES (?, ?, ?, ?, ?)');
        $insR = $db->prepare('INSERT INTO meeting_decision_responsible (decision_id, user_id, contact_id, seat_id) VALUES (?, ?, ?, ?)');
        $order = 0;
        foreach ((array) ($_POST['decision'] ?? []) as $row) {
            $h = trim((string) ($row['heading'] ?? ''));
            if ($h === '') continue;
            $due = trim((string) ($row['due_date'] ?? ''));
            $insD->execute([
                $id, ++$order, $h,
                trim((string) ($row['description'] ?? '')) ?: null,
                ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) ? $due : null,
            ]);
            $newDecId = $db->lastInsertId();
            foreach ((array) ($row['user_ids'] ?? []) as $x) if ((int) $x > 0) $insR->execute([$newDecId, (int) $x, null, null]);
            foreach ((array) ($row['seat_ids'] ?? []) as $x) if ((int) $x > 0) $insR->execute([$newDecId, null, null, (int) $x]);
            foreach ((array) ($row['contact_ids'] ?? []) as $x) if ((int) $x > 0) $insR->execute([$newDecId, null, (int) $x, null]);
        }

        // Next-meeting agenda
        $insN = $db->prepare('INSERT INTO meeting_next_agenda (meeting_id, sort_order, title, description) VALUES (?, ?, ?, ?)');
        $order = 0;
        foreach ((array) ($_POST['next_agenda'] ?? []) as $row) {
            $t = trim((string) ($row['title'] ?? ''));
            if ($t === '') continue;
            $insN->execute([$id, ++$order, $t, trim((string) ($row['description'] ?? '')) ?: null]);
        }

        // URL attachments
        $insU = $db->prepare('INSERT INTO meeting_url (meeting_id, label, url) VALUES (?, ?, ?)');
        foreach ((array) ($_POST['url'] ?? []) as $row) {
            $u = trim((string) ($row['url'] ?? ''));
            if ($u === '') continue;
            $insU->execute([$id, trim((string) ($row['label'] ?? '')) ?: null, $u]);
        }

        $db->query('COMMIT');
        $flashAndReturn('Meeting saved.', 'success', $id);
    } catch (Throwable $e) {
        try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
        $flashAndReturn('Save failed: ' . $e->getMessage(), 'danger', $id);
    }
}

$formValues = $existing ?: [
    'title' => '', 'purpose' => '', 'meeting_date' => date('Y-m-d'),
    'from_time' => '', 'to_time' => '', 'location' => '', 'virtual_link' => '',
    'chair_user_id' => 0, 'chair_contact_id' => 0, 'division_id' => 0,
    'status' => 'scheduled', 'minutes_body' => '',
    'next_meeting_date' => '', 'next_meeting_time' => '',
    'previous_meeting_id' => 0,
];

$pageTitle = $existing ? 'Edit meeting · ' . $existing['reference_no'] : 'New meeting';
render_header('Meetings · ' . $pageTitle, ['main_container_class' => 'container-xl']);
render_page_header($pageTitle, [
    'icon' => 'bi-calendar2-week',
    'subtitle' => $existing ? 'Edit fields, participants, agenda, decisions. Saves in one transaction.' : 'Create a new meeting. A reference number is allocated automatically from the division.',
    'actions' => '<a class="btn btn-light" href="/meetings.php"><i class="bi bi-arrow-left me-1"></i>Back to Meetings</a>',
]);
?>

<form method="post" class="card">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="save">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Title <span class="text-danger">*</span></label>
                <input class="form-control" name="title" value="<?= esc((string) $formValues['title']) ?>" required maxlength="300">
            </div>
            <div class="col-md-4">
                <label class="form-label">Division (for reference number)</label>
                <select class="form-select" name="division_id" <?= $existing ? 'disabled' : '' ?>>
                    <option value="">— Select division —</option>
                    <?php foreach ($divisions as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= (int) $formValues['division_id'] === (int) $d['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $d['name']) ?><?= !empty($d['code']) ? ' (' . esc((string) $d['code']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($existing): ?><input type="hidden" name="division_id" value="<?= (int) $formValues['division_id'] ?>"><?php endif; ?>
                <div class="small text-muted mt-1"><?= $existing ? 'Reference: <code>' . esc((string) $existing['reference_no']) . '</code>' : 'Reference is allocated on save.' ?></div>
            </div>

            <div class="col-md-3">
                <label class="form-label">Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="meeting_date" value="<?= esc((string) $formValues['meeting_date']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">From time</label>
                <input type="time" class="form-control" name="from_time" value="<?= esc(substr((string) $formValues['from_time'], 0, 5)) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">To time</label>
                <input type="time" class="form-control" name="to_time" value="<?= esc(substr((string) $formValues['to_time'], 0, 5)) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <?php foreach (['scheduled' => 'Scheduled', 'inprogress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $k => $v): ?>
                        <option value="<?= esc($k) ?>" <?= (string) $formValues['status'] === $k ? 'selected' : '' ?>><?= esc($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Location (physical)</label>
                <input class="form-control" name="location" value="<?= esc((string) $formValues['location']) ?>" maxlength="300" placeholder="e.g. Conference Hall, Ground Floor">
            </div>
            <div class="col-md-6">
                <label class="form-label">Virtual meeting link</label>
                <input class="form-control" name="virtual_link" value="<?= esc((string) $formValues['virtual_link']) ?>" maxlength="500" placeholder="https://meet.google.com/… (optional; for hybrid)">
            </div>

            <div class="col-md-6">
                <label class="form-label">Chairperson (Internal User)</label>
                <select class="form-select ts-search" name="chair_user_id" id="chairUserSelect">
                    <option value="0">— None (or use contact) —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) $formValues['chair_user_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= esc((string) $u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label d-flex justify-content-between align-items-center">
                    <span>Chairperson (External User)</span>
                    <span class="d-inline-flex align-items-center gap-2">
                        <a href="#" class="small" id="chairNewContactBtn"><i class="bi bi-plus-lg"></i> New External User</a>
                        <button type="button" class="btn btn-sm btn-link p-0" id="chairRefreshBtn" title="Refresh External User list"><i class="bi bi-arrow-clockwise"></i></button>
                    </span>
                </label>
                <select class="form-select ts-search" name="chair_contact_id" id="chairContactSelect">
                    <option value="0">— None —</option>
                    <?php foreach ($contacts as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) $formValues['chair_contact_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= esc((string) $c['name']) ?><?= !empty($c['institution']) ? ' · ' . esc((string) $c['institution']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-12">
                <label class="form-label">Previous meeting <span class="small text-muted">(optional — link to earlier record in the series)</span></label>
                <select class="form-select ts-search" name="previous_meeting_id" id="prevMeetingSelect">
                    <option value="0">— None —</option>
                    <?php
                    try {
                        $prev = db()->query('SELECT id, reference_no, title FROM meeting WHERE is_active = 1 ORDER BY meeting_date DESC LIMIT 200')->fetchAll();
                    } catch (Throwable $e) { $prev = []; }
                    foreach ($prev as $p):
                        if ($existing && (int) $p['id'] === (int) $existing['id']) continue; // don't allow self-link
                    ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $formValues['previous_meeting_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $p['reference_no']) ?> · <?= esc((string) $p['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Purpose</label>
                <textarea class="form-control" name="purpose" rows="3"><?= esc((string) $formValues['purpose']) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Minutes body (free text)</label>
                <textarea class="form-control" name="minutes_body" rows="3"><?= esc((string) $formValues['minutes_body']) ?></textarea>
            </div>
        </div>

        <hr class="my-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="text-uppercase small text-muted mb-0"><i class="bi bi-people me-1"></i>Participants</h6>
            <button type="button" class="btn btn-sm btn-outline-primary" data-open-modal="participantModal"><i class="bi bi-plus-lg me-1"></i>Add participant</button>
        </div>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" id="participantTable">
            <thead><tr><th>Name</th><th>Type</th><th>Attendance</th><th>Role</th><th class="text-end">Action</th></tr></thead>
            <tbody></tbody>
        </table></div>
        <div id="participantHidden"></div>

        <hr class="my-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="text-uppercase small text-muted mb-0"><i class="bi bi-list-ol me-1"></i>Agenda</h6>
            <button type="button" class="btn btn-sm btn-outline-primary" data-open-modal="agendaModal"><i class="bi bi-plus-lg me-1"></i>Add agenda item</button>
        </div>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" id="agendaTable">
            <thead><tr><th style="width:4%;">#</th><th>Title</th><th>Description</th><th>Lead</th><th class="text-end">Action</th></tr></thead>
            <tbody></tbody>
        </table></div>
        <div id="agendaHidden"></div>

        <hr class="my-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="text-uppercase small text-muted mb-0"><i class="bi bi-check2-square me-1"></i>Decision points</h6>
            <button type="button" class="btn btn-sm btn-outline-primary" data-open-modal="decisionModal"><i class="bi bi-plus-lg me-1"></i>Add decision</button>
        </div>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" id="decisionTable">
            <thead><tr><th style="width:4%;">#</th><th>Heading</th><th>Description</th><th>Due</th><th>Responsible</th><th class="text-end">Action</th></tr></thead>
            <tbody></tbody>
        </table></div>
        <div id="decisionHidden"></div>

        <hr class="my-4">
        <h6 class="text-uppercase small text-muted mb-3"><i class="bi bi-calendar-plus me-1"></i>Next meeting <span class="small text-muted">(optional)</span></h6>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Next meeting date</label>
                <input type="date" class="form-control" name="next_meeting_date" value="<?= esc((string) $formValues['next_meeting_date']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Next meeting time</label>
                <input type="time" class="form-control" name="next_meeting_time" value="<?= esc(substr((string) $formValues['next_meeting_time'], 0, 5)) ?>">
            </div>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-2 mt-3">
            <span class="small fw-semibold">Next-meeting agenda</span>
            <button type="button" class="btn btn-sm btn-outline-primary" data-open-modal="nextAgendaModal"><i class="bi bi-plus-lg me-1"></i>Add next-meeting agenda</button>
        </div>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" id="nextAgendaTable">
            <thead><tr><th style="width:4%;">#</th><th>Title</th><th>Description</th><th class="text-end">Action</th></tr></thead>
            <tbody></tbody>
        </table></div>
        <div id="nextAgendaHidden"></div>

        <hr class="my-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="text-uppercase small text-muted mb-0"><i class="bi bi-link-45deg me-1"></i>Attachment URLs <span class="small text-muted">(Google Drive / SharePoint / any)</span></h6>
            <button type="button" class="btn btn-sm btn-outline-primary" data-open-modal="urlModal"><i class="bi bi-plus-lg me-1"></i>Add URL</button>
        </div>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" id="urlTable">
            <thead><tr><th>Label</th><th>URL</th><th class="text-end">Action</th></tr></thead>
            <tbody></tbody>
        </table></div>
        <div id="urlHidden"></div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a class="btn btn-light" href="<?= $existing ? '/meeting_view.php?id=' . (int) $existing['id'] : '/meetings.php' ?>">Cancel</a>
        <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Save meeting</button>
    </div>
</form>

<script>
window.__meetingRef = {
    users:    <?= json_encode(array_map(static fn($u) => ['id' => (int) $u['id'], 'name' => (string) $u['name']], $users), JSON_UNESCAPED_UNICODE) ?>,
    contacts: <?= json_encode(array_map(static fn($c) => ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'inst' => (string) ($c['institution'] ?? '')], $contacts), JSON_UNESCAPED_UNICODE) ?>,
    seats:    <?= json_encode(array_map(static fn($s) => ['id' => (int) $s['id'], 'name' => (string) $s['name'] . (empty($s['seat_number']) ? '' : ' (' . $s['seat_number'] . ')')], $seats), JSON_UNESCAPED_UNICODE) ?>,
    meeting_id: <?= (int) ($existing['id'] ?? 0) ?>,
    preset: {
        participants: <?= json_encode(array_map(static fn($p) => [
            'id' => (int) $p['id'],
            'user_id' => (int) ($p['user_id'] ?? 0),
            'contact_id' => (int) ($p['contact_id'] ?? 0),
            'is_mandatory' => (int) $p['is_mandatory'],
            'attended' => $p['attended'] === null ? '' : (string) $p['attended'],
            'role_label' => (string) ($p['role_label'] ?? ''),
        ], $existingParticipants)) ?>,
        agenda: <?= json_encode(array_map(static function ($a) use ($existingAgendaLeads) {
            $leads = $existingAgendaLeads[(int) $a['id']] ?? [];
            $uIds = array_values(array_filter(array_map(static fn($r) => (int) ($r['user_id']    ?? 0), $leads)));
            $sIds = array_values(array_filter(array_map(static fn($r) => (int) ($r['seat_id']    ?? 0), $leads)));
            $cIds = array_values(array_filter(array_map(static fn($r) => (int) ($r['contact_id'] ?? 0), $leads)));
            // Back-fill from legacy single-id columns for rows that
            // predate the meeting_agenda_lead table.
            if ($uIds === [] && (int) ($a['lead_user_id']    ?? 0) > 0) $uIds[] = (int) $a['lead_user_id'];
            if ($sIds === [] && (int) ($a['lead_seat_id']    ?? 0) > 0) $sIds[] = (int) $a['lead_seat_id'];
            if ($cIds === [] && (int) ($a['lead_contact_id'] ?? 0) > 0) $cIds[] = (int) $a['lead_contact_id'];
            return [
                'id' => (int) $a['id'],
                'title' => (string) $a['title'],
                'description' => (string) ($a['description'] ?? ''),
                'user_ids' => $uIds, 'seat_ids' => $sIds, 'contact_ids' => $cIds,
            ];
        }, $existingAgenda)) ?>,
        decisions: <?= json_encode(array_map(static function ($d) use ($existingDecisionResp) {
            $rs = $existingDecisionResp[(int) $d['id']] ?? [];
            return [
                'id' => (int) $d['id'],
                'heading' => (string) $d['heading'],
                'description' => (string) ($d['description'] ?? ''),
                'due_date' => substr((string) ($d['due_date'] ?? ''), 0, 10),
                'user_ids' => array_values(array_filter(array_map(static fn($r) => (int) ($r['user_id'] ?? 0), $rs))),
                'seat_ids' => array_values(array_filter(array_map(static fn($r) => (int) ($r['seat_id'] ?? 0), $rs))),
                'contact_ids' => array_values(array_filter(array_map(static fn($r) => (int) ($r['contact_id'] ?? 0), $rs))),
            ];
        }, $existingDecisions)) ?>,
        next_agenda: <?= json_encode(array_map(static fn($n) => ['id' => (int) $n['id'], 'title' => (string) $n['title'], 'description' => (string) ($n['description'] ?? '')], $existingNextAgenda)) ?>,
        urls: <?= json_encode(array_map(static fn($u) => ['id' => (int) $u['id'], 'label' => (string) ($u['label'] ?? ''), 'url' => (string) $u['url']], $existingUrls)) ?>,
    },
};
</script>
<!-- =============== Modal templates =============== -->
<div class="mm-backdrop" id="mmBackdrop" style="display:none;"></div>

<!-- Participant modal — two side-by-side checkbox lists with search, +
     one shared attendance/role row across every ticked person. -->
<div class="mm-modal" id="participantModal" style="display:none; width: min(900px, 94vw);">
    <div class="mm-header"><span><i class="bi bi-people me-1"></i>Participants</span><button type="button" class="btn-close" data-close-modal></button></div>
    <div class="mm-body">
        <input type="hidden" id="pModalIdx" value="">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label small fw-semibold"><i class="bi bi-person-badge me-1"></i>Internal Users (from seats)</label>
                <input type="text" class="form-control form-control-sm mb-2" id="pSearchUsers" placeholder="Search internal users…">
                <div class="pm-check-list" id="pListUsers"></div>
                <div class="small text-muted mt-1" id="pListUsersCount"></div>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-semibold"><i class="bi bi-person-vcard me-1"></i>External Users</label>
                <input type="text" class="form-control form-control-sm mb-2" id="pSearchContacts" placeholder="Search external users…">
                <div class="pm-check-list" id="pListContacts"></div>
                <div class="small text-muted mt-1" id="pListContactsCount"></div>
            </div>
            <div class="col-12">
                <hr class="my-2">
                <div class="small text-muted mb-2">The row below applies to <strong>every</strong> ticked person.</div>
                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label small">Attendance type</label>
                        <select class="form-select" id="pModalMand"><option value="1">Mandatory</option><option value="0">Optional</option></select></div>
                    <div class="col-md-4"><label class="form-label small">Present?</label>
                        <select class="form-select" id="pModalAtt"><option value="">— not yet —</option><option value="1">Present</option><option value="0">Absent</option></select></div>
                    <div class="col-md-4"><label class="form-label small">Role</label>
                        <input class="form-control" id="pModalRole" placeholder="e.g. Guest"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="mm-footer"><button type="button" class="btn btn-light btn-sm" data-close-modal>Cancel</button><button type="button" class="btn btn-primary btn-sm" id="pModalSave"><i class="bi bi-check2 me-1"></i>Save selected</button></div>
</div>
<style>
.pm-check-list { height: 240px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 6px; padding: 6px 10px; }
.pm-check-list .pm-row { display: flex; align-items: center; gap: 6px; padding: 3px 0; }
.pm-check-list .pm-row label { margin: 0; cursor: pointer; line-height: 1.3; }
.pm-check-list .pm-row:hover { background: #f8fafc; }
.pm-check-list .pm-empty { color: #94a3b8; text-align: center; padding: 20px 0; font-size: .85rem; }
</style>

<!-- Agenda modal — three checkbox lists for leads (multi-select). -->
<div class="mm-modal" id="agendaModal" style="display:none; width: min(960px, 94vw);">
    <div class="mm-header"><span><i class="bi bi-list-ol me-1"></i>Agenda item</span><button type="button" class="btn-close" data-close-modal></button></div>
    <div class="mm-body">
        <input type="hidden" id="aModalIdx" value="">
        <div class="mb-2"><label class="form-label small">Title *</label><input class="form-control" id="aModalTitle"></div>
        <div class="mb-3"><label class="form-label small">Description</label><textarea class="form-control" id="aModalDesc" rows="2"></textarea></div>
        <div class="small text-muted mb-2">Leads are usually drawn from the meeting's participant list. Tick anyone from the Participants column; the other two columns let you add someone who isn't on the list yet.</div>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label small fw-semibold"><i class="bi bi-people me-1"></i>Participants <span class="text-muted small">(this meeting)</span></label>
                <input type="text" class="form-control form-control-sm mb-2" id="aSearchParts" placeholder="Search participants…">
                <div class="pm-check-list" id="aListParts"></div>
                <div class="small text-muted mt-1" id="aListPartsCount"></div>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold"><i class="bi bi-person-badge me-1"></i>Other Internal Users</label>
                <input type="text" class="form-control form-control-sm mb-2" id="aSearchUsers" placeholder="Search internal users…">
                <div class="pm-check-list" id="aListUsers"></div>
                <div class="small text-muted mt-1" id="aListUsersCount"></div>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold"><i class="bi bi-person-vcard me-1"></i>Other External Users</label>
                <input type="text" class="form-control form-control-sm mb-2" id="aSearchContacts" placeholder="Search external users…">
                <div class="pm-check-list" id="aListContacts"></div>
                <div class="small text-muted mt-1" id="aListContactsCount"></div>
            </div>
        </div>
    </div>
    <div class="mm-footer"><button type="button" class="btn btn-light btn-sm" data-close-modal>Cancel</button><button type="button" class="btn btn-primary btn-sm" id="aModalSave"><i class="bi bi-check2 me-1"></i>Save</button></div>
</div>

<!-- Decision modal -->
<div class="mm-modal" id="decisionModal" style="display:none; width: min(960px, 94vw);">
    <div class="mm-header"><span><i class="bi bi-check2-square me-1"></i>Decision point</span><button type="button" class="btn-close" data-close-modal></button></div>
    <div class="mm-body">
        <input type="hidden" id="dModalIdx" value="">
        <div class="mb-2"><label class="form-label small">Heading *</label><input class="form-control" id="dModalHead"></div>
        <div class="mb-2"><label class="form-label small">Description</label><textarea class="form-control" id="dModalDesc" rows="3"></textarea></div>
        <div class="mb-3"><label class="form-label small">Due date</label><input type="date" class="form-control" id="dModalDue"></div>
        <div class="small text-muted mb-2">Pick the people responsible. Any Internal User you tick who holds a seat gets an auto-generated task in <strong>Own Tasks</strong> so they can track status.</div>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label small fw-semibold"><i class="bi bi-people me-1"></i>Participants <span class="text-muted small">(this meeting)</span></label>
                <input type="text" class="form-control form-control-sm mb-2" id="dSearchParts" placeholder="Search participants…">
                <div class="pm-check-list" id="dListParts"></div>
                <div class="small text-muted mt-1" id="dListPartsCount"></div>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold"><i class="bi bi-person-badge me-1"></i>Other Internal Users</label>
                <input type="text" class="form-control form-control-sm mb-2" id="dSearchUsers" placeholder="Search internal users…">
                <div class="pm-check-list" id="dListUsers"></div>
                <div class="small text-muted mt-1" id="dListUsersCount"></div>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold"><i class="bi bi-person-vcard me-1"></i>Other External Users</label>
                <input type="text" class="form-control form-control-sm mb-2" id="dSearchContacts" placeholder="Search external users…">
                <div class="pm-check-list" id="dListContacts"></div>
                <div class="small text-muted mt-1" id="dListContactsCount"></div>
            </div>
        </div>
    </div>
    <div class="mm-footer"><button type="button" class="btn btn-light btn-sm" data-close-modal>Cancel</button><button type="button" class="btn btn-primary btn-sm" id="dModalSave"><i class="bi bi-check2 me-1"></i>Save</button></div>
</div>

<!-- Next-agenda modal -->
<div class="mm-modal" id="nextAgendaModal" style="display:none;">
    <div class="mm-header"><span><i class="bi bi-calendar-plus me-1"></i>Next-meeting agenda</span><button type="button" class="btn-close" data-close-modal></button></div>
    <div class="mm-body">
        <input type="hidden" id="nModalIdx" value="">
        <div class="mb-2"><label class="form-label small">Title *</label><input class="form-control" id="nModalTitle"></div>
        <div class="mb-2"><label class="form-label small">Description</label><textarea class="form-control" id="nModalDesc" rows="2"></textarea></div>
    </div>
    <div class="mm-footer"><button type="button" class="btn btn-light btn-sm" data-close-modal>Cancel</button><button type="button" class="btn btn-primary btn-sm" id="nModalSave"><i class="bi bi-check2 me-1"></i>Save</button></div>
</div>

<!-- URL modal -->
<div class="mm-modal" id="urlModal" style="display:none;">
    <div class="mm-header"><span><i class="bi bi-link-45deg me-1"></i>Attachment URL</span><button type="button" class="btn-close" data-close-modal></button></div>
    <div class="mm-body">
        <input type="hidden" id="uModalIdx" value="">
        <div class="mb-2"><label class="form-label small">Label</label><input class="form-control" id="uModalLabel" placeholder="Optional"></div>
        <div class="mb-2"><label class="form-label small">URL *</label><input type="url" class="form-control" id="uModalUrl" placeholder="https://…"></div>
    </div>
    <div class="mm-footer"><button type="button" class="btn btn-light btn-sm" data-close-modal>Cancel</button><button type="button" class="btn btn-primary btn-sm" id="uModalSave"><i class="bi bi-check2 me-1"></i>Save</button></div>
</div>

<!-- New External User quick-add modal -->
<div class="mm-modal" id="newContactModal" style="display:none;">
    <div class="mm-header"><span><i class="bi bi-person-plus me-1"></i>New External User</span><button type="button" class="btn-close" data-close-modal></button></div>
    <div class="mm-body">
        <div class="row g-2">
            <div class="col-md-6"><label class="form-label small">Name *</label><input class="form-control" id="ncName"></div>
            <div class="col-md-6"><label class="form-label small">Institution</label><input class="form-control" id="ncInst"></div>
            <div class="col-md-6"><label class="form-label small">Designation</label><input class="form-control" id="ncDes"></div>
            <div class="col-md-3"><label class="form-label small">Mobile</label><input class="form-control" id="ncMob"></div>
            <div class="col-md-3"><label class="form-label small">Email</label><input type="email" class="form-control" id="ncEm"></div>
        </div>
    </div>
    <div class="mm-footer"><button type="button" class="btn btn-light btn-sm" data-close-modal>Cancel</button><button type="button" class="btn btn-primary btn-sm" id="ncSave"><i class="bi bi-check2 me-1"></i>Save External User</button></div>
</div>

<style>
.mm-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.35); z-index: 1080; }
.mm-modal   { position: fixed; top: 15vh; left: 50%; transform: translateX(-50%); width: min(560px, 92vw); background: #fff; border-radius: 8px; box-shadow: 0 20px 40px rgba(0,0,0,.2); z-index: 1090; }
.mm-header  { padding: 10px 14px; border-bottom: 1px solid #e3e6ee; cursor: move; display: flex; justify-content: space-between; align-items: center; font-weight: 600; user-select: none; }
.mm-body    { padding: 14px; max-height: 62vh; overflow-y: auto; }
.mm-footer  { padding: 10px 14px; border-top: 1px solid #e3e6ee; display: flex; justify-content: flex-end; gap: 6px; }
.mm-modal.mm-dragging { transition: none; }
</style>

<script>
(function () {
    const R = window.__meetingRef;

    // ============ STATE ARRAYS ============
    const state = {
        participants: (R.preset.participants || []).slice(),
        agenda:       (R.preset.agenda || []).slice(),
        decisions:    (R.preset.decisions || []).slice(),
        next_agenda:  (R.preset.next_agenda || []).slice(),
        urls:         (R.preset.urls || []).slice(),
    };
    let contactsPool = R.contacts.slice(); // mutable — quick-add + refresh
    let meetingId    = Number(R.meeting_id || 0);
    // ------- Auto-save wiring -------
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    const csrf = csrfInput ? csrfInput.value : '';
    const statusPill = (() => {
        const el = document.createElement('span');
        el.id = 'saveStatus';
        el.className = 'badge text-bg-light border ms-2';
        el.style.minWidth = '90px'; el.style.display = 'inline-block'; el.style.textAlign = 'center';
        el.textContent = meetingId > 0 ? 'Saved' : 'Draft (not yet saved)';
        const submitBtn = document.querySelector('button[type=submit]');
        if (submitBtn) submitBtn.parentNode.insertBefore(el, submitBtn);
        return el;
    })();
    const setStatus = (text, tone) => {
        statusPill.textContent = text;
        statusPill.className = 'badge text-bg-' + (tone || 'light') + ' border ms-2';
        statusPill.style.minWidth = '90px'; statusPill.style.display = 'inline-block'; statusPill.style.textAlign = 'center';
    };
    const ajax = async (params) => {
        params.csrf_token = csrf;
        const body = new URLSearchParams();
        Object.entries(params).forEach(([k, v]) => {
            if (Array.isArray(v)) v.forEach(x => body.append(k, x));
            else body.set(k, v == null ? '' : String(v));
        });
        const res = await fetch('/meeting_ajax.php', { method: 'POST', body });
        return await res.json();
    };
    const ensureDraft = async () => {
        if (meetingId > 0) return meetingId;
        setStatus('Creating draft…', 'info');
        const divSel = document.querySelector('select[name="division_id"]');
        const j = await ajax({ action: 'create_draft', division_id: divSel ? divSel.value : 0 });
        if (!j.ok) { setStatus('Draft create failed', 'danger'); throw new Error(j.error || 'draft failed'); }
        meetingId = j.meeting_id;
        // Reflect in the URL so a refresh keeps the same draft; also
        // unlock the division dropdown (creation resolves the reference
        // number, editing the division post-creation is not supported).
        try { history.replaceState(null, '', '/meeting_edit.php?id=' + meetingId); } catch (e) {}
        setStatus('Draft saved', 'success');
        return meetingId;
    };
    // Debounced field save.
    const fieldDebounce = new Map();
    const saveField = (field, value) => {
        if (fieldDebounce.has(field)) clearTimeout(fieldDebounce.get(field));
        setStatus('Saving…', 'info');
        fieldDebounce.set(field, setTimeout(async () => {
            try {
                await ensureDraft();
                const j = await ajax({ action: 'save_field', meeting_id: meetingId, field, value });
                setStatus(j.ok ? 'Saved' : ('Save error: ' + (j.error || '')), j.ok ? 'success' : 'danger');
            } catch (e) { setStatus('Save error', 'danger'); }
        }, 700));
    };
    // Auto-save on every named field in the main form.
    document.querySelectorAll('form input[name], form select[name], form textarea[name]')
        .forEach(el => {
            if (el.name === 'csrf_token' || el.name === 'action' || el.name === 'id') return;
            if (el.name.startsWith('participant[') || el.name.startsWith('agenda[') || el.name.startsWith('decision[') || el.name.startsWith('next_agenda[') || el.name.startsWith('url[')) return;
            // Every field autosaves on change; text inputs also on input (debounced).
            const eventName = (el.tagName === 'TEXTAREA' || (el.type && ['text','email','url','number','search'].includes(el.type))) ? 'input' : 'change';
            el.addEventListener(eventName, () => saveField(el.name, el.value));
        });

    const esc = (s) => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const findById = (list, id) => list.find(x => String(x.id) === String(id));
    const nameOf = (list, id) => { const x = findById(list, id); return x ? (x.name + (x.inst ? ' · ' + x.inst : '')) : ''; };
    const optionsFor = (list, selId, includeNone=true) => {
        let h = includeNone ? '<option value="0">— None —</option>' : '';
        list.forEach(x => { h += `<option value="${x.id}" ${String(x.id) === String(selId ?? '') ? 'selected' : ''}>${esc(x.name + (x.inst ? ' · ' + x.inst : ''))}</option>`; });
        return h;
    };
    const multiOptionsFor = (list, sel) => {
        const set = new Set((sel || []).map(String));
        return list.map(x => `<option value="${x.id}" ${set.has(String(x.id)) ? 'selected' : ''}>${esc(x.name + (x.inst ? ' · ' + x.inst : ''))}</option>`).join('');
    };

    // ============ MOVABLE MODAL HELPERS ============
    const backdrop = document.getElementById('mmBackdrop');
    const openModal = (id) => {
        // Fresh position each open so a dragged spot doesn't stick.
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.style.top = '15vh'; modal.style.left = '50%'; modal.style.transform = 'translateX(-50%)';
        backdrop.style.display = 'block'; modal.style.display = 'block';
    };
    const closeAllModals = () => {
        backdrop.style.display = 'none';
        document.querySelectorAll('.mm-modal').forEach(m => m.style.display = 'none');
    };
    document.addEventListener('click', (ev) => {
        const openBtn = ev.target.closest('[data-open-modal]');
        if (openBtn) { ev.preventDefault(); openModal(openBtn.getAttribute('data-open-modal')); return; }
        if (ev.target.closest('[data-close-modal]')) { closeAllModals(); return; }
        if (ev.target === backdrop) closeAllModals();
    });
    // Drag by header
    document.querySelectorAll('.mm-modal').forEach(modal => {
        const header = modal.querySelector('.mm-header');
        if (!header) return;
        let dragging = false, startX = 0, startY = 0, origX = 0, origY = 0;
        header.addEventListener('mousedown', (e) => {
            if (e.target.closest('button')) return; // don't drag when clicking close
            dragging = true;
            modal.classList.add('mm-dragging');
            modal.style.transform = 'none';
            const rect = modal.getBoundingClientRect();
            origX = rect.left; origY = rect.top;
            modal.style.left = origX + 'px'; modal.style.top = origY + 'px';
            startX = e.clientX; startY = e.clientY;
            e.preventDefault();
        });
        document.addEventListener('mousemove', (e) => {
            if (!dragging) return;
            modal.style.left = (origX + e.clientX - startX) + 'px';
            modal.style.top  = (origY + e.clientY - startY) + 'px';
        });
        document.addEventListener('mouseup', () => { dragging = false; modal.classList.remove('mm-dragging'); });
    });

    // ============ TABLE RENDERERS ============
    const renderParticipants = () => {
        const tbody = document.querySelector('#participantTable tbody');
        tbody.innerHTML = state.participants.map((p, i) => {
            const name = p.user_id ? nameOf(R.users, p.user_id) : (p.contact_id ? nameOf(contactsPool, p.contact_id) : '—');
            const type = String(p.is_mandatory) === '1' ? '<span class="badge text-bg-danger">Mandatory</span>' : '<span class="badge text-bg-secondary">Optional</span>';
            const att = p.attended === '' || p.attended === undefined || p.attended === null ? '<span class="text-muted">not recorded</span>' : (String(p.attended) === '1' ? '<span class="badge text-bg-success">Present</span>' : '<span class="badge text-bg-secondary">Absent</span>');
            return `<tr><td>${esc(name)}</td><td>${type}</td><td>${att}</td><td class="small">${esc(p.role_label || '')}</td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-edit-participant="${i}"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger"  data-del-participant="${i}"><i class="bi bi-x"></i></button>
                </td></tr>`;
        }).join('') || '<tr><td colspan="5" class="text-center text-muted small py-2">No participants yet — click Add participant.</td></tr>';
        const hidden = document.getElementById('participantHidden');
        hidden.innerHTML = state.participants.map((p, i) => {
            return `<input type="hidden" name="participant[${i}][user_id]"      value="${p.user_id || 0}">
                    <input type="hidden" name="participant[${i}][contact_id]"   value="${p.contact_id || 0}">
                    <input type="hidden" name="participant[${i}][is_mandatory]" value="${p.is_mandatory ?? 1}">
                    <input type="hidden" name="participant[${i}][attended]"     value="${p.attended ?? ''}">
                    <input type="hidden" name="participant[${i}][role_label]"   value="${esc(p.role_label || '')}">`;
        }).join('');
    };
    const renderAgenda = () => {
        const tbody = document.querySelector('#agendaTable tbody');
        tbody.innerHTML = state.agenda.map((a, i) => {
            const leads = [
                ...((a.user_ids    || []).map(id => nameOf(R.users,       id))),
                ...((a.seat_ids    || []).map(id => nameOf(R.seats,       id))),
                ...((a.contact_ids || []).map(id => nameOf(contactsPool,  id))),
            ].filter(Boolean).map(esc).join(', ');
            return `<tr><td>${i+1}</td><td>${esc(a.title)}</td><td class="small text-muted">${esc((a.description || '').slice(0, 100))}</td><td class="small">${leads}</td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-edit-agenda="${i}"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger"  data-del-agenda="${i}"><i class="bi bi-x"></i></button>
                </td></tr>`;
        }).join('') || '<tr><td colspan="5" class="text-center text-muted small py-2">No agenda items yet.</td></tr>';
        // Hidden inputs for the big Save-meeting fallback — keep the
        // legacy single-value fields so the server-side wholesale
        // rewrite still works, now derived from the first ticked
        // entry of each list.
        const hidden = document.getElementById('agendaHidden');
        hidden.innerHTML = state.agenda.map((a, i) => {
            const u = (a.user_ids    || [])[0] || 0;
            const s = (a.seat_ids    || [])[0] || 0;
            const c = (a.contact_ids || [])[0] || 0;
            return `<input type="hidden" name="agenda[${i}][title]"           value="${esc(a.title || '')}">
                    <input type="hidden" name="agenda[${i}][description]"     value="${esc(a.description || '')}">
                    <input type="hidden" name="agenda[${i}][lead_user_id]"    value="${u}">
                    <input type="hidden" name="agenda[${i}][lead_seat_id]"    value="${s}">
                    <input type="hidden" name="agenda[${i}][lead_contact_id]" value="${c}">`;
        }).join('');
    };
    const renderDecisions = () => {
        const tbody = document.querySelector('#decisionTable tbody');
        tbody.innerHTML = state.decisions.map((d, i) => {
            const responsibles = [
                ...(d.user_ids || []).map(id => nameOf(R.users, id)),
                ...(d.seat_ids || []).map(id => nameOf(R.seats, id)),
                ...(d.contact_ids || []).map(id => nameOf(contactsPool, id)),
            ].filter(Boolean).map(esc).join(', ');
            return `<tr><td>${i+1}</td><td>${esc(d.heading)}</td><td class="small text-muted">${esc((d.description || '').slice(0, 100))}</td><td class="small">${esc(d.due_date || '')}</td><td class="small">${responsibles}</td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-edit-decision="${i}"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger"  data-del-decision="${i}"><i class="bi bi-x"></i></button>
                </td></tr>`;
        }).join('') || '<tr><td colspan="6" class="text-center text-muted small py-2">No decision points yet.</td></tr>';
        const hidden = document.getElementById('decisionHidden');
        hidden.innerHTML = state.decisions.map((d, i) => {
            const users   = (d.user_ids    || []).map(id => `<input type="hidden" name="decision[${i}][user_ids][]"    value="${id}">`).join('');
            const seats   = (d.seat_ids    || []).map(id => `<input type="hidden" name="decision[${i}][seat_ids][]"    value="${id}">`).join('');
            const conts   = (d.contact_ids || []).map(id => `<input type="hidden" name="decision[${i}][contact_ids][]" value="${id}">`).join('');
            return `<input type="hidden" name="decision[${i}][heading]"     value="${esc(d.heading || '')}">
                    <input type="hidden" name="decision[${i}][description]" value="${esc(d.description || '')}">
                    <input type="hidden" name="decision[${i}][due_date]"    value="${d.due_date || ''}">${users}${seats}${conts}`;
        }).join('');
    };
    const renderNextAgenda = () => {
        const tbody = document.querySelector('#nextAgendaTable tbody');
        tbody.innerHTML = state.next_agenda.map((n, i) => {
            return `<tr><td>${i+1}</td><td>${esc(n.title)}</td><td class="small text-muted">${esc(n.description || '')}</td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-edit-next="${i}"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger"  data-del-next="${i}"><i class="bi bi-x"></i></button>
                </td></tr>`;
        }).join('') || '<tr><td colspan="4" class="text-center text-muted small py-2">No next-meeting agenda yet.</td></tr>';
        document.getElementById('nextAgendaHidden').innerHTML = state.next_agenda.map((n, i) =>
            `<input type="hidden" name="next_agenda[${i}][title]"       value="${esc(n.title || '')}">
             <input type="hidden" name="next_agenda[${i}][description]" value="${esc(n.description || '')}">`).join('');
    };
    const renderUrls = () => {
        const tbody = document.querySelector('#urlTable tbody');
        tbody.innerHTML = state.urls.map((u, i) => {
            return `<tr><td class="small">${esc(u.label || '')}</td><td class="small"><a href="${esc(u.url)}" target="_blank">${esc(u.url)}</a></td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-edit-url="${i}"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger"  data-del-url="${i}"><i class="bi bi-x"></i></button>
                </td></tr>`;
        }).join('') || '<tr><td colspan="3" class="text-center text-muted small py-2">No URLs yet.</td></tr>';
        document.getElementById('urlHidden').innerHTML = state.urls.map((u, i) =>
            `<input type="hidden" name="url[${i}][label]" value="${esc(u.label || '')}">
             <input type="hidden" name="url[${i}][url]"   value="${esc(u.url || '')}">`).join('');
    };
    const renderAll = () => { renderParticipants(); renderAgenda(); renderDecisions(); renderNextAgenda(); renderUrls(); };

    // Table row edit / delete — deletes fire an AJAX call before
    // mutating local state so the DB stays in sync.
    const deleteRow = async (endpoint, id, listName, idx) => {
        if (id && meetingId > 0) {
            setStatus('Deleting…', 'info');
            const j = await ajax({ action: endpoint, meeting_id: meetingId, id });
            if (!j.ok) { setStatus('Delete failed', 'danger'); return; }
            setStatus('Saved', 'success');
        }
        state[listName].splice(idx, 1);
        renderAll();
    };
    document.addEventListener('click', (ev) => {
        const eP = ev.target.closest('[data-edit-participant]'); if (eP) return openParticipant(+eP.dataset.editParticipant);
        const dP = ev.target.closest('[data-del-participant]');  if (dP) { const i = +dP.dataset.delParticipant; return deleteRow('delete_participant', state.participants[i]?.id || 0, 'participants', i); }
        const eA = ev.target.closest('[data-edit-agenda]'); if (eA) return openAgenda(+eA.dataset.editAgenda);
        const dA = ev.target.closest('[data-del-agenda]');  if (dA) { const i = +dA.dataset.delAgenda; return deleteRow('delete_agenda', state.agenda[i]?.id || 0, 'agenda', i); }
        const eD = ev.target.closest('[data-edit-decision]'); if (eD) return openDecision(+eD.dataset.editDecision);
        const dD = ev.target.closest('[data-del-decision]');  if (dD) { const i = +dD.dataset.delDecision; return deleteRow('delete_decision', state.decisions[i]?.id || 0, 'decisions', i); }
        const eN = ev.target.closest('[data-edit-next]'); if (eN) return openNext(+eN.dataset.editNext);
        const dN = ev.target.closest('[data-del-next]');  if (dN) { const i = +dN.dataset.delNext; return deleteRow('delete_next_agenda', state.next_agenda[i]?.id || 0, 'next_agenda', i); }
        const eU = ev.target.closest('[data-edit-url]'); if (eU) return openUrl(+eU.dataset.editUrl);
        const dU = ev.target.closest('[data-del-url]');  if (dU) { const i = +dU.dataset.delUrl; return deleteRow('delete_url', state.urls[i]?.id || 0, 'urls', i); }
    });

    // ============ MODAL OPEN / PREFILL ============
    // Participant modal — multi-select checkbox lists on either side.
    // When editing an existing row (idx != null) we pre-tick that one
    // person; when adding fresh (idx == null) the lists come up empty
    // and the operator can tick any number.
    const sortByName = (list) => list.slice().sort((a, b) => a.name.localeCompare(b.name));
    const buildCheckList = (containerId, items, preChecked, prefix) => {
        const set = new Set((preChecked || []).map(String));
        const html = items.map(x => {
            const sub = x.inst ? ` <span class="text-muted small">· ${esc(x.inst)}</span>` : '';
            const checked = set.has(String(x.id)) ? 'checked' : '';
            return `<div class="pm-row" data-name="${esc(x.name.toLowerCase() + ' ' + (x.inst || '').toLowerCase())}">
                <input class="form-check-input" type="checkbox" value="${x.id}" id="${prefix}${x.id}" ${checked}>
                <label for="${prefix}${x.id}">${esc(x.name)}${sub}</label>
            </div>`;
        }).join('') || '<div class="pm-empty">No entries.</div>';
        document.getElementById(containerId).innerHTML = html;
    };
    const filterCheckList = (containerId, countId, needle) => {
        const n = needle.trim().toLowerCase();
        let shown = 0, total = 0;
        document.querySelectorAll(`#${containerId} .pm-row`).forEach(r => {
            total++;
            const match = n === '' || (r.getAttribute('data-name') || '').includes(n);
            r.style.display = match ? '' : 'none';
            if (match) shown++;
        });
        document.getElementById(countId).textContent = total === 0 ? '' : (n === '' ? `${total} total` : `${shown} of ${total} shown`);
    };

    const openParticipant = (idx) => {
        const p = idx == null ? {} : state.participants[idx];
        document.getElementById('pModalIdx').value = idx == null ? '' : idx;
        const preUsers    = p.user_id    ? [p.user_id]    : [];
        const preContacts = p.contact_id ? [p.contact_id] : [];
        buildCheckList('pListUsers',    sortByName(R.users),        preUsers,    'pmu_');
        buildCheckList('pListContacts', sortByName(contactsPool),   preContacts, 'pmc_');
        document.getElementById('pSearchUsers').value = '';
        document.getElementById('pSearchContacts').value = '';
        filterCheckList('pListUsers',    'pListUsersCount',    '');
        filterCheckList('pListContacts', 'pListContactsCount', '');
        document.getElementById('pModalMand').value = String(p.is_mandatory ?? 1);
        document.getElementById('pModalAtt').value  = p.attended == null ? '' : String(p.attended);
        document.getElementById('pModalRole').value = p.role_label || '';
        openModal('participantModal');
    };
    // Live search inside each checkbox list.
    document.getElementById('pSearchUsers')?.addEventListener('input', (e) => filterCheckList('pListUsers', 'pListUsersCount', e.target.value));
    document.getElementById('pSearchContacts')?.addEventListener('input', (e) => filterCheckList('pListContacts', 'pListContactsCount', e.target.value));
    // Compose the Participants column from the live state array
    // every time the modal opens — the operator may have added or
    // removed participants since the last open. Each entry carries
    // a type ('u' for internal user, 'c' for external) so the
    // checkbox value encodes which pool it feeds back into on save.
    const buildParticipantPool = () => {
        const rows = [];
        state.participants.forEach(p => {
            if (p.user_id > 0) {
                const u = findById(R.users, p.user_id);
                if (u) rows.push({ id: 'u' + p.user_id, name: u.name, inst: '' });
            }
            if (p.contact_id > 0) {
                const c = findById(contactsPool, p.contact_id);
                if (c) rows.push({ id: 'c' + p.contact_id, name: c.name, inst: c.inst || '' });
            }
        });
        return rows;
    };
    const openAgenda = (idx) => {
        const a = idx == null ? {} : state.agenda[idx];
        document.getElementById('aModalIdx').value = idx == null ? '' : idx;
        document.getElementById('aModalTitle').value = a.title || '';
        document.getElementById('aModalDesc').value  = a.description || '';
        // Participant pool (prefixed-id scheme: u<id> or c<id>).
        const partPool = sortByName(buildParticipantPool());
        const prePart = [];
        (a.user_ids    || []).forEach(id => prePart.push('u' + id));
        (a.contact_ids || []).forEach(id => prePart.push('c' + id));
        buildCheckList('aListParts', partPool, prePart, 'alp_');
        // Fallback pools: Internal / External users NOT already in participants.
        const participantUserIds    = new Set(state.participants.filter(p => p.user_id    > 0).map(p => p.user_id));
        const participantContactIds = new Set(state.participants.filter(p => p.contact_id > 0).map(p => p.contact_id));
        const otherUsers    = sortByName(R.users).filter(u => !participantUserIds.has(u.id));
        const otherContacts = sortByName(contactsPool).filter(c => !participantContactIds.has(c.id));
        buildCheckList('aListUsers',    otherUsers,    a.user_ids    || [], 'alu_');
        buildCheckList('aListContacts', otherContacts, a.contact_ids || [], 'alc_');
        document.getElementById('aSearchParts').value    = '';
        document.getElementById('aSearchUsers').value    = '';
        document.getElementById('aSearchContacts').value = '';
        filterCheckList('aListParts',    'aListPartsCount',    '');
        filterCheckList('aListUsers',    'aListUsersCount',    '');
        filterCheckList('aListContacts', 'aListContactsCount', '');
        openModal('agendaModal');
    };
    document.getElementById('aSearchParts')?.addEventListener('input', (e) => filterCheckList('aListParts',    'aListPartsCount',    e.target.value));
    document.getElementById('aSearchUsers')?.addEventListener('input', (e) => filterCheckList('aListUsers',    'aListUsersCount',    e.target.value));
    document.getElementById('aSearchContacts')?.addEventListener('input', (e) => filterCheckList('aListContacts', 'aListContactsCount', e.target.value));
    const openDecision = (idx) => {
        const d = idx == null ? {} : state.decisions[idx];
        document.getElementById('dModalIdx').value = idx == null ? '' : idx;
        document.getElementById('dModalHead').value = d.heading || '';
        document.getElementById('dModalDesc').value = d.description || '';
        document.getElementById('dModalDue').value  = d.due_date || '';
        // Three-column layout, same shape as the agenda modal.
        const partPool = sortByName(buildParticipantPool());
        const prePart = [];
        (d.user_ids    || []).forEach(id => prePart.push('u' + id));
        (d.contact_ids || []).forEach(id => prePart.push('c' + id));
        buildCheckList('dListParts', partPool, prePart, 'drp_');
        const participantUserIds    = new Set(state.participants.filter(p => p.user_id    > 0).map(p => p.user_id));
        const participantContactIds = new Set(state.participants.filter(p => p.contact_id > 0).map(p => p.contact_id));
        const otherUsers    = sortByName(R.users).filter(u => !participantUserIds.has(u.id));
        const otherContacts = sortByName(contactsPool).filter(c => !participantContactIds.has(c.id));
        buildCheckList('dListUsers',    otherUsers,    d.user_ids    || [], 'dru_');
        buildCheckList('dListContacts', otherContacts, d.contact_ids || [], 'drc_');
        document.getElementById('dSearchParts').value    = '';
        document.getElementById('dSearchUsers').value    = '';
        document.getElementById('dSearchContacts').value = '';
        filterCheckList('dListParts',    'dListPartsCount',    '');
        filterCheckList('dListUsers',    'dListUsersCount',    '');
        filterCheckList('dListContacts', 'dListContactsCount', '');
        openModal('decisionModal');
    };
    document.getElementById('dSearchParts')?.addEventListener('input', (e) => filterCheckList('dListParts',    'dListPartsCount',    e.target.value));
    document.getElementById('dSearchUsers')?.addEventListener('input', (e) => filterCheckList('dListUsers',    'dListUsersCount',    e.target.value));
    document.getElementById('dSearchContacts')?.addEventListener('input', (e) => filterCheckList('dListContacts', 'dListContactsCount', e.target.value));
    const openNext = (idx) => {
        const n = idx == null ? {} : state.next_agenda[idx];
        document.getElementById('nModalIdx').value = idx == null ? '' : idx;
        document.getElementById('nModalTitle').value = n.title || '';
        document.getElementById('nModalDesc').value  = n.description || '';
        openModal('nextAgendaModal');
    };
    const openUrl = (idx) => {
        const u = idx == null ? {} : state.urls[idx];
        document.getElementById('uModalIdx').value = idx == null ? '' : idx;
        document.getElementById('uModalLabel').value = u.label || '';
        document.getElementById('uModalUrl').value   = u.url || '';
        openModal('urlModal');
    };
    // Open buttons that DON'T carry data-open-modal need custom open calls
    document.querySelectorAll('[data-open-modal="participantModal"]').forEach(b => b.addEventListener('click', () => openParticipant(null)));
    document.querySelectorAll('[data-open-modal="agendaModal"]').forEach(b => b.addEventListener('click', () => openAgenda(null)));
    document.querySelectorAll('[data-open-modal="decisionModal"]').forEach(b => b.addEventListener('click', () => openDecision(null)));
    document.querySelectorAll('[data-open-modal="nextAgendaModal"]').forEach(b => b.addEventListener('click', () => openNext(null)));
    document.querySelectorAll('[data-open-modal="urlModal"]').forEach(b => b.addEventListener('click', () => openUrl(null)));

    // ============ MODAL SAVE ============
    const collectMulti = (el) => Array.from(el.selectedOptions).map(o => Number(o.value)).filter(Boolean);
    // Modal saves upsert via AJAX, then reflect the returned item_id
    // in local state so subsequent edits target the same row.
    const upsertRow = async (endpoint, payload, listName, idxRaw) => {
        try {
            await ensureDraft();
            setStatus('Saving…', 'info');
            const j = await ajax({ action: endpoint, meeting_id: meetingId, ...payload });
            if (!j.ok) { alert('Save failed: ' + (j.error || '')); setStatus('Save error', 'danger'); return; }
            const row = { ...payload, id: j.item_id };
            if (idxRaw === '') state[listName].push(row); else state[listName][+idxRaw] = row;
            renderAll(); closeAllModals();
            setStatus('Saved', 'success');
        } catch (e) { alert('Save failed: ' + e.message); setStatus('Save error', 'danger'); }
    };

    document.getElementById('pModalSave').addEventListener('click', async () => {
        const idxRaw = document.getElementById('pModalIdx').value;
        const checkedUsers    = Array.from(document.querySelectorAll('#pListUsers input:checked')).map(c => Number(c.value));
        const checkedContacts = Array.from(document.querySelectorAll('#pListContacts input:checked')).map(c => Number(c.value));
        if (checkedUsers.length === 0 && checkedContacts.length === 0) { alert('Tick at least one user or external user.'); return; }
        const common = {
            is_mandatory: document.getElementById('pModalMand').value,
            attended:     document.getElementById('pModalAtt').value,
            role_label:   document.getElementById('pModalRole').value.trim(),
        };
        try {
            await ensureDraft();
            setStatus('Saving…', 'info');
            // Edit mode: upsert the one being edited, then any extras
            // get added as new rows.
            const edited = idxRaw !== '' ? state.participants[+idxRaw] : null;
            let primaryDone = false;
            const persist = async (user_id, contact_id) => {
                let targetId = 0;
                if (edited && !primaryDone) {
                    const matchesUser    = user_id    > 0 && user_id    === (edited.user_id    || 0);
                    const matchesContact = contact_id > 0 && contact_id === (edited.contact_id || 0);
                    if (matchesUser || matchesContact) { targetId = edited.id || 0; primaryDone = true; }
                }
                const j = await ajax({ action: 'upsert_participant', meeting_id: meetingId,
                    id: targetId, user_id, contact_id, ...common });
                if (!j.ok) throw new Error(j.error || 'save failed');
                return { id: j.item_id, user_id, contact_id, ...common };
            };
            const newRows = [];
            for (const uid of checkedUsers)    newRows.push(await persist(uid, 0));
            for (const cid of checkedContacts) newRows.push(await persist(0, cid));
            if (idxRaw !== '') {
                // Replace the one being edited (if it got re-upserted),
                // else drop it and append the new rows.
                if (primaryDone) {
                    const editedIdx = newRows.findIndex(r => r.id === edited.id);
                    state.participants[+idxRaw] = newRows[editedIdx];
                    newRows.splice(editedIdx, 1);
                } else {
                    // The person being edited was NOT re-ticked — delete
                    // that DB row and continue with newly-added rows.
                    if (edited.id) await ajax({ action: 'delete_participant', meeting_id: meetingId, id: edited.id });
                    state.participants.splice(+idxRaw, 1);
                }
            }
            state.participants.push(...newRows);
            renderAll(); closeAllModals();
            setStatus('Saved', 'success');
        } catch (e) { alert('Save failed: ' + e.message); setStatus('Save error', 'danger'); }
    });
    document.getElementById('aModalSave').addEventListener('click', () => {
        const idxRaw = document.getElementById('aModalIdx').value;
        const title = document.getElementById('aModalTitle').value.trim();
        if (title === '') { alert('Title required.'); return; }
        const existingId = idxRaw !== '' ? (state.agenda[+idxRaw]?.id || 0) : 0;
        // Collect from both the Participants column (prefixed with
        // u/c to distinguish type) and the other-user / other-contact
        // columns, then dedupe into the final arrays.
        const userSet = new Set(), contactSet = new Set();
        document.querySelectorAll('#aListParts input:checked').forEach(c => {
            const raw = String(c.value);
            if (raw[0] === 'u') userSet.add(Number(raw.slice(1)));
            else if (raw[0] === 'c') contactSet.add(Number(raw.slice(1)));
        });
        document.querySelectorAll('#aListUsers input:checked').forEach(c => userSet.add(Number(c.value)));
        document.querySelectorAll('#aListContacts input:checked').forEach(c => contactSet.add(Number(c.value)));
        upsertRow('upsert_agenda', {
            id: existingId, title, description: document.getElementById('aModalDesc').value.trim(),
            user_ids: Array.from(userSet).filter(Boolean),
            seat_ids: [], // seat-as-lead UI retired; schema still accepts the field
            contact_ids: Array.from(contactSet).filter(Boolean),
        }, 'agenda', idxRaw);
    });
    document.getElementById('dModalSave').addEventListener('click', () => {
        const idxRaw = document.getElementById('dModalIdx').value;
        const heading = document.getElementById('dModalHead').value.trim();
        if (heading === '') { alert('Heading required.'); return; }
        const existingId = idxRaw !== '' ? (state.decisions[+idxRaw]?.id || 0) : 0;
        const userSet = new Set(), contactSet = new Set();
        document.querySelectorAll('#dListParts input:checked').forEach(c => {
            const raw = String(c.value);
            if (raw[0] === 'u') userSet.add(Number(raw.slice(1)));
            else if (raw[0] === 'c') contactSet.add(Number(raw.slice(1)));
        });
        document.querySelectorAll('#dListUsers input:checked').forEach(c => userSet.add(Number(c.value)));
        document.querySelectorAll('#dListContacts input:checked').forEach(c => contactSet.add(Number(c.value)));
        upsertRow('upsert_decision', {
            id: existingId, heading, description: document.getElementById('dModalDesc').value.trim(),
            due_date: document.getElementById('dModalDue').value,
            user_ids: Array.from(userSet).filter(Boolean),
            seat_ids: [], // seat-as-responsibility UI retired
            contact_ids: Array.from(contactSet).filter(Boolean),
        }, 'decisions', idxRaw);
    });
    document.getElementById('nModalSave').addEventListener('click', () => {
        const idxRaw = document.getElementById('nModalIdx').value;
        const title = document.getElementById('nModalTitle').value.trim();
        if (title === '') { alert('Title required.'); return; }
        const existingId = idxRaw !== '' ? (state.next_agenda[+idxRaw]?.id || 0) : 0;
        upsertRow('upsert_next_agenda', {
            id: existingId, title, description: document.getElementById('nModalDesc').value.trim(),
        }, 'next_agenda', idxRaw);
    });
    document.getElementById('uModalSave').addEventListener('click', () => {
        const idxRaw = document.getElementById('uModalIdx').value;
        const url = document.getElementById('uModalUrl').value.trim();
        if (url === '') { alert('URL required.'); return; }
        const existingId = idxRaw !== '' ? (state.urls[+idxRaw]?.id || 0) : 0;
        upsertRow('upsert_url', {
            id: existingId, label: document.getElementById('uModalLabel').value.trim(), url,
        }, 'urls', idxRaw);
    });

    // ============ CHAIR quick-add + refresh ============
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;
    const rebuildChairSelect = (selectId) => {
        const sel = document.getElementById(selectId);
        const currentVal = sel.value;
        sel.innerHTML = '<option value="0">— None —</option>' + contactsPool.map(c =>
            `<option value="${c.id}">${esc(c.name + (c.inst || c.institution ? ' · ' + (c.inst || c.institution) : ''))}</option>`).join('');
        sel.value = currentVal;
        // If TomSelect wrapped this select, sync its internal option
        // store so the newly-added row is actually in its dropdown.
        if (sel.tomselect) {
            sel.tomselect.clearOptions();
            sel.tomselect.addOption({value: '0', text: '— None —'});
            contactsPool.forEach(c => sel.tomselect.addOption({
                value: String(c.id),
                text: c.name + (c.inst || c.institution ? ' · ' + (c.inst || c.institution) : ''),
            }));
            sel.tomselect.refreshOptions(false);
            sel.tomselect.setValue(currentVal, true);
        }
    };
    document.getElementById('chairNewContactBtn')?.addEventListener('click', (ev) => { ev.preventDefault(); openModal('newContactModal'); });
    document.getElementById('chairRefreshBtn')?.addEventListener('click', async () => {
        try {
            const res = await fetch('/contacts_ajax.php?action=list');
            const json = await res.json();
            if (json.ok) {
                contactsPool = json.contacts.map(c => ({ id: c.id, name: c.name, inst: c.institution || '' }));
                rebuildChairSelect('chairContactSelect');
                renderAll();
            }
        } catch (e) { alert('Refresh failed: ' + e.message); }
    });
    document.getElementById('ncSave').addEventListener('click', async () => {
        const name = document.getElementById('ncName').value.trim();
        if (name === '') { alert('Name required.'); return; }
        const body = new URLSearchParams();
        body.set('action', 'create'); body.set('csrf_token', csrfToken); body.set('name', name);
        body.set('institution', document.getElementById('ncInst').value.trim());
        body.set('designation', document.getElementById('ncDes').value.trim());
        body.set('mobile', document.getElementById('ncMob').value.trim());
        body.set('email',  document.getElementById('ncEm').value.trim());
        try {
            const res = await fetch('/contacts_ajax.php', { method: 'POST', body });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'Create failed');
            const c = json.contact;
            contactsPool.push({ id: c.id, name: c.name, inst: c.institution || '' });
            rebuildChairSelect('chairContactSelect');
            const chair = document.getElementById('chairContactSelect');
            chair.value = c.id;
            if (chair.tomselect) chair.tomselect.setValue(String(c.id), false);
            // Nudge the auto-save pipeline so the new chair persists.
            chair.dispatchEvent(new Event('change', { bubbles: true }));
            // Clear the modal fields.
            ['ncName','ncInst','ncDes','ncMob','ncEm'].forEach(id => document.getElementById(id).value = '');
            renderAll();
            closeAllModals();
        } catch (e) { alert('Create failed: ' + e.message); }
    });

    // Initial render (preset already applied to state above).
    renderAll();
})();
</script>

<!-- TomSelect for searchable single-selects on the main form. One
     helper per select — each one hooks in on top of the existing
     native select without touching its name/value contract, so the
     auto-save flow keeps working against the same field names. -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tom-select/2.3.1/css/tom-select.bootstrap5.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/tom-select/2.3.1/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof TomSelect === 'undefined') return;
    document.querySelectorAll('.ts-search').forEach(sel => {
        try {
            new TomSelect(sel, {
                allowEmptyOption: true,
                maxOptions: 2000,
                plugins: ['dropdown_input'],
            });
        } catch (e) { console.warn('TomSelect init failed on', sel.id, e); }
    });
});
</script>

<?php render_footer(); ?>

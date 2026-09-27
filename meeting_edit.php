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
                <label class="form-label">Chairperson (user)</label>
                <select class="form-select" name="chair_user_id">
                    <option value="0">— None (or use contact) —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) $formValues['chair_user_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= esc((string) $u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Chairperson (contact)</label>
                <select class="form-select" name="chair_contact_id">
                    <option value="0">— None —</option>
                    <?php foreach ($contacts as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) $formValues['chair_contact_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= esc((string) $c['name']) ?><?= !empty($c['institution']) ? ' · ' . esc((string) $c['institution']) : '' ?></option>
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
        <h6 class="text-uppercase small text-muted mb-3"><i class="bi bi-people me-1"></i>Participants</h6>
        <div id="participantsWrap"></div>
        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addParticipant"><i class="bi bi-plus-lg me-1"></i>Add participant</button>

        <hr class="my-4">
        <h6 class="text-uppercase small text-muted mb-3"><i class="bi bi-list-ol me-1"></i>Agenda</h6>
        <div id="agendaWrap"></div>
        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addAgenda"><i class="bi bi-plus-lg me-1"></i>Add agenda item</button>

        <hr class="my-4">
        <h6 class="text-uppercase small text-muted mb-3"><i class="bi bi-check2-square me-1"></i>Decision points</h6>
        <div id="decisionsWrap"></div>
        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addDecision"><i class="bi bi-plus-lg me-1"></i>Add decision</button>

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
            <div class="col-md-6">
                <label class="form-label">Previous meeting (link to earlier record)</label>
                <select class="form-select" name="previous_meeting_id">
                    <option value="0">— None —</option>
                    <?php
                    try {
                        $prev = db()->query('SELECT id, reference_no, title FROM meeting WHERE is_active = 1 ORDER BY meeting_date DESC LIMIT 200')->fetchAll();
                    } catch (Throwable $e) { $prev = []; }
                    foreach ($prev as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $formValues['previous_meeting_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $p['reference_no']) ?> · <?= esc((string) $p['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div id="nextAgendaWrap" class="mt-3"></div>
        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addNextAgenda"><i class="bi bi-plus-lg me-1"></i>Add next-meeting agenda</button>

        <hr class="my-4">
        <h6 class="text-uppercase small text-muted mb-3"><i class="bi bi-link-45deg me-1"></i>Attachment URLs <span class="small text-muted">(Google Drive / SharePoint / any)</span></h6>
        <div id="urlsWrap"></div>
        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addUrl"><i class="bi bi-plus-lg me-1"></i>Add URL</button>
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
    preset: {
        participants: <?= json_encode(array_map(static fn($p) => [
            'user_id' => (int) ($p['user_id'] ?? 0),
            'contact_id' => (int) ($p['contact_id'] ?? 0),
            'is_mandatory' => (int) $p['is_mandatory'],
            'attended' => $p['attended'] === null ? '' : (string) $p['attended'],
            'role_label' => (string) ($p['role_label'] ?? ''),
        ], $existingParticipants)) ?>,
        agenda: <?= json_encode(array_map(static fn($a) => [
            'title' => (string) $a['title'],
            'description' => (string) ($a['description'] ?? ''),
            'lead_user_id' => (int) ($a['lead_user_id'] ?? 0),
            'lead_seat_id' => (int) ($a['lead_seat_id'] ?? 0),
            'lead_contact_id' => (int) ($a['lead_contact_id'] ?? 0),
        ], $existingAgenda)) ?>,
        decisions: <?= json_encode(array_map(static function ($d) use ($existingDecisionResp) {
            $rs = $existingDecisionResp[(int) $d['id']] ?? [];
            return [
                'heading' => (string) $d['heading'],
                'description' => (string) ($d['description'] ?? ''),
                'due_date' => substr((string) ($d['due_date'] ?? ''), 0, 10),
                'user_ids' => array_values(array_filter(array_map(static fn($r) => (int) ($r['user_id'] ?? 0), $rs))),
                'seat_ids' => array_values(array_filter(array_map(static fn($r) => (int) ($r['seat_id'] ?? 0), $rs))),
                'contact_ids' => array_values(array_filter(array_map(static fn($r) => (int) ($r['contact_id'] ?? 0), $rs))),
            ];
        }, $existingDecisions)) ?>,
        next_agenda: <?= json_encode(array_map(static fn($n) => ['title' => (string) $n['title'], 'description' => (string) ($n['description'] ?? '')], $existingNextAgenda)) ?>,
        urls: <?= json_encode(array_map(static fn($u) => ['label' => (string) ($u['label'] ?? ''), 'url' => (string) $u['url']], $existingUrls)) ?>,
    },
};
</script>
<script>
(function () {
    const R = window.__meetingRef;
    const optHtml = (list, keyName, sel) => {
        let out = '<option value="0">— None —</option>';
        list.forEach(x => {
            const label = x.name + (x.inst ? ' · ' + x.inst : '');
            out += `<option value="${x.id}" ${String(x.id) === String(sel) ? 'selected' : ''}>${label.replace(/</g,'&lt;')}</option>`;
        });
        return out;
    };
    const multiOptHtml = (list, sel) => {
        const set = new Set((sel || []).map(String));
        let out = '';
        list.forEach(x => {
            const label = x.name + (x.inst ? ' · ' + x.inst : '');
            out += `<option value="${x.id}" ${set.has(String(x.id)) ? 'selected' : ''}>${label.replace(/</g,'&lt;')}</option>`;
        });
        return out;
    };

    const addParticipant = (p = {}) => {
        const idx = document.querySelectorAll('#participantsWrap .prow').length;
        const html = `<div class="prow row g-2 align-items-end mb-2 border rounded p-2">
            <div class="col-md-3"><label class="form-label small">User (from seat)</label>
                <select class="form-select form-select-sm" name="participant[${idx}][user_id]">${optHtml(R.users, 'name', p.user_id || 0)}</select></div>
            <div class="col-md-3"><label class="form-label small">Contact (external)</label>
                <select class="form-select form-select-sm" name="participant[${idx}][contact_id]">${optHtml(R.contacts, 'name', p.contact_id || 0)}</select></div>
            <div class="col-md-2"><label class="form-label small">Attendance type</label>
                <select class="form-select form-select-sm" name="participant[${idx}][is_mandatory]">
                    <option value="1" ${String(p.is_mandatory ?? 1) === '1' ? 'selected' : ''}>Mandatory</option>
                    <option value="0" ${String(p.is_mandatory) === '0' ? 'selected' : ''}>Optional</option>
                </select></div>
            <div class="col-md-2"><label class="form-label small">Present?</label>
                <select class="form-select form-select-sm" name="participant[${idx}][attended]">
                    <option value="" ${p.attended === '' || p.attended === undefined ? 'selected' : ''}>— not yet —</option>
                    <option value="1" ${String(p.attended) === '1' ? 'selected' : ''}>Present</option>
                    <option value="0" ${String(p.attended) === '0' ? 'selected' : ''}>Absent</option>
                </select></div>
            <div class="col-md-1"><label class="form-label small">Role</label>
                <input class="form-control form-control-sm" name="participant[${idx}][role_label]" placeholder="e.g. Guest" value="${(p.role_label || '').replace(/"/g,'&quot;')}"></div>
            <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.prow').remove();"><i class="bi bi-x"></i></button></div>
        </div>`;
        document.getElementById('participantsWrap').insertAdjacentHTML('beforeend', html);
    };
    const addAgenda = (a = {}) => {
        const idx = document.querySelectorAll('#agendaWrap .arow').length;
        const html = `<div class="arow row g-2 align-items-end mb-2 border rounded p-2">
            <div class="col-md-4"><label class="form-label small">Agenda title</label>
                <input class="form-control form-control-sm" name="agenda[${idx}][title]" value="${(a.title || '').replace(/"/g,'&quot;')}"></div>
            <div class="col-md-4"><label class="form-label small">Description</label>
                <input class="form-control form-control-sm" name="agenda[${idx}][description]" value="${(a.description || '').replace(/"/g,'&quot;')}"></div>
            <div class="col-md-3"><label class="form-label small">Lead (user OR contact OR seat)</label>
                <select class="form-select form-select-sm" name="agenda[${idx}][lead_user_id]">${optHtml(R.users, 'name', a.lead_user_id || 0)}</select>
                <select class="form-select form-select-sm mt-1" name="agenda[${idx}][lead_seat_id]">${optHtml(R.seats, 'name', a.lead_seat_id || 0)}</select>
                <select class="form-select form-select-sm mt-1" name="agenda[${idx}][lead_contact_id]">${optHtml(R.contacts, 'name', a.lead_contact_id || 0)}</select></div>
            <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.arow').remove();"><i class="bi bi-x"></i></button></div>
        </div>`;
        document.getElementById('agendaWrap').insertAdjacentHTML('beforeend', html);
    };
    const addDecision = (d = {}) => {
        const idx = document.querySelectorAll('#decisionsWrap .drow').length;
        const html = `<div class="drow row g-2 align-items-start mb-2 border rounded p-2">
            <div class="col-md-4"><label class="form-label small">Heading</label>
                <input class="form-control form-control-sm" name="decision[${idx}][heading]" value="${(d.heading || '').replace(/"/g,'&quot;')}">
                <label class="form-label small mt-2">Description</label>
                <textarea class="form-control form-control-sm" name="decision[${idx}][description]" rows="3">${(d.description || '').replace(/</g,'&lt;')}</textarea>
                <label class="form-label small mt-2">Due date</label>
                <input type="date" class="form-control form-control-sm" name="decision[${idx}][due_date]" value="${d.due_date || ''}"></div>
            <div class="col-md-3"><label class="form-label small">Responsible users</label>
                <select class="form-select form-select-sm" name="decision[${idx}][user_ids][]" multiple size="6">${multiOptHtml(R.users, d.user_ids || [])}</select></div>
            <div class="col-md-3"><label class="form-label small">Responsible seats</label>
                <select class="form-select form-select-sm" name="decision[${idx}][seat_ids][]" multiple size="6">${multiOptHtml(R.seats, d.seat_ids || [])}</select></div>
            <div class="col-md-2"><label class="form-label small">Responsible contacts</label>
                <select class="form-select form-select-sm" name="decision[${idx}][contact_ids][]" multiple size="6">${multiOptHtml(R.contacts, d.contact_ids || [])}</select>
                <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="this.closest('.drow').remove();"><i class="bi bi-x"></i> Remove</button></div>
        </div>`;
        document.getElementById('decisionsWrap').insertAdjacentHTML('beforeend', html);
    };
    const addNextAgenda = (n = {}) => {
        const idx = document.querySelectorAll('#nextAgendaWrap .nrow').length;
        const html = `<div class="nrow row g-2 align-items-end mb-2 border rounded p-2">
            <div class="col-md-4"><label class="form-label small">Next-meeting agenda title</label>
                <input class="form-control form-control-sm" name="next_agenda[${idx}][title]" value="${(n.title || '').replace(/"/g,'&quot;')}"></div>
            <div class="col-md-7"><label class="form-label small">Description</label>
                <input class="form-control form-control-sm" name="next_agenda[${idx}][description]" value="${(n.description || '').replace(/"/g,'&quot;')}"></div>
            <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.nrow').remove();"><i class="bi bi-x"></i></button></div>
        </div>`;
        document.getElementById('nextAgendaWrap').insertAdjacentHTML('beforeend', html);
    };
    const addUrl = (u = {}) => {
        const idx = document.querySelectorAll('#urlsWrap .urow').length;
        const html = `<div class="urow row g-2 align-items-end mb-2 border rounded p-2">
            <div class="col-md-3"><label class="form-label small">Label</label>
                <input class="form-control form-control-sm" name="url[${idx}][label]" value="${(u.label || '').replace(/"/g,'&quot;')}" placeholder="Optional label"></div>
            <div class="col-md-8"><label class="form-label small">URL</label>
                <input type="url" class="form-control form-control-sm" name="url[${idx}][url]" value="${(u.url || '').replace(/"/g,'&quot;')}" placeholder="https://…"></div>
            <div class="col-md-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.urow').remove();"><i class="bi bi-x"></i></button></div>
        </div>`;
        document.getElementById('urlsWrap').insertAdjacentHTML('beforeend', html);
    };

    document.getElementById('addParticipant').addEventListener('click', () => addParticipant());
    document.getElementById('addAgenda').addEventListener('click', () => addAgenda());
    document.getElementById('addDecision').addEventListener('click', () => addDecision());
    document.getElementById('addNextAgenda').addEventListener('click', () => addNextAgenda());
    document.getElementById('addUrl').addEventListener('click', () => addUrl());

    // Hydrate from preset (edit path).
    (R.preset.participants || []).forEach(addParticipant);
    (R.preset.agenda || []).forEach(addAgenda);
    (R.preset.decisions || []).forEach(addDecision);
    (R.preset.next_agenda || []).forEach(addNextAgenda);
    (R.preset.urls || []).forEach(addUrl);
    if ((R.preset.participants || []).length === 0) addParticipant();
    if ((R.preset.agenda || []).length === 0)       addAgenda();
})();
</script>

<?php render_footer(); ?>

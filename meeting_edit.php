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
require_once __DIR__ . '/includes/teams_helpers.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
meetings_bootstrap();
teams_bootstrap();
$teams = teams_list_active();
$teamsHasTeamId = teams_responsible_has_team_id();
$teamsHasFanOut = teams_decision_has_fan_out();

$isAdminAll = is_manage_admin($viewer) || user_can_admin_module($viewerId, 'meetings');

$id = (int) ($_GET['id'] ?? 0);
$existing = null;
if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM meeting WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $existing = $stmt->fetch() ?: null;
    if ($existing !== null) {
        // Edit = creator, (internal) chairperson, or admin. Chair gets
        // edit rights so they can massage decision wording before
        // approving.
        if (!meetings_can_edit($existing, $viewer)) {
            http_response_code(403);
            render_header('Access denied');
            render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
            echo '<div class="alert alert-danger">Only the meeting creator, the chairperson, or a Meetings admin can edit this row.</div>';
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
$decisionIdsWithNextMirror = []; // decision ids that already have a linked next-agenda row
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
            // Which of these decisions already have an "Add to next-meeting"
            // mirror row? Used to pre-tick the checkbox in the modal.
            if (meetings_column_exists('meeting_next_agenda', 'linked_decision_id')) {
                $st = db()->prepare("SELECT DISTINCT linked_decision_id FROM meeting_next_agenda WHERE meeting_id = ? AND linked_decision_id IN ($ph)");
                $st->execute(array_merge([$mid], $ids));
                foreach ($st->fetchAll() as $r) $decisionIdsWithNextMirror[(int) $r['linked_decision_id']] = true;
            }
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

        // Agenda. Each client-side agenda row carries a `tmp_id` so a
        // decision that references it can be re-linked after the row's
        // DB id changes (the big save does DELETE + re-INSERT and the
        // agenda IDs flip each time). Build $tmpToAgendaId while we
        // re-insert, then resolve decision.agenda_id from it below.
        $hasAgendaRemarksSave = false;
        try { foreach ($db->query('SHOW COLUMNS FROM meeting_agenda')->fetchAll() as $c) if (strtolower((string) $c['Field']) === 'remarks') $hasAgendaRemarksSave = true; } catch (Throwable $e) { /* ignore */ }
        $insA = $db->prepare('INSERT INTO meeting_agenda
            (meeting_id, sort_order, title, description, lead_user_id, lead_seat_id, lead_contact_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $order = 0;
        $tmpToAgendaId = [];
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
            $newAgendaId = (int) $db->lastInsertId();
            $tmpKey = trim((string) ($row['tmp_id'] ?? ''));
            if ($tmpKey !== '') $tmpToAgendaId[$tmpKey] = $newAgendaId;
            if ($hasAgendaRemarksSave) {
                $remarksRaw = trim((string) ($row['remarks'] ?? ''));
                $db->prepare('UPDATE meeting_agenda SET remarks = ? WHERE id = ?')
                    ->execute([$remarksRaw === '' ? null : $remarksRaw, $newAgendaId]);
            }
        }

        // Decisions + M:N responsibility. The decision carries two
        // flag columns + a fan_out_teams toggle that may or may not
        // exist on older installs — detect once and pick SQL
        // accordingly. The responsibility table also has an optional
        // team_id column we populate when available.
        $hasDecFlagsSave = false; $hasFanOutSave = false; $hasRespTeamSave = false; $hasRemarksSave = false; $hasAgendaIdSave = false;
        try {
            $colsD = [];
            foreach ($db->query('SHOW COLUMNS FROM meeting_decision')->fetchAll() as $c) $colsD[strtolower((string) $c['Field'])] = true;
            $hasDecFlagsSave = isset($colsD['create_own_tasks']) && isset($colsD['status_private']);
            $hasFanOutSave   = isset($colsD['fan_out_teams']);
            $hasRemarksSave  = isset($colsD['remarks']);
            $hasAgendaIdSave = isset($colsD['agenda_id']);
        } catch (Throwable $e) { /* ignore */ }
        try {
            $colsR = [];
            foreach ($db->query('SHOW COLUMNS FROM meeting_decision_responsible')->fetchAll() as $c) $colsR[strtolower((string) $c['Field'])] = true;
            $hasRespTeamSave = isset($colsR['team_id']);
        } catch (Throwable $e) { /* ignore */ }

        if ($hasDecFlagsSave && $hasFanOutSave) {
            $insD = $db->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date, create_own_tasks, status_private, fan_out_teams) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        } elseif ($hasDecFlagsSave) {
            $insD = $db->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date, create_own_tasks, status_private) VALUES (?, ?, ?, ?, ?, ?, ?)');
        } else {
            $insD = $db->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date) VALUES (?, ?, ?, ?, ?)');
        }
        $insR = $hasRespTeamSave
            ? $db->prepare('INSERT INTO meeting_decision_responsible (decision_id, user_id, contact_id, seat_id, team_id) VALUES (?, ?, ?, ?, ?)')
            : $db->prepare('INSERT INTO meeting_decision_responsible (decision_id, user_id, contact_id, seat_id) VALUES (?, ?, ?, ?)');
        // Collect auto-mirror rows while inserting decisions, so the
        // next-agenda insert loop below can append them after the manual
        // rows (preserves their sort_order position).
        $autoMirrorRows = [];
        $order = 0;
        foreach ((array) ($_POST['decision'] ?? []) as $row) {
            $h = trim((string) ($row['heading'] ?? ''));
            if ($h === '') continue;
            $due = trim((string) ($row['due_date'] ?? ''));
            $createOwnF = isset($row['create_own_tasks']) ? (int) $row['create_own_tasks'] : 1;
            $statusPrivF = isset($row['status_private']) ? (int) $row['status_private'] : 0;
            $fanOutF    = isset($row['fan_out_teams']) ? (int) $row['fan_out_teams'] : 0;
            $params = [$id, ++$order, $h, trim((string) ($row['description'] ?? '')) ?: null,
                ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) ? $due : null];
            if ($hasDecFlagsSave) { $params[] = $createOwnF ? 1 : 0; $params[] = $statusPrivF ? 1 : 0; }
            if ($hasDecFlagsSave && $hasFanOutSave) { $params[] = $fanOutF ? 1 : 0; }
            $insD->execute($params);
            $newDecId = $db->lastInsertId();
            if ($hasAgendaIdSave) {
                $agendaTmp = trim((string) ($row['agenda_tmp_id'] ?? ''));
                $resolvedAgendaId = ($agendaTmp !== '' && isset($tmpToAgendaId[$agendaTmp])) ? $tmpToAgendaId[$agendaTmp] : null;
                $db->prepare('UPDATE meeting_decision SET agenda_id = ? WHERE id = ?')
                    ->execute([$resolvedAgendaId, $newDecId]);
            }
            $decRemarksRaw = $hasRemarksSave ? trim((string) ($row['remarks'] ?? '')) : '';
            if ($hasRemarksSave) {
                $db->prepare('UPDATE meeting_decision SET remarks = ? WHERE id = ?')
                    ->execute([$decRemarksRaw === '' ? null : $decRemarksRaw, $newDecId]);
            }
            if ($hasRespTeamSave) {
                foreach ((array) ($row['user_ids']    ?? []) as $x) if ((int) $x > 0) $insR->execute([$newDecId, (int) $x, null, null, null]);
                foreach ((array) ($row['seat_ids']    ?? []) as $x) if ((int) $x > 0) $insR->execute([$newDecId, null, null, (int) $x, null]);
                foreach ((array) ($row['contact_ids'] ?? []) as $x) if ((int) $x > 0) $insR->execute([$newDecId, null, (int) $x, null, null]);
                foreach ((array) ($row['team_ids']    ?? []) as $x) if ((int) $x > 0) $insR->execute([$newDecId, null, null, null, (int) $x]);
            } else {
                foreach ((array) ($row['user_ids']    ?? []) as $x) if ((int) $x > 0) $insR->execute([$newDecId, (int) $x, null, null]);
                foreach ((array) ($row['seat_ids']    ?? []) as $x) if ((int) $x > 0) $insR->execute([$newDecId, null, null, (int) $x]);
                foreach ((array) ($row['contact_ids'] ?? []) as $x) if ((int) $x > 0) $insR->execute([$newDecId, null, (int) $x, null]);
            }
            // "Add to next-meeting agenda" mirror row — queued now,
            // inserted after the manual next-agenda loop below.
            if (((int) ($row['add_to_next_agenda'] ?? 0)) === 1) {
                $resolvedNames = meetings_resolve_responsible_names(
                    (array) ($row['user_ids'] ?? []),
                    (array) ($row['seat_ids'] ?? []),
                    (array) ($row['contact_ids'] ?? []),
                    (array) ($row['team_ids'] ?? [])
                );
                $autoMirrorRows[] = [
                    'linked_decision_id' => $newDecId,
                    'title'              => $h,
                    'description'        => $decRemarksRaw === '' ? null : $decRemarksRaw,
                    'remarks'            => $resolvedNames === '' ? null : $resolvedNames,
                ];
            }
        }

        // Next-meeting agenda
        $hasNextRemarksSave = false;
        $hasLinkedCol       = false;
        try {
            foreach ($db->query('SHOW COLUMNS FROM meeting_next_agenda')->fetchAll() as $c) {
                $f = strtolower((string) $c['Field']);
                if ($f === 'remarks')            $hasNextRemarksSave = true;
                if ($f === 'linked_decision_id') $hasLinkedCol       = true;
            }
        } catch (Throwable $e) { /* ignore */ }
        $insN = $db->prepare('INSERT INTO meeting_next_agenda (meeting_id, sort_order, title, description) VALUES (?, ?, ?, ?)');
        $order = 0;
        foreach ((array) ($_POST['next_agenda'] ?? []) as $row) {
            $t = trim((string) ($row['title'] ?? ''));
            if ($t === '') continue;
            $insN->execute([$id, ++$order, $t, trim((string) ($row['description'] ?? '')) ?: null]);
            if ($hasNextRemarksSave) {
                $remarksRaw = trim((string) ($row['remarks'] ?? ''));
                $db->prepare('UPDATE meeting_next_agenda SET remarks = ? WHERE id = ?')
                    ->execute([$remarksRaw === '' ? null : $remarksRaw, (int) $db->lastInsertId()]);
            }
        }
        // Auto-mirror rows (from "Add to next-meeting agenda" checkbox).
        foreach ($autoMirrorRows as $m) {
            $insN->execute([$id, ++$order, $m['title'], $m['description']]);
            $newNaId = (int) $db->lastInsertId();
            if ($hasNextRemarksSave) {
                $db->prepare('UPDATE meeting_next_agenda SET remarks = ? WHERE id = ?')->execute([$m['remarks'], $newNaId]);
            }
            if ($hasLinkedCol) {
                $db->prepare('UPDATE meeting_next_agenda SET linked_decision_id = ? WHERE id = ?')->execute([$m['linked_decision_id'], $newNaId]);
            }
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

/* ---------- Copy to new meeting ---------- */
if (is_post() && ($_POST['action'] ?? '') === 'copy_meeting') {
    csrf_check_or_die();
    if ($existing === null) $flashAndReturn('Source meeting not found.', 'danger', 0);

    $copyGeneral      = isset($_POST['copy_general']);
    $copyParticipants = isset($_POST['copy_participants']);
    $copyAgenda       = isset($_POST['copy_agenda']);
    $copyDecisions    = isset($_POST['copy_decisions']);
    // If this is ticked, the source meeting's next-meeting agenda rows
    // are inserted into the NEW meeting's main Agenda list (appended
    // after any items pulled from "Copy agenda items", so a chair can
    // tick both: carry forward the current agenda AND promote what
    // was planned for next time).
    $copyNextAsAgenda = isset($_POST['copy_next_as_agenda']);

    $db = db();
    $db->query('START TRANSACTION');
    try {
        $sourceDivision = (int) ($existing['division_id'] ?? 0);
        $newRef         = meetings_generate_reference($sourceDivision);
        $divCode        = (string) ($existing['division_code_snapshot'] ?? '');

        // Base defaults for the new meeting. Date is today; from/to
        // time + minutes_body + status start empty so the operator
        // fills them in. previous_meeting_id points at the source so
        // the chain is preserved for cross-reference.
        $newTitle    = $copyGeneral ? ('Copy of ' . (string) $existing['title']) : '(Copy — please update title)';
        $newPurpose  = $copyGeneral ? (string) ($existing['purpose']       ?? '') : '';
        $newLocation = $copyGeneral ? (string) ($existing['location']      ?? '') : '';
        $newVlink    = $copyGeneral ? (string) ($existing['virtual_link']  ?? '') : '';
        $newChairU   = $copyGeneral ? (int)    ($existing['chair_user_id'] ?? 0)  : 0;
        $newChairC   = $copyGeneral ? (int)    ($existing['chair_contact_id'] ?? 0) : 0;

        $db->prepare('INSERT INTO meeting
            (reference_no, title, purpose, meeting_date, from_time, to_time, location, virtual_link,
             chair_user_id, chair_contact_id, division_id, division_code_snapshot, status,
             minutes_body, next_meeting_date, next_meeting_time, previous_meeting_id, is_active,
             created_at, updated_at, created_by, updated_by)
            VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, "scheduled", NULL, NULL, NULL, ?, 1, NOW(), NOW(), ?, ?)')
            ->execute([
                $newRef, $newTitle,
                $newPurpose === '' ? null : $newPurpose,
                date('Y-m-d'),
                $newLocation === '' ? null : $newLocation,
                $newVlink === '' ? null : $newVlink,
                $newChairU > 0 ? $newChairU : null,
                $newChairC > 0 ? $newChairC : null,
                $sourceDivision > 0 ? $sourceDivision : null,
                $divCode === '' ? null : $divCode,
                (int) $existing['id'],
                $viewerId, $viewerId,
            ]);
        $newId = (int) $db->lastInsertId();

        if ($copyParticipants) {
            $insP = $db->prepare('INSERT INTO meeting_participant
                (meeting_id, user_id, contact_id, is_mandatory, attended, role_label, created_at)
                VALUES (?, ?, ?, ?, NULL, ?, NOW())');
            foreach ($existingParticipants as $p) {
                $insP->execute([
                    $newId,
                    ((int) ($p['user_id']    ?? 0)) ?: null,
                    ((int) ($p['contact_id'] ?? 0)) ?: null,
                    (int) ($p['is_mandatory'] ?? 1),
                    trim((string) ($p['role_label'] ?? '')) ?: null,
                ]);
            }
        }

        // Agenda is written by two optional sources: the source
        // meeting's existing agenda, then its next-meeting agenda
        // promoted into the main list. $agendaOrder stays shared
        // across both so the sort_order is sequential.
        $agendaOrder = 0;
        if ($copyAgenda || $copyNextAsAgenda) {
            $insA  = $db->prepare('INSERT INTO meeting_agenda
                (meeting_id, sort_order, title, description, lead_user_id, lead_seat_id, lead_contact_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $insAL = $db->prepare('INSERT INTO meeting_agenda_lead (agenda_id, user_id, seat_id, contact_id) VALUES (?, ?, ?, ?)');
        }
        if ($copyAgenda) {
            foreach ($existingAgenda as $a) {
                $insA->execute([
                    $newId, ++$agendaOrder,
                    (string) $a['title'],
                    trim((string) ($a['description'] ?? '')) ?: null,
                    ((int) ($a['lead_user_id']    ?? 0)) ?: null,
                    ((int) ($a['lead_seat_id']    ?? 0)) ?: null,
                    ((int) ($a['lead_contact_id'] ?? 0)) ?: null,
                ]);
                $newAgendaId = (int) $db->lastInsertId();
                foreach ($existingAgendaLeads[(int) $a['id']] ?? [] as $lead) {
                    $insAL->execute([
                        $newAgendaId,
                        ((int) ($lead['user_id']    ?? 0)) ?: null,
                        ((int) ($lead['seat_id']    ?? 0)) ?: null,
                        ((int) ($lead['contact_id'] ?? 0)) ?: null,
                    ]);
                }
            }
        }
        if ($copyNextAsAgenda) {
            // Pull the source meeting's next-meeting agenda rows (if
            // any) and append them to the new meeting's main agenda.
            // Leads aren't carried — next_agenda has no leads.
            try {
                $pt = $db->prepare('SELECT title, description FROM meeting_next_agenda
                    WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC');
                $pt->execute([(int) $existing['id']]);
                foreach ($pt->fetchAll() as $n) {
                    $insA->execute([
                        $newId, ++$agendaOrder,
                        (string) $n['title'],
                        trim((string) ($n['description'] ?? '')) ?: null,
                        null, null, null,
                    ]);
                }
            } catch (Throwable $e) { /* next_agenda rows absent — skip */ }
        }

        if ($copyDecisions) {
            // Probe schema shape for the optional flag/fan-out/team columns.
            $hasDecFlagsC = false; $hasFanOutC = false; $hasRemarksC = false; $hasRespTeamC = false;
            try {
                $cols = [];
                foreach ($db->query('SHOW COLUMNS FROM meeting_decision')->fetchAll() as $c) $cols[strtolower((string) $c['Field'])] = true;
                $hasDecFlagsC = isset($cols['create_own_tasks']) && isset($cols['status_private']);
                $hasFanOutC   = isset($cols['fan_out_teams']);
                $hasRemarksC  = isset($cols['remarks']);
            } catch (Throwable $e) { /* ignore */ }
            try {
                $cols2 = [];
                foreach ($db->query('SHOW COLUMNS FROM meeting_decision_responsible')->fetchAll() as $c) $cols2[strtolower((string) $c['Field'])] = true;
                $hasRespTeamC = isset($cols2['team_id']);
            } catch (Throwable $e) { /* ignore */ }

            if ($hasDecFlagsC && $hasFanOutC) {
                $insD = $db->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date, create_own_tasks, status_private, fan_out_teams) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            } elseif ($hasDecFlagsC) {
                $insD = $db->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date, create_own_tasks, status_private) VALUES (?, ?, ?, ?, ?, ?, ?)');
            } else {
                $insD = $db->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date) VALUES (?, ?, ?, ?, ?)');
            }
            $insR = $hasRespTeamC
                ? $db->prepare('INSERT INTO meeting_decision_responsible (decision_id, user_id, contact_id, seat_id, team_id) VALUES (?, ?, ?, ?, ?)')
                : $db->prepare('INSERT INTO meeting_decision_responsible (decision_id, user_id, contact_id, seat_id) VALUES (?, ?, ?, ?)');
            $order = 0;
            foreach ($existingDecisions as $d) {
                $params = [$newId, ++$order,
                    (string) $d['heading'],
                    trim((string) ($d['description'] ?? '')) ?: null,
                    (string) ($d['due_date'] ?? '') ?: null];
                if ($hasDecFlagsC)                 { $params[] = (int) ($d['create_own_tasks'] ?? 1); $params[] = (int) ($d['status_private'] ?? 0); }
                if ($hasDecFlagsC && $hasFanOutC)  { $params[] = (int) ($d['fan_out_teams'] ?? 0); }
                $insD->execute($params);
                $newDecId = (int) $db->lastInsertId();
                if ($hasRemarksC) {
                    $db->prepare('UPDATE meeting_decision SET remarks = ? WHERE id = ?')
                        ->execute([trim((string) ($d['remarks'] ?? '')) ?: null, $newDecId]);
                }
                foreach ($existingDecisionResp[(int) $d['id']] ?? [] as $r) {
                    $uid = ((int) ($r['user_id']    ?? 0)) ?: null;
                    $cid = ((int) ($r['contact_id'] ?? 0)) ?: null;
                    $sid = ((int) ($r['seat_id']    ?? 0)) ?: null;
                    $tid = ((int) ($r['team_id']    ?? 0)) ?: null;
                    if ($hasRespTeamC) $insR->execute([$newDecId, $uid, $cid, $sid, $tid]);
                    else               $insR->execute([$newDecId, $uid, $cid, $sid]);
                }
            }
        }

        $db->query('COMMIT');
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['meeting_edit_flash'] = ['msg' => 'Copied to ' . $newRef . '. Update the date, time and the rest below, then save.', 'type' => 'success'];
        header('Location: /meeting_edit.php?id=' . $newId);
        exit;
    } catch (Throwable $e) {
        try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
        $flashAndReturn('Copy failed: ' . $e->getMessage(), 'danger', (int) $existing['id']);
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

// Flash messages written by the Copy-to-new-meeting handler land here
// (the usual save handler bounces to meeting_view.php and uses the
// meeting_view_flash key).
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$editFlashMessage = null; $editFlashType = 'success';
if (!empty($_SESSION['meeting_edit_flash']) && is_array($_SESSION['meeting_edit_flash'])) {
    $editFlashMessage = (string) ($_SESSION['meeting_edit_flash']['msg']  ?? '');
    $editFlashType    = (string) ($_SESSION['meeting_edit_flash']['type'] ?? 'success');
    unset($_SESSION['meeting_edit_flash']);
}

render_header('Meetings · ' . $pageTitle, ['main_container_class' => 'container-xl']);
render_page_header($pageTitle, [
    'icon' => 'bi-calendar2-week',
    'subtitle' => $existing ? 'Edit fields, participants, agenda, decisions. Saves in one transaction.' : 'Create a new meeting. A reference number is allocated automatically from the division.',
    'actions' => ($existing
            ? '<a class="btn btn-outline-success" href="/meeting_bulk_import.php?id=' . (int) $existing['id'] . '"><i class="bi bi-file-earmark-arrow-up me-1"></i>Bulk upload minutes</a>'
                . '<button type="button" class="btn btn-outline-primary ms-2" data-bs-toggle="modal" data-bs-target="#copyMeetingModal"><i class="bi bi-copy me-1"></i>Copy to new meeting</button>'
            : '')
        . '<a class="btn btn-light ms-2" href="/meetings.php"><i class="bi bi-arrow-left me-1"></i>Back to Meetings</a>',
]);
?>

<?php if ($editFlashMessage !== null): ?>
    <div class="alert alert-<?= esc($editFlashType) ?>"><?= esc($editFlashMessage) ?></div>
<?php endif; ?>

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
                <label class="form-label d-flex justify-content-between align-items-center">
                    <span>Previous meeting <span class="small text-muted">(optional — link to earlier record in the series)</span></span>
                    <?php if ($existing): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="importPrevBtn" disabled>
                            <i class="bi bi-arrow-down-circle me-1"></i>Import from previous
                        </button>
                    <?php endif; ?>
                </label>
                <select class="form-select ts-search" name="previous_meeting_id" id="prevMeetingSelect">
                    <option value="0">— None —</option>
                    <?php
                    try {
                        $prev = db()->query('SELECT id, reference_no, title, meeting_date FROM meeting WHERE is_active = 1 ORDER BY meeting_date DESC LIMIT 200')->fetchAll();
                    } catch (Throwable $e) { $prev = []; }
                    $prevMap = [];
                    foreach ($prev as $p):
                        if ($existing && (int) $p['id'] === (int) $existing['id']) continue; // don't allow self-link
                        $prevMap[(int) $p['id']] = [
                            'reference_no' => (string) $p['reference_no'],
                            'title'        => (string) $p['title'],
                            'meeting_date' => (string) ($p['meeting_date'] ?? ''),
                        ];
                    ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $formValues['previous_meeting_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $p['reference_no']) ?> · <?= esc((string) $p['title']) ?><?= !empty($p['meeting_date']) ? ' · ' . esc(date('d/m/Y', strtotime((string) $p['meeting_date']))) : '' ?>
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
        <!-- Two-column shape: main editor on the left, participant +
             URL rail on the right. Mirrors the view page's layout so
             the edit screen reads like the meeting it is going to
             become. -->
        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3 meeting-section">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-list-ol me-1"></i>Agenda</span>
                        <?php if ($existing): ?>
                        <div class="dropdown">
                            <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-plus-lg me-1"></i>Add agenda
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="/meeting_grid.php?id=<?= (int) $existing['id'] ?>&type=agenda" target="_blank"><i class="bi bi-grid-3x3-gap me-2"></i>Agenda bulk entry <span class="small text-muted">(spreadsheet, new tab)</span></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#" data-open-modal="agendaModal"><i class="bi bi-plus-lg me-2"></i>Add single agenda item</a></li>
                            </ul>
                        </div>
                        <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-open-modal="agendaModal"><i class="bi bi-plus-lg me-1"></i>Add agenda item</button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body" id="agendaList"></div>
                </div>
                <div id="agendaHidden"></div>

                <div class="card mb-3 meeting-section">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-check2-square me-1"></i>Decision points</span>
                        <?php if ($existing): ?>
                        <div class="dropdown">
                            <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-plus-lg me-1"></i>Add decision
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="/meeting_grid.php?id=<?= (int) $existing['id'] ?>&type=decision" target="_blank"><i class="bi bi-grid-3x3-gap me-2"></i>Decision points bulk entry <span class="small text-muted">(spreadsheet, new tab)</span></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#" data-open-modal="decisionModal"><i class="bi bi-plus-lg me-2"></i>Add single decision</a></li>
                            </ul>
                        </div>
                        <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-open-modal="decisionModal"><i class="bi bi-plus-lg me-1"></i>Add decision</button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body" id="decisionList"></div>
                </div>
                <div id="decisionHidden"></div>

                <div class="card mb-3 meeting-section">
                    <div class="card-header"><i class="bi bi-calendar-plus me-1"></i>Next meeting <span class="small text-muted">(optional)</span></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Next meeting date</label>
                                <input type="date" class="form-control" name="next_meeting_date" value="<?= esc((string) $formValues['next_meeting_date']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Next meeting time</label>
                                <input type="time" class="form-control" name="next_meeting_time" value="<?= esc(substr((string) $formValues['next_meeting_time'], 0, 5)) ?>">
                            </div>
                        </div>
                        <hr class="my-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-semibold">Next-meeting agenda</span>
                            <?php if ($existing): ?>
                            <div class="dropdown">
                                <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="bi bi-plus-lg me-1"></i>Add
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="/meeting_grid.php?id=<?= (int) $existing['id'] ?>&type=next_agenda" target="_blank"><i class="bi bi-grid-3x3-gap me-2"></i>Next-meeting bulk entry <span class="small text-muted">(spreadsheet, new tab)</span></a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="#" data-open-modal="nextAgendaModal"><i class="bi bi-plus-lg me-2"></i>Add single item</a></li>
                                </ul>
                            </div>
                            <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-open-modal="nextAgendaModal"><i class="bi bi-plus-lg me-1"></i>Add next-meeting item</button>
                            <?php endif; ?>
                        </div>
                        <div id="nextAgendaList"></div>
                    </div>
                </div>
                <div id="nextAgendaHidden"></div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3 meeting-section">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-people me-1"></i>Participants <span class="badge text-bg-light border ms-1" id="participantCount">0</span></span>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-open-modal="participantModal"><i class="bi bi-plus-lg"></i></button>
                    </div>
                    <div class="card-body p-2" id="participantList"></div>
                </div>
                <div id="participantHidden"></div>

                <div class="card mb-3 meeting-section">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-link-45deg me-1"></i>Attachment URLs</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-open-modal="urlModal"><i class="bi bi-plus-lg"></i></button>
                    </div>
                    <div class="card-body p-2" id="urlList"></div>
                </div>
                <div id="urlHidden"></div>
            </div>
        </div>
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
    teams:    <?= json_encode(array_map(static fn($t) => ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'head_name' => (string) ($t['head_name'] ?? ''), 'member_count' => count($t['members'] ?? [])], $teams), JSON_UNESCAPED_UNICODE) ?>,
    teams_enabled: <?= $teamsHasTeamId ? 'true' : 'false' ?>,
    fan_out_enabled: <?= $teamsHasFanOut ? 'true' : 'false' ?>,
    prev_meta: <?= json_encode($prevMap ?? [], JSON_UNESCAPED_UNICODE) ?>,
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
                'remarks' => (string) ($a['remarks'] ?? ''),
                'user_ids' => $uIds, 'seat_ids' => $sIds, 'contact_ids' => $cIds,
            ];
        }, $existingAgenda)) ?>,
        decisions: <?= json_encode(array_map(static function ($d) use ($existingDecisionResp, $decisionIdsWithNextMirror) {
            $rs = $existingDecisionResp[(int) $d['id']] ?? [];
            return [
                'id' => (int) $d['id'],
                'heading' => (string) $d['heading'],
                'description' => (string) ($d['description'] ?? ''),
                'due_date' => substr((string) ($d['due_date'] ?? ''), 0, 10),
                'create_own_tasks' => isset($d['create_own_tasks']) ? (int) $d['create_own_tasks'] : 1,
                'status_private'   => isset($d['status_private'])   ? (int) $d['status_private']   : 0,
                'fan_out_teams'    => isset($d['fan_out_teams'])    ? (int) $d['fan_out_teams']    : 0,
                'remarks'          => (string) ($d['remarks'] ?? ''),
                'agenda_id'        => isset($d['agenda_id']) ? (int) $d['agenda_id'] : 0,
                'add_to_next_agenda' => isset($decisionIdsWithNextMirror[(int) $d['id']]) ? 1 : 0,
                'user_ids' => array_values(array_filter(array_map(static fn($r) => (int) ($r['user_id'] ?? 0), $rs))),
                'seat_ids' => array_values(array_filter(array_map(static fn($r) => (int) ($r['seat_id'] ?? 0), $rs))),
                'contact_ids' => array_values(array_filter(array_map(static fn($r) => (int) ($r['contact_id'] ?? 0), $rs))),
                'team_ids' => array_values(array_filter(array_map(static fn($r) => (int) ($r['team_id'] ?? 0), $rs))),
            ];
        }, $existingDecisions)) ?>,
        next_agenda: <?= json_encode(array_map(static fn($n) => ['id' => (int) $n['id'], 'title' => (string) $n['title'], 'description' => (string) ($n['description'] ?? ''), 'remarks' => (string) ($n['remarks'] ?? ''), 'linked_decision_id' => isset($n['linked_decision_id']) ? (int) $n['linked_decision_id'] : 0], $existingNextAgenda)) ?>,
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

/* ---------- Participant / Agenda / Decision / Next-Agenda tables ----
 * Fixed table layout keeps columns stable regardless of content length;
 * long Description + Responsible + Remarks are clipped to a 3-line box
 * with a mouse-over `title` so the full text is one hover away. Line
 * height is pinned so clipped and un-clipped cells share the same row
 * height — no more "one tall row + one short row" accordion. */
.meeting-sub-table               { table-layout: fixed; width: 100%; }
.meeting-sub-table thead th      { font-size: .78rem; letter-spacing: .02em; text-transform: uppercase; color: #475569; background: #f8fafc; }
.meeting-sub-table th,
.meeting-sub-table td            { overflow: hidden; vertical-align: top; padding: 8px 10px; }
.meeting-sub-table td.text-end   { padding-right: 10px; }
.meeting-sub-table td            { line-height: 1.35; font-size: .88rem; }

/* 3-line clamp with ellipsis. One utility, used on Description /
 * Responsible / Remarks. The explicit max-height is a fallback for
 * browsers without -webkit-line-clamp — gives the same visual bound. */
.meeting-sub-table .cell-clamp2,
.meeting-sub-table .cell-clamp {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    word-break: break-word;
    line-height: 1.35;
    max-height: calc(1.35em * 3);
    cursor: help;
}
.meeting-sub-table .cell-wrap    { word-break: break-word; white-space: normal; }
.meeting-sub-table .remarks-cell { background: #fff8e1; }      /* soft amber */
.meeting-sub-table .remarks-cell:empty { background: transparent; }
.meeting-sub-table .cell-muted-empty::before { content: '—'; color: #cbd5e1; }
.meeting-sub-table .action-col   { white-space: nowrap; }

/* Bootstrap table-sm trims padding too aggressively for a 3-line
 * clamp — restore a comfortable vertical rhythm. */
.table-sm.meeting-sub-table > :not(caption) > * > * { padding: 8px 10px; }
.pm-check-list .pm-row { display: flex; align-items: center; gap: 6px; padding: 3px 0; }
.pm-check-list .pm-row label { margin: 0; cursor: pointer; line-height: 1.3; }
.pm-check-list .pm-row:hover { background: #f8fafc; }
.pm-check-list .pm-empty { color: #94a3b8; text-align: center; padding: 20px 0; font-size: .85rem; }

/* ---------- View-page-style section lists (replaces the sub-tables) --- */
.meeting-section .card-header         { font-weight: 600; }
.meeting-list-item                    { font-size: .9rem; }
.meeting-list-item:last-child         { border-bottom: 0 !important; }
.meeting-list-item .meeting-sl        { min-width: 1.75rem; padding-top: 0.1rem; }
.meeting-group-header                 { letter-spacing: .02em; }

/* ---------- Inline edit (agenda row) ---------- */
.meeting-list-edit                     { background: #f8fbff; padding-left: 10px !important; padding-right: 10px !important; border-radius: 4px; }
.agenda-lead-pill                      { font-weight: 500; padding: 4px 8px; }
.agenda-lead-pill .btn-close           { opacity: .55; }
.agenda-lead-pill .btn-close:hover     { opacity: 1; }
.agenda-leads-dropdown                 { position: absolute; top: calc(100% + 2px); left: 0; right: 0; z-index: 1050; max-height: 240px; overflow-y: auto; border-radius: 4px; }
.agenda-leads-section-label            { background: #f1f5f9; font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; }
.agenda-leads-option                   { padding: 6px 10px; cursor: pointer; font-size: .88rem; }
.agenda-leads-option:hover             { background: #eff6ff; }
.agenda-leads-empty                    { font-style: italic; }
</style>

<!-- Agenda modal — three checkbox lists for leads (multi-select). -->
<div class="mm-modal" id="agendaModal" style="display:none; width: min(960px, 94vw);">
    <div class="mm-header"><span><i class="bi bi-list-ol me-1"></i>Agenda item</span><button type="button" class="btn-close" data-close-modal></button></div>
    <div class="mm-body">
        <input type="hidden" id="aModalIdx" value="">
        <div class="mb-2"><label class="form-label small">Title *</label><input class="form-control" id="aModalTitle"></div>
        <div class="mb-2"><label class="form-label small">Description</label><textarea class="form-control" id="aModalDesc" rows="2"></textarea></div>
        <div class="mb-3"><label class="form-label small">Remarks <span class="text-muted">(internal note; also populated automatically by bulk-import warnings)</span></label><textarea class="form-control" id="aModalRemarks" rows="2"></textarea></div>
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
<div class="mm-modal" id="decisionModal" style="display:none; width: min(1140px, 96vw);">
    <div class="mm-header"><span><i class="bi bi-check2-square me-1"></i>Decision point</span><button type="button" class="btn-close" data-close-modal></button></div>
    <div class="mm-body">
        <input type="hidden" id="dModalIdx" value="">
        <div class="row g-2 mb-2">
            <div class="col-md-8"><label class="form-label small">Heading *</label><input class="form-control" id="dModalHead"></div>
            <div class="col-md-4"><label class="form-label small">Agenda</label>
                <select class="form-select" id="dModalAgenda" title="Group this decision under one of the meeting's agenda items. Leave as Other for decisions that don't belong to a specific agenda.">
                    <option value="">Other</option>
                </select>
            </div>
        </div>
        <div class="mb-2"><label class="form-label small">Description</label><textarea class="form-control" id="dModalDesc" rows="3"></textarea></div>
        <div class="mb-2"><label class="form-label small">Remarks <span class="text-muted">(optional)</span></label><textarea class="form-control" id="dModalRemarks" rows="2" placeholder="Internal notes about this decision (not sent to anyone)."></textarea></div>
        <div class="row g-2 mb-3 align-items-end">
            <div class="col-md-3"><label class="form-label small">Due date</label><input type="date" class="form-control" id="dModalDue"></div>
            <div class="col-md-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="dModalCreateOwn" checked>
                    <label class="form-check-label small" for="dModalCreateOwn"><strong>Show in Own Tasks</strong> under Project Management</label>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="dModalPrivate">
                    <label class="form-check-label small" for="dModalPrivate"><strong>Show status only to the owner</strong> (hidden from heads/others)</label>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="dModalFanOut">
                    <label class="form-check-label small" for="dModalFanOut"><strong>Fan out team tasks</strong> — create a task for every team member (default: team head only)</label>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="dModalAddToNext">
                    <label class="form-check-label small" for="dModalAddToNext"><strong>Add to next-meeting agenda</strong> — mirrors heading → title, remarks → description, responsibles → remarks. Linked, auto-updates on edit/delete.</label>
                </div>
            </div>
        </div>
        <div class="small text-muted mb-2">Pick the people responsible. When <em>Show in Own Tasks</em> is on, each Internal User who holds a seat gets their <strong>own</strong> task row. For teams, the task lands on the team head's seat; tick <em>Fan out</em> to spawn one task per member instead.</div>
        <div class="row g-3">
            <div class="col-md-6 col-lg-3">
                <label class="form-label small fw-semibold"><i class="bi bi-people me-1"></i>Participants <span class="text-muted small">(this meeting)</span></label>
                <input type="text" class="form-control form-control-sm mb-2" id="dSearchParts" placeholder="Search participants…">
                <div class="pm-check-list" id="dListParts"></div>
                <div class="small text-muted mt-1" id="dListPartsCount"></div>
            </div>
            <div class="col-md-6 col-lg-3">
                <label class="form-label small fw-semibold"><i class="bi bi-person-badge me-1"></i>Other Internal Users</label>
                <input type="text" class="form-control form-control-sm mb-2" id="dSearchUsers" placeholder="Search internal users…">
                <div class="pm-check-list" id="dListUsers"></div>
                <div class="small text-muted mt-1" id="dListUsersCount"></div>
            </div>
            <div class="col-md-6 col-lg-3">
                <label class="form-label small fw-semibold"><i class="bi bi-person-vcard me-1"></i>Other External Users</label>
                <input type="text" class="form-control form-control-sm mb-2" id="dSearchContacts" placeholder="Search external users…">
                <div class="pm-check-list" id="dListContacts"></div>
                <div class="small text-muted mt-1" id="dListContactsCount"></div>
            </div>
            <div class="col-md-6 col-lg-3" id="dTeamsCol">
                <label class="form-label small fw-semibold"><i class="bi bi-people-fill me-1"></i>Teams</label>
                <input type="text" class="form-control form-control-sm mb-2" id="dSearchTeams" placeholder="Search teams…">
                <div class="pm-check-list" id="dListTeams"></div>
                <div class="small text-muted mt-1" id="dListTeamsCount"></div>
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
        <div class="mb-2"><label class="form-label small">Remarks <span class="text-muted">(internal note; also populated automatically by bulk-import warnings)</span></label><textarea class="form-control" id="nModalRemarks" rows="2"></textarea></div>
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
    // tmp_id is a client-only stable id used to re-link decisions to
    // agenda items across the big-save handler's DELETE+INSERT cycle
    // (the DB id of each agenda row changes on every save, so we can't
    // reference it in a cross-table way client-side).
    const makeTmp = () => (crypto && crypto.randomUUID ? crypto.randomUUID() : ('t-' + Math.random().toString(36).slice(2) + Date.now()));
    const state = {
        participants: (R.preset.participants || []).slice(),
        agenda:       (R.preset.agenda || []).map(a => ({ ...a, tmp_id: makeTmp() })),
        decisions:    (R.preset.decisions || []).slice(),
        next_agenda:  (R.preset.next_agenda || []).slice(),
        urls:         (R.preset.urls || []).slice(),
    };
    // Seed decision.agenda_tmp_id from the loaded agenda_id -> tmp_id
    // mapping so edits land on the same agenda after the save flip.
    (() => {
        const dbIdToTmp = {};
        state.agenda.forEach(a => { if (a.id) dbIdToTmp[String(a.id)] = a.tmp_id; });
        state.decisions.forEach(d => {
            const aid = Number(d.agenda_id || 0);
            d.agenda_tmp_id = aid > 0 ? (dbIdToTmp[String(aid)] || '') : '';
        });
    })();
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

    // ============ LIST RENDERERS (view-page style) ============
    // Each section is a stacked block of items with pencil + x icons on
    // the right. Pencil opens the existing modal; x deletes the row via
    // the same AJAX endpoint the old table rows used.
    const renderParticipants = () => {
        const list = document.getElementById('participantList');
        document.getElementById('participantCount').textContent = state.participants.length;
        list.innerHTML = state.participants.map((p, i) => {
            const name = p.user_id ? nameOf(R.users, p.user_id) : (p.contact_id ? nameOf(contactsPool, p.contact_id) : '—');
            const sub  = p.contact_id ? (findById(contactsPool, p.contact_id)?.inst || '') : '';
            const role = p.role_label || '';
            const mandMark = String(p.is_mandatory) === '1'
                ? '<span class="text-danger fw-bold" title="Mandatory" style="font-size:1.1rem; line-height:1;">*</span>'
                : '<span class="text-muted" title="Optional" style="width:0.7rem; display:inline-block;"></span>';
            const current = String(p.attended ?? '');
            const presentActive = current === '1';
            const absentActive  = current === '0';
            const attendPill = presentActive
                ? '<span class="badge text-bg-success" title="Present">P</span>'
                : (absentActive ? '<span class="badge text-bg-danger" title="Absent">A</span>'
                    : '<span class="badge text-bg-light border" title="Attendance not recorded">—</span>');
            const subRow = sub ? `<div class="text-muted small">${esc(sub)}</div>` : '';
            const roleRow = role ? `<div class="text-muted small"><i class="bi bi-tag me-1"></i>${esc(role)}</div>` : '';
            return `<div class="meeting-list-item d-flex align-items-start gap-2 py-2 px-1 border-bottom">
                ${mandMark}
                <div class="flex-grow-1 small">
                    <strong>${esc(name)}</strong>
                    ${subRow}
                    ${roleRow}
                </div>
                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                    <button type="button" class="btn btn-sm btn-link p-0 pe-1" data-attend-set="${i}:1" title="${presentActive ? 'Click to clear' : 'Mark as Present'}">
                        <span class="${presentActive ? 'badge text-bg-success' : 'badge text-bg-light border'}">P</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 pe-1" data-attend-set="${i}:0" title="${absentActive ? 'Click to clear' : 'Mark as Absent'}">
                        <span class="${absentActive ? 'badge text-bg-danger' : 'badge text-bg-light border'}">A</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" data-edit-participant="${i}" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger  py-0 px-1" data-del-participant="${i}"  title="Delete"><i class="bi bi-x"></i></button>
                </div>
            </div>`;
        }).join('') || '<div class="text-muted small p-2">No participants yet — click the + to add.</div>';
        const hidden = document.getElementById('participantHidden');
        hidden.innerHTML = state.participants.map((p, i) => {
            return `<input type="hidden" name="participant[${i}][user_id]"      value="${p.user_id || 0}">
                    <input type="hidden" name="participant[${i}][contact_id]"   value="${p.contact_id || 0}">
                    <input type="hidden" name="participant[${i}][is_mandatory]" value="${p.is_mandatory ?? 1}">
                    <input type="hidden" name="participant[${i}][attended]"     value="${p.attended ?? ''}">
                    <input type="hidden" name="participant[${i}][role_label]"   value="${esc(p.role_label || '')}">`;
        }).join('');
    };
    // Rendered agenda item has two modes: a static display row (default)
    // and an inline edit row (triggered by the pencil icon). Only one
    // row at a time is in edit mode; agendaEditIdx holds its index so
    // the next render reinstates the edit shell after any state churn.
    let agendaEditIdx = null;
    const renderAgendaItemView = (a, i) => {
        const leads = [
            ...((a.user_ids    || []).map(id => nameOf(R.users,       id))),
            ...((a.seat_ids    || []).map(id => nameOf(R.seats,       id))),
            ...((a.contact_ids || []).map(id => nameOf(contactsPool,  id))),
        ].filter(Boolean);
        const descBlock    = (a.description || '').trim() === '' ? '' : `<div class="small text-muted mt-1" style="white-space:pre-wrap;">${esc(a.description)}</div>`;
        const remarksBlock = (a.remarks     || '').trim() === '' ? '' : `<div class="small text-warning-emphasis mt-1" style="white-space:pre-wrap;"><i class="bi bi-exclamation-triangle me-1"></i><em>Remarks:</em> ${esc(a.remarks)}</div>`;
        const leadsBlock   = leads.length === 0 ? '' : `<div class="small text-muted mt-1"><i class="bi bi-person-check me-1"></i>Lead: ${esc(leads.join(', '))}</div>`;
        return `<div class="meeting-list-item d-flex align-items-start gap-2 py-2 border-bottom">
            <div class="meeting-sl text-muted small">${i+1}.</div>
            <div class="flex-grow-1">
                <strong>${esc(a.title || '')}</strong>
                ${descBlock}
                ${leadsBlock}
                ${remarksBlock}
            </div>
            <div class="d-flex flex-shrink-0 gap-1">
                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" data-edit-agenda-inline="${i}" title="Edit"><i class="bi bi-pencil"></i></button>
                <button type="button" class="btn btn-sm btn-outline-danger  py-0 px-1" data-del-agenda="${i}"  title="Delete"><i class="bi bi-x"></i></button>
            </div>
        </div>`;
    };
    // Inline edit template — a pencil click swaps the view row for this
    // one. Title / description / remarks become textareas; leads is a
    // pill multi-select driven by a search dropdown. Save calls the
    // same upsert_agenda endpoint the modal does; cancel just re-renders.
    const renderAgendaItemEdit = (a, i) => {
        const pills = [
            ...((a.user_ids    || []).filter(Boolean).map(id => ({ type: 'u', id, name: nameOf(R.users, id) }))),
            ...((a.contact_ids || []).filter(Boolean).map(id => ({ type: 'c', id, name: nameOf(contactsPool, id) }))),
            ...((a.seat_ids    || []).filter(Boolean).map(id => ({ type: 's', id, name: nameOf(R.seats, id) }))),
        ].filter(p => p.name);
        const pillHtml = pills.map(p =>
            `<span class="agenda-lead-pill badge text-bg-light border d-inline-flex align-items-center gap-1" data-lead-ref="${p.type}${p.id}">
                ${esc(p.name)}
                <button type="button" class="btn-close btn-close-sm js-remove-lead" aria-label="Remove" style="font-size:0.55rem;"></button>
            </span>`
        ).join('');
        return `<div class="meeting-list-item meeting-list-edit d-flex align-items-start gap-2 py-2 border-bottom" data-agenda-edit-row="${i}">
            <div class="meeting-sl text-muted small">${i+1}.</div>
            <div class="flex-grow-1">
                <input class="form-control form-control-sm mb-2 js-agenda-title" placeholder="Title *" value="${esc(a.title || '')}">
                <textarea class="form-control form-control-sm mb-2 js-agenda-desc" rows="2" placeholder="Description">${esc(a.description || '')}</textarea>
                <div class="agenda-leads-widget mb-2" data-leads-widget>
                    <div class="d-flex flex-wrap gap-1 mb-1 js-leads-pills">${pillHtml}</div>
                    <div class="position-relative">
                        <input type="text" class="form-control form-control-sm js-leads-input" placeholder="Add lead — search participants or users…" autocomplete="off">
                        <div class="agenda-leads-dropdown shadow-sm border bg-white js-leads-dropdown" style="display:none;"></div>
                    </div>
                </div>
                <textarea class="form-control form-control-sm js-agenda-remarks" rows="1" placeholder="Remarks (optional)">${esc(a.remarks || '')}</textarea>
            </div>
            <div class="d-flex flex-shrink-0 gap-1">
                <button type="button" class="btn btn-sm btn-primary py-0 px-2" data-save-agenda-inline="${i}" title="Save"><i class="bi bi-check2"></i></button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-cancel-agenda-inline="${i}" title="Cancel"><i class="bi bi-x"></i></button>
            </div>
        </div>`;
    };
    const renderAgendaItem = (a, i) => (agendaEditIdx === i ? renderAgendaItemEdit(a, i) : renderAgendaItemView(a, i));
    const renderAgenda = () => {
        const list = document.getElementById('agendaList');
        list.innerHTML = state.agenda.map(renderAgendaItem).join('') || '<div class="text-muted small">No agenda items yet.</div>';
        // Focus the title input when a row has just entered edit mode.
        if (agendaEditIdx !== null) {
            const row = list.querySelector(`[data-agenda-edit-row="${agendaEditIdx}"]`);
            row?.querySelector('.js-agenda-title')?.focus();
        }
        const hidden = document.getElementById('agendaHidden');
        hidden.innerHTML = state.agenda.map((a, i) => {
            const u = (a.user_ids    || [])[0] || 0;
            const s = (a.seat_ids    || [])[0] || 0;
            const c = (a.contact_ids || [])[0] || 0;
            return `<input type="hidden" name="agenda[${i}][tmp_id]"          value="${esc(a.tmp_id || '')}">
                    <input type="hidden" name="agenda[${i}][title]"           value="${esc(a.title || '')}">
                    <input type="hidden" name="agenda[${i}][description]"     value="${esc(a.description || '')}">
                    <input type="hidden" name="agenda[${i}][remarks]"         value="${esc(a.remarks || '')}">
                    <input type="hidden" name="agenda[${i}][lead_user_id]"    value="${u}">
                    <input type="hidden" name="agenda[${i}][lead_seat_id]"    value="${s}">
                    <input type="hidden" name="agenda[${i}][lead_contact_id]" value="${c}">`;
        }).join('');
    };

    // ---------- Inline-edit helpers (leads pill multi-select) ----------
    // Build the dropdown HTML for the leads widget. Pool = participants
    // first, then other internal users, then other external users.
    // Already-picked leads (by ref 'u<id>'/'c<id>') are filtered out
    // along with any search-text non-match.
    const buildLeadDropdown = (needleRaw, excludeRefs) => {
        const needle = (needleRaw || '').trim().toLowerCase();
        const match = (n) => !needle || String(n || '').toLowerCase().includes(needle);
        const partsU = new Set(), partsC = new Set();
        state.participants.forEach(p => {
            if (p.user_id > 0)    partsU.add(p.user_id);
            if (p.contact_id > 0) partsC.add(p.contact_id);
        });
        const section = (label, items) => items.length === 0 ? '' :
            `<div class="agenda-leads-section-label small text-muted px-2 py-1">${label}</div>` +
            items.map(x => `<div class="agenda-leads-option js-pick-lead" data-lead-type="${x.type}" data-lead-id="${x.id}">${esc(x.name)}${x.inst ? ` <span class="text-muted small">· ${esc(x.inst)}</span>` : ''}</div>`).join('');
        const participantOpts = [];
        state.participants.forEach(p => {
            if (p.user_id > 0) {
                const u = findById(R.users, p.user_id);
                if (u && !excludeRefs.has('u' + u.id) && match(u.name)) participantOpts.push({ type: 'u', id: u.id, name: u.name, inst: '' });
            }
            if (p.contact_id > 0) {
                const c = findById(contactsPool, p.contact_id);
                if (c && !excludeRefs.has('c' + c.id) && match(c.name + ' ' + (c.inst || ''))) participantOpts.push({ type: 'c', id: c.id, name: c.name, inst: c.inst || '' });
            }
        });
        const otherUserOpts = R.users
            .filter(u => !partsU.has(u.id) && !excludeRefs.has('u' + u.id) && match(u.name))
            .map(u => ({ type: 'u', id: u.id, name: u.name, inst: '' }));
        const otherContactOpts = contactsPool
            .filter(c => !partsC.has(c.id) && !excludeRefs.has('c' + c.id) && match(c.name + ' ' + (c.inst || '')))
            .map(c => ({ type: 'c', id: c.id, name: c.name, inst: c.inst || '' }));
        const html = section('Participants',          sortByName(participantOpts).slice(0, 20))
                   + section('Other internal users',  sortByName(otherUserOpts).slice(0, 20))
                   + section('Other external users',  sortByName(otherContactOpts).slice(0, 20));
        return html || '<div class="agenda-leads-empty small text-muted px-2 py-2">No matches.</div>';
    };
    const refreshLeadDropdown = (widget) => {
        const input = widget.querySelector('.js-leads-input');
        const dd    = widget.querySelector('.js-leads-dropdown');
        const picked = new Set(Array.from(widget.querySelectorAll('.agenda-lead-pill')).map(el => el.dataset.leadRef));
        dd.innerHTML = buildLeadDropdown(input.value, picked);
        dd.style.display = 'block';
    };
    const pickLeadInWidget = (optionEl) => {
        const widget = optionEl.closest('[data-leads-widget]');
        if (!widget) return;
        const type = optionEl.dataset.leadType; const id = Number(optionEl.dataset.leadId);
        const name = optionEl.firstChild?.textContent?.trim() || optionEl.textContent.trim();
        const pillsBox = widget.querySelector('.js-leads-pills');
        if (pillsBox.querySelector(`[data-lead-ref="${type}${id}"]`)) return;
        pillsBox.insertAdjacentHTML('beforeend',
            `<span class="agenda-lead-pill badge text-bg-light border d-inline-flex align-items-center gap-1" data-lead-ref="${type}${id}">
                ${esc(name)}
                <button type="button" class="btn-close btn-close-sm js-remove-lead" aria-label="Remove" style="font-size:0.55rem;"></button>
            </span>`);
        const input = widget.querySelector('.js-leads-input');
        input.value = ''; refreshLeadDropdown(widget); input.focus();
    };
    // Search box events + outside-click close.
    document.addEventListener('input', (ev) => {
        if (!ev.target.classList.contains('js-leads-input')) return;
        const widget = ev.target.closest('[data-leads-widget]');
        if (widget) refreshLeadDropdown(widget);
    });
    document.addEventListener('focusin', (ev) => {
        if (!ev.target.classList.contains('js-leads-input')) return;
        const widget = ev.target.closest('[data-leads-widget]');
        if (widget) refreshLeadDropdown(widget);
    });
    document.addEventListener('click', (ev) => {
        if (ev.target.closest('[data-leads-widget]')) return;
        document.querySelectorAll('.js-leads-dropdown').forEach(dd => dd.style.display = 'none');
    });
    // Enter inside an inline-edit input would otherwise submit the big
    // form. Catch it and route to Save; Escape cancels.
    document.addEventListener('keydown', (ev) => {
        const inEditRow = ev.target.closest('[data-agenda-edit-row]');
        if (!inEditRow) return;
        if (ev.key === 'Enter' && ev.target.classList.contains('js-agenda-title')) {
            ev.preventDefault();
            saveAgendaInline(+inEditRow.dataset.agendaEditRow);
        } else if (ev.key === 'Escape') {
            ev.preventDefault();
            agendaEditIdx = null;
            renderAgenda();
        }
    });
    const saveAgendaInline = async (i) => {
        const row = document.querySelector(`[data-agenda-edit-row="${i}"]`);
        if (!row) return;
        const title = row.querySelector('.js-agenda-title').value.trim();
        if (title === '') { alert('Title required.'); return; }
        const desc    = row.querySelector('.js-agenda-desc').value.trim();
        const remarks = row.querySelector('.js-agenda-remarks').value.trim();
        const userIds = [], contactIds = [], seatIds = [];
        row.querySelectorAll('.agenda-lead-pill').forEach(pill => {
            const ref = pill.dataset.leadRef || '';
            const t = ref[0]; const id = Number(ref.slice(1));
            if (!id) return;
            if (t === 'u') userIds.push(id);
            else if (t === 'c') contactIds.push(id);
            else if (t === 's') seatIds.push(id);
        });
        const existing = state.agenda[i];
        const payload = {
            id: existing?.id || 0,
            title, description: desc, remarks,
            user_ids: userIds, contact_ids: contactIds, seat_ids: seatIds,
        };
        agendaEditIdx = null; // leave edit mode; upsertRow renders at the end
        await upsertRow('upsert_agenda', payload, 'agenda', String(i));
    };

    const renderDecisionCard = (d, origIdx) => {
        const responsibles = [
            ...(d.user_ids || []).map(id => nameOf(R.users, id)),
            ...(d.seat_ids || []).map(id => nameOf(R.seats, id)),
            ...(d.contact_ids || []).map(id => nameOf(contactsPool, id)),
            ...(d.team_ids || []).map(id => {
                const t = (R.teams || []).find(x => x.id === id);
                return t ? ('Team: ' + t.name) : '';
            }),
        ].filter(Boolean);
        const respBadges = responsibles.map(n => `<span class="badge text-bg-light border me-1">${esc(n)}</span>`).join('');
        const descBlock    = (d.description || '').trim() === '' ? '' : `<div class="small mt-1" style="white-space:pre-wrap;">${esc(d.description)}</div>`;
        const remarksBlock = (d.remarks     || '').trim() === '' ? '' : `<div class="small text-muted mt-1" style="white-space:pre-wrap;"><em>Remarks:</em> ${esc(d.remarks)}</div>`;
        const dueBlock     = (d.due_date    || '').trim() === '' ? '' : `<div class="small text-muted"><i class="bi bi-calendar-event me-1"></i>Due: ${esc(d.due_date)}</div>`;
        const respBlock    = responsibles.length === 0 ? '' : `<div class="small mt-1">Responsible: ${respBadges}</div>`;
        const nextChip     = Number(d.add_to_next_agenda || 0) === 1
            ? '<span class="badge text-bg-info-subtle border ms-1" title="Mirrored to next-meeting agenda"><i class="bi bi-calendar-plus me-1"></i>in next</span>' : '';
        return `<div class="meeting-list-item border-bottom pb-2 mb-2 d-flex align-items-start gap-2">
            <div class="flex-grow-1">
                <strong>${esc(d.heading || '')}</strong>${nextChip}
                ${descBlock}
                ${dueBlock}
                ${respBlock}
                ${remarksBlock}
            </div>
            <div class="d-flex flex-shrink-0 gap-1">
                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" data-edit-decision="${origIdx}" title="Edit"><i class="bi bi-pencil"></i></button>
                <button type="button" class="btn btn-sm btn-outline-danger  py-0 px-1" data-del-decision="${origIdx}"  title="Delete"><i class="bi bi-x"></i></button>
            </div>
        </div>`;
    };
    const renderDecisions = () => {
        const list = document.getElementById('decisionList');
        if (state.decisions.length === 0) {
            list.innerHTML = '<div class="text-muted small">No decision points yet.</div>';
        } else {
            // Group decisions by their agenda_tmp_id. "Other" bucket at
            // the bottom. Each group shows the agenda's Sl.no. and title.
            const groups = new Map();
            state.agenda.forEach((a, aIdx) => groups.set(a.tmp_id, { title: `#${aIdx+1} — ${a.title || '(untitled)'}`, items: [] }));
            groups.set('', { title: 'Other', items: [] });
            state.decisions.forEach((d, dIdx) => {
                const key = d.agenda_tmp_id && groups.has(d.agenda_tmp_id) ? d.agenda_tmp_id : '';
                groups.get(key).items.push({ d, dIdx });
            });
            const parts = [];
            for (const [, g] of groups) {
                if (g.items.length === 0) continue;
                parts.push(`<div class="mb-3">
                    <div class="meeting-group-header small text-uppercase text-muted fw-semibold mb-2"><i class="bi bi-folder2 me-1"></i>${esc(g.title)}</div>
                    ${g.items.map(({ d, dIdx }) => renderDecisionCard(d, dIdx)).join('')}
                </div>`);
            }
            list.innerHTML = parts.join('');
        }
        const hidden = document.getElementById('decisionHidden');
        hidden.innerHTML = state.decisions.map((d, i) => {
            const users   = (d.user_ids    || []).map(id => `<input type="hidden" name="decision[${i}][user_ids][]"    value="${id}">`).join('');
            const seats   = (d.seat_ids    || []).map(id => `<input type="hidden" name="decision[${i}][seat_ids][]"    value="${id}">`).join('');
            const conts   = (d.contact_ids || []).map(id => `<input type="hidden" name="decision[${i}][contact_ids][]" value="${id}">`).join('');
            const teams   = (d.team_ids    || []).map(id => `<input type="hidden" name="decision[${i}][team_ids][]"    value="${id}">`).join('');
            const createOwn = Number(d.create_own_tasks == null ? 1 : d.create_own_tasks) === 1 ? 1 : 0;
            const priv      = Number(d.status_private || 0) === 1 ? 1 : 0;
            const fanOut    = Number(d.fan_out_teams || 0) === 1 ? 1 : 0;
            const addToNext = Number(d.add_to_next_agenda || 0) === 1 ? 1 : 0;
            return `<input type="hidden" name="decision[${i}][heading]"       value="${esc(d.heading || '')}">
                    <input type="hidden" name="decision[${i}][description]"   value="${esc(d.description || '')}">
                    <input type="hidden" name="decision[${i}][due_date]"      value="${d.due_date || ''}">
                    <input type="hidden" name="decision[${i}][remarks]"       value="${esc(d.remarks || '')}">
                    <input type="hidden" name="decision[${i}][agenda_tmp_id]" value="${esc(d.agenda_tmp_id || '')}">
                    <input type="hidden" name="decision[${i}][create_own_tasks]" value="${createOwn}">
                    <input type="hidden" name="decision[${i}][status_private]"   value="${priv}">
                    <input type="hidden" name="decision[${i}][fan_out_teams]"    value="${fanOut}">
                    <input type="hidden" name="decision[${i}][add_to_next_agenda]" value="${addToNext}">${users}${seats}${conts}${teams}`;
        }).join('');
    };
    const renderNextAgenda = () => {
        const list = document.getElementById('nextAgendaList');
        list.innerHTML = state.next_agenda.map((n, i) => {
            const descBlock    = (n.description || '').trim() === '' ? '' : `<div class="small text-muted mt-1" style="white-space:pre-wrap;">${esc(n.description)}</div>`;
            const remarksBlock = (n.remarks     || '').trim() === '' ? '' : `<div class="small text-warning-emphasis mt-1" style="white-space:pre-wrap;"><i class="bi bi-exclamation-triangle me-1"></i><em>Remarks:</em> ${esc(n.remarks)}</div>`;
            const linkChip = Number(n.linked_decision_id || 0) > 0
                ? '<span class="badge text-bg-info-subtle border ms-1" title="Mirrored from a decision point — the decision keeps this row in sync, so edits here may be overwritten."><i class="bi bi-link-45deg me-1"></i>from decision</span>' : '';
            return `<div class="meeting-list-item d-flex align-items-start gap-2 py-2 border-bottom">
                <div class="meeting-sl text-muted small">${i+1}.</div>
                <div class="flex-grow-1">
                    <strong>${esc(n.title || '')}</strong>${linkChip}
                    ${descBlock}
                    ${remarksBlock}
                </div>
                <div class="d-flex flex-shrink-0 gap-1">
                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" data-edit-next="${i}" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger  py-0 px-1" data-del-next="${i}"  title="Delete"><i class="bi bi-x"></i></button>
                </div>
            </div>`;
        }).join('') || '<div class="text-muted small">No next-meeting agenda yet.</div>';
        // Hidden inputs — SKIP linked rows: the big-save handler
        // re-synthesises them from each decision's add_to_next_agenda
        // flag, so re-sending them here would create a duplicate.
        document.getElementById('nextAgendaHidden').innerHTML = state.next_agenda
            .filter(n => !Number(n.linked_decision_id || 0))
            .map((n, i) =>
                `<input type="hidden" name="next_agenda[${i}][title]"       value="${esc(n.title || '')}">
                 <input type="hidden" name="next_agenda[${i}][description]" value="${esc(n.description || '')}">
                 <input type="hidden" name="next_agenda[${i}][remarks]"     value="${esc(n.remarks || '')}">`).join('');
    };
    const renderUrls = () => {
        const list = document.getElementById('urlList');
        list.innerHTML = state.urls.map((u, i) => {
            const label = u.label || u.url || '';
            return `<div class="meeting-list-item d-flex align-items-center gap-2 py-2 border-bottom small">
                <i class="bi bi-link-45deg text-muted"></i>
                <a href="${esc(u.url)}" target="_blank" rel="noopener" class="flex-grow-1 text-truncate" title="${esc(u.url)}">${esc(label)}</a>
                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" data-edit-url="${i}" title="Edit"><i class="bi bi-pencil"></i></button>
                <button type="button" class="btn btn-sm btn-outline-danger  py-0 px-1" data-del-url="${i}"  title="Delete"><i class="bi bi-x"></i></button>
            </div>`;
        }).join('') || '<div class="text-muted small p-2">No URLs yet.</div>';
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
    const confirmDelete = (label) => confirm('Delete this ' + label + '? This cannot be undone.');
    // Inline attendance toggle on participant rows. Clicks the Present
    // / Absent buttons rendered by renderParticipants(); toggles off if
    // the already-active state is clicked again (so attendance can go
    // back to "not recorded"). Saves via upsert_participant so the
    // row persists without touching the big Save-meeting button.
    const saveParticipantAttendance = async (idx) => {
        const p = state.participants[idx];
        if (!p) return;
        try {
            await ensureDraft();
            setStatus('Saving attendance…', 'info');
            const j = await ajax({
                action: 'upsert_participant', meeting_id: meetingId,
                id: p.id || 0,
                user_id: p.user_id || 0, contact_id: p.contact_id || 0,
                is_mandatory: p.is_mandatory ?? 1,
                attended: p.attended === '' || p.attended == null ? '' : String(p.attended),
                role_label: p.role_label || '',
            });
            if (!j.ok) throw new Error(j.error || 'save failed');
            if (!p.id) p.id = j.item_id;
            setStatus('Attendance saved', 'success');
        } catch (e) { setStatus('Save error', 'danger'); alert('Attendance save failed: ' + e.message); }
    };
    document.addEventListener('click', (ev) => {
        const aB = ev.target.closest('[data-attend-set]');
        if (aB) {
            const [idxStr, valStr] = aB.dataset.attendSet.split(':');
            const idx = +idxStr;
            const newVal = String(valStr);
            const p = state.participants[idx];
            if (!p) return;
            const current = String(p.attended ?? '');
            p.attended = (current === newVal) ? '' : newVal; // click-again clears
            renderParticipants();
            saveParticipantAttendance(idx);
            return;
        }
        const eP = ev.target.closest('[data-edit-participant]'); if (eP) return openParticipant(+eP.dataset.editParticipant);
        const dP = ev.target.closest('[data-del-participant]');  if (dP) { if (!confirmDelete('participant')) return; const i = +dP.dataset.delParticipant; return deleteRow('delete_participant', state.participants[i]?.id || 0, 'participants', i); }
        const eAi = ev.target.closest('[data-edit-agenda-inline]'); if (eAi) { agendaEditIdx = +eAi.dataset.editAgendaInline; renderAgenda(); return; }
        const eA  = ev.target.closest('[data-edit-agenda]');        if (eA)  return openAgenda(+eA.dataset.editAgenda);
        const dA  = ev.target.closest('[data-del-agenda]');         if (dA)  { if (!confirmDelete('agenda item')) return; const i = +dA.dataset.delAgenda; if (agendaEditIdx === i) agendaEditIdx = null; return deleteRow('delete_agenda', state.agenda[i]?.id || 0, 'agenda', i); }
        const cAi = ev.target.closest('[data-cancel-agenda-inline]'); if (cAi) { agendaEditIdx = null; renderAgenda(); return; }
        const sAi = ev.target.closest('[data-save-agenda-inline]');   if (sAi) return saveAgendaInline(+sAi.dataset.saveAgendaInline);
        const rLead = ev.target.closest('.js-remove-lead');           if (rLead) {
            const pill = rLead.closest('.agenda-lead-pill');
            const widget = pill?.closest('[data-leads-widget]');
            pill?.remove();
            if (widget && widget.querySelector('.js-leads-dropdown')?.style.display === 'block') refreshLeadDropdown(widget);
            return;
        }
        const pLead = ev.target.closest('.js-pick-lead');             if (pLead) { pickLeadInWidget(pLead); return; }
        const eD = ev.target.closest('[data-edit-decision]'); if (eD) return openDecision(+eD.dataset.editDecision);
        const dD = ev.target.closest('[data-del-decision]');  if (dD) { if (!confirmDelete('decision point')) return; const i = +dD.dataset.delDecision; return deleteRow('delete_decision', state.decisions[i]?.id || 0, 'decisions', i); }
        const eN = ev.target.closest('[data-edit-next]'); if (eN) return openNext(+eN.dataset.editNext);
        const dN = ev.target.closest('[data-del-next]');  if (dN) { if (!confirmDelete('next-meeting agenda item')) return; const i = +dN.dataset.delNext; return deleteRow('delete_next_agenda', state.next_agenda[i]?.id || 0, 'next_agenda', i); }
        const eU = ev.target.closest('[data-edit-url]'); if (eU) return openUrl(+eU.dataset.editUrl);
        const dU = ev.target.closest('[data-del-url]');  if (dU) { if (!confirmDelete('attachment URL')) return; const i = +dU.dataset.delUrl; return deleteRow('delete_url', state.urls[i]?.id || 0, 'urls', i); }
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
        document.getElementById('aModalRemarks').value = a.remarks || '';
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
        document.getElementById('dModalRemarks').value = d.remarks || '';
        // Fill the agenda dropdown from the current state.agenda list,
        // using tmp_id as the stable value. "Other" (blank) is the
        // first option.
        const aSel = document.getElementById('dModalAgenda');
        const current = d.agenda_tmp_id || '';
        aSel.innerHTML = '<option value="">Other</option>' + state.agenda.map((a, i) =>
            `<option value="${esc(a.tmp_id)}" ${a.tmp_id === current ? 'selected' : ''}>#${i+1} — ${esc(a.title || '(untitled)')}</option>`
        ).join('');
        document.getElementById('dModalCreateOwn').checked = d.create_own_tasks == null ? true : Number(d.create_own_tasks) === 1;
        document.getElementById('dModalPrivate').checked   = Number(d.status_private || 0) === 1;
        document.getElementById('dModalFanOut').checked    = Number(d.fan_out_teams || 0) === 1;
        document.getElementById('dModalAddToNext').checked = Number(d.add_to_next_agenda || 0) === 1;
        // Four-column layout (participants / internal / external / teams).
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
        // Teams column — hidden gracefully when the hosted DB lacks the
        // meeting_decision_responsible.team_id column.
        const teamsCol = document.getElementById('dTeamsCol');
        if (teamsCol) teamsCol.style.display = R.teams_enabled ? '' : 'none';
        document.getElementById('dModalFanOut').closest('.col-md-3').style.display = R.fan_out_enabled ? '' : 'none';
        if (R.teams_enabled) {
            const teamPool = sortByName((R.teams || []).map(t => ({
                id: t.id,
                name: t.name + (t.head_name ? ' · head: ' + t.head_name : '') + (t.member_count ? ' · ' + t.member_count + ' members' : ''),
            })));
            buildCheckList('dListTeams', teamPool, d.team_ids || [], 'drt_');
            document.getElementById('dSearchTeams').value = '';
            filterCheckList('dListTeams', 'dListTeamsCount', '');
        }
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
    document.getElementById('dSearchTeams')?.addEventListener('input', (e) => filterCheckList('dListTeams', 'dListTeamsCount', e.target.value));
    const openNext = (idx) => {
        const n = idx == null ? {} : state.next_agenda[idx];
        document.getElementById('nModalIdx').value = idx == null ? '' : idx;
        document.getElementById('nModalTitle').value = n.title || '';
        document.getElementById('nModalDesc').value  = n.description || '';
        document.getElementById('nModalRemarks').value = n.remarks || '';
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
    document.querySelectorAll('[data-open-modal="participantModal"]').forEach(b => b.addEventListener('click', (e) => { e.preventDefault(); openParticipant(null); }));
    document.querySelectorAll('[data-open-modal="agendaModal"]').forEach(b => b.addEventListener('click', (e) => { e.preventDefault(); openAgenda(null); }));
    document.querySelectorAll('[data-open-modal="decisionModal"]').forEach(b => b.addEventListener('click', (e) => { e.preventDefault(); openDecision(null); }));
    document.querySelectorAll('[data-open-modal="nextAgendaModal"]').forEach(b => b.addEventListener('click', (e) => { e.preventDefault(); openNext(null); }));
    document.querySelectorAll('[data-open-modal="urlModal"]').forEach(b => b.addEventListener('click', (e) => { e.preventDefault(); openUrl(null); }));

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
            // Agenda rows need a stable tmp_id for cross-table linking;
            // keep the previous one on edit, mint a fresh one on add.
            if (listName === 'agenda') {
                const prevTmp = idxRaw === '' ? '' : (state.agenda[+idxRaw]?.tmp_id || '');
                row.tmp_id = prevTmp || makeTmp();
            }
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
            remarks: document.getElementById('aModalRemarks').value.trim(),
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
        const userSet = new Set(), contactSet = new Set(), teamSet = new Set();
        document.querySelectorAll('#dListParts input:checked').forEach(c => {
            const raw = String(c.value);
            if (raw[0] === 'u') userSet.add(Number(raw.slice(1)));
            else if (raw[0] === 'c') contactSet.add(Number(raw.slice(1)));
        });
        document.querySelectorAll('#dListUsers input:checked').forEach(c => userSet.add(Number(c.value)));
        document.querySelectorAll('#dListContacts input:checked').forEach(c => contactSet.add(Number(c.value)));
        document.querySelectorAll('#dListTeams input:checked').forEach(c => teamSet.add(Number(c.value)));
        // Resolve the picked agenda tmp_id to the agenda's current DB id
        // (if any) so the ajax upsert can persist agenda_id directly.
        const pickedAgendaTmp = document.getElementById('dModalAgenda').value || '';
        const pickedAgenda = pickedAgendaTmp ? state.agenda.find(a => a.tmp_id === pickedAgendaTmp) : null;
        const pickedAgendaId = pickedAgenda ? (pickedAgenda.id || 0) : 0;
        upsertRow('upsert_decision', {
            id: existingId, heading, description: document.getElementById('dModalDesc').value.trim(),
            due_date: document.getElementById('dModalDue').value,
            remarks: document.getElementById('dModalRemarks').value.trim(),
            agenda_id: pickedAgendaId,
            agenda_tmp_id: pickedAgendaTmp,
            create_own_tasks: document.getElementById('dModalCreateOwn').checked ? 1 : 0,
            status_private:   document.getElementById('dModalPrivate').checked   ? 1 : 0,
            fan_out_teams:    document.getElementById('dModalFanOut').checked    ? 1 : 0,
            add_to_next_agenda: document.getElementById('dModalAddToNext').checked ? 1 : 0,
            user_ids: Array.from(userSet).filter(Boolean),
            seat_ids: [], // seat-as-responsibility UI retired
            contact_ids: Array.from(contactSet).filter(Boolean),
            team_ids: Array.from(teamSet).filter(Boolean),
        }, 'decisions', idxRaw);
    });
    document.getElementById('nModalSave').addEventListener('click', () => {
        const idxRaw = document.getElementById('nModalIdx').value;
        const title = document.getElementById('nModalTitle').value.trim();
        if (title === '') { alert('Title required.'); return; }
        const existingId = idxRaw !== '' ? (state.next_agenda[+idxRaw]?.id || 0) : 0;
        upsertRow('upsert_next_agenda', {
            id: existingId, title, description: document.getElementById('nModalDesc').value.trim(),
            remarks: document.getElementById('nModalRemarks').value.trim(),
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

<?php if ($existing): ?>
<!-- Copy-to-new-meeting modal (Bootstrap) -->
<div class="modal fade" id="copyMeetingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="copy_meeting">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-copy text-primary me-1"></i>Copy this meeting</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-light border mb-3">
            <div class="small text-muted">Source</div>
            <div class="fw-semibold"><?= esc((string) $existing['reference_no']) ?> · <?= esc((string) $existing['title']) ?></div>
            <div class="small text-muted">A new meeting will be created under the same division with its own reference number. The current meeting stays untouched.</div>
          </div>
          <div class="small text-muted mb-2">Tick the sections you want to carry over. You'll land on the new meeting's edit page to set date, time and the rest.</div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="cmGeneral" name="copy_general" value="1" checked>
            <label class="form-check-label" for="cmGeneral"><strong>General details</strong> <span class="small text-muted">(title, purpose, location, virtual link, chairperson)</span></label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="cmParticipants" name="copy_participants" value="1" checked>
            <label class="form-check-label" for="cmParticipants"><strong>Participants</strong> <span class="small text-muted">(attendance clears — those marks are per-meeting)</span></label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="cmAgenda" name="copy_agenda" value="1" checked>
            <label class="form-check-label" for="cmAgenda"><strong>Agenda items</strong> <span class="small text-muted">(titles, descriptions, leads)</span></label>
          </div>
          <div class="form-check ms-4">
            <input class="form-check-input" type="checkbox" id="cmNextAsAgenda" name="copy_next_as_agenda" value="1">
            <label class="form-check-label" for="cmNextAsAgenda"><strong>Promote next-meeting agenda</strong> <span class="small text-muted">(append the source's planned-for-next items as agenda items in the new meeting)</span></label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="cmDecisions" name="copy_decisions" value="1" checked>
            <label class="form-check-label" for="cmDecisions"><strong>Decision points</strong> <span class="small text-muted">(heading, description, due date, remarks, responsibility, team links)</span></label>
          </div>
          <div class="small text-muted mt-3"><i class="bi bi-info-circle me-1"></i>Date / time, minutes text, URL attachments and chairperson approval are <strong>not</strong> copied — they belong to the specific meeting and are set fresh on the new one. The source meeting's <em>next-meeting agenda</em> stays on the source; use the checkbox above to also promote it into the new meeting's main agenda.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-copy me-1"></i>Create new meeting</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Import-from-previous modal (Bootstrap) -->
<div class="modal fade" id="importPrevModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-arrow-down-circle text-primary me-1"></i>Import from previous meeting</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="card bg-light mb-3">
          <div class="card-body py-2">
            <div class="small text-muted">Source meeting</div>
            <div class="fw-semibold" id="ipHeaderRef">—</div>
            <div class="small text-muted" id="ipHeaderMeta">—</div>
          </div>
        </div>
        <div class="small text-muted mb-2">Tick what you want to copy into <strong><?= esc((string) $existing['reference_no']) ?></strong>. Only the text fields are copied — responsibility, teams, participants and attachments are not carried over.</div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="ipDec" value="1" checked>
          <label class="form-check-label" for="ipDec"><strong>Decision points</strong> <span class="small text-muted">(heading, description, due date, remarks)</span></label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="ipNext" value="1">
          <label class="form-check-label" for="ipNext"><strong>Next-meeting agenda</strong> <span class="small text-muted">(title, description)</span></label>
        </div>
        <div class="small text-muted mt-3">Imported rows are appended to the end of the current meeting's lists. The source meeting is not modified.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="ipImportBtn"><i class="bi bi-arrow-down-circle me-1"></i>Import</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
    const btn    = document.getElementById('importPrevBtn');
    const sel    = document.getElementById('prevMeetingSelect');
    const modalEl = document.getElementById('importPrevModal');
    if (!btn || !sel || !modalEl || !window.bootstrap?.Modal) return;
    const refMap = window.__meetingRef.prev_meta || {};
    const modal  = new bootstrap.Modal(modalEl);
    const sync = () => { btn.disabled = !(Number(sel.value) > 0); };
    sel.addEventListener('change', sync); sync();
    btn.addEventListener('click', () => {
        const pid = Number(sel.value);
        if (pid <= 0) return;
        const meta = refMap[pid] || refMap[String(pid)] || null;
        document.getElementById('ipHeaderRef').textContent  = meta ? (meta.reference_no + ' · ' + meta.title) : ('Meeting #' + pid);
        document.getElementById('ipHeaderMeta').textContent = meta && meta.meeting_date ? ('Date: ' + meta.meeting_date) : '';
        document.getElementById('ipDec').checked  = true;
        document.getElementById('ipNext').checked = false;
        modal.show();
    });
    document.getElementById('ipImportBtn').addEventListener('click', async function () {
        const pid = Number(sel.value);
        if (pid <= 0) return;
        const dec  = document.getElementById('ipDec').checked ? 1 : 0;
        const nxt  = document.getElementById('ipNext').checked ? 1 : 0;
        if (!dec && !nxt) { alert('Pick at least one section to import.'); return; }
        this.disabled = true; this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing…';
        const fd = new FormData();
        fd.append('action', 'import_from_previous');
        fd.append('meeting_id', String(<?= (int) $existing['id'] ?>));
        fd.append('source_meeting_id', String(pid));
        fd.append('import_decisions', String(dec));
        fd.append('import_next_agenda', String(nxt));
        fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
        try {
            const r = await fetch('/meeting_ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Import failed');
            // Full reload so the server-rendered preset arrays pick up
            // the new rows, keeping the Save-side wholesale rewrite happy.
            location.reload();
        } catch (e) {
            alert('Import failed: ' + e.message);
            this.disabled = false; this.innerHTML = '<i class="bi bi-arrow-down-circle me-1"></i>Import';
        }
    });
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>

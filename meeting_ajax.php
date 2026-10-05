<?php
/**
 * Meeting auto-save endpoints.
 *
 * Every action returns JSON with { ok: true, ... } on success or
 * { ok: false, error: '...' } on failure. All writes CSRF-checked.
 *
 * Actions:
 *   create_draft                                                → { meeting_id, reference_no }
 *   save_field       (meeting_id, field, value)                 → { }
 *   upsert_participant  (meeting_id, id?, user_id, contact_id, is_mandatory, attended, role_label) → { item_id }
 *   delete_participant  (meeting_id, id)                        → { }
 *   upsert_agenda    (meeting_id, id?, title, description, lead_user_id, lead_seat_id, lead_contact_id) → { item_id }
 *   delete_agenda    (meeting_id, id)                           → { }
 *   upsert_decision  (meeting_id, id?, heading, description, due_date, user_ids[], seat_ids[], contact_ids[]) → { item_id }
 *   delete_decision  (meeting_id, id)                           → { }
 *   upsert_next_agenda (meeting_id, id?, title, description)   → { item_id }
 *   delete_next_agenda (meeting_id, id)                        → { }
 *   upsert_url       (meeting_id, id?, label, url)              → { item_id }
 *   delete_url       (meeting_id, id)                           → { }
 *
 * Permission: creator + admin can write. Non-creators get 403.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/meetings_helpers.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$sendError = static function (string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
};

require_auth();
$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
meetings_bootstrap();
task_tracker_bootstrap();
$isAdminAll = is_manage_admin($viewer) || user_can_admin_module($viewerId, 'meetings');

if (!is_post())    $sendError('POST required.', 405);
if (!csrf_check()) $sendError('CSRF check failed.', 403);

$action = (string) ($_POST['action'] ?? '');

// Helper: check + load meeting, enforce writer permission.
$requireEditable = static function (int $meetingId) use ($viewerId, $isAdminAll, $sendError) {
    if ($meetingId <= 0) $sendError('Missing meeting_id.');
    $st = db()->prepare('SELECT * FROM meeting WHERE id = ? LIMIT 1');
    $st->execute([$meetingId]);
    $m = $st->fetch();
    if ($m === false) $sendError('Meeting not found.', 404);
    if (!$isAdminAll && (int) ($m['created_by'] ?? 0) !== $viewerId) $sendError('Not permitted.', 403);
    return $m;
};

if ($action === 'create_draft') {
    $divisionId = (int) ($_POST['division_id'] ?? 0);
    try {
        $ref = meetings_generate_reference($divisionId);
        $divCode = null;
        if ($divisionId > 0) {
            $s = db()->prepare('SELECT code, name FROM office_hierarchy_nodes WHERE id = ?');
            $s->execute([$divisionId]);
            $r = $s->fetch();
            if ($r !== false) {
                $stored = trim((string) ($r['code'] ?? ''));
                if ($stored !== '') $divCode = strtoupper($stored);
                else {
                    $abbrev = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $r['name']));
                    if ($abbrev !== '') $divCode = substr($abbrev, 0, 3);
                }
            }
        }
        db()->prepare('INSERT INTO meeting
            (reference_no, title, meeting_date, division_id, division_code_snapshot, status, is_active,
             created_at, updated_at, created_by, updated_by)
            VALUES (?, ?, CURRENT_DATE, ?, ?, "scheduled", 1, NOW(), NOW(), ?, ?)')
            ->execute([
                $ref,
                'Untitled meeting',
                $divisionId > 0 ? $divisionId : null,
                $divCode,
                $viewerId, $viewerId,
            ]);
        $id = db()->lastInsertId();
        echo json_encode(['ok' => true, 'meeting_id' => $id, 'reference_no' => $ref]);
        exit;
    } catch (Throwable $e) { $sendError('Draft create failed: ' . $e->getMessage(), 500); }
}

$meetingId = (int) ($_POST['meeting_id'] ?? 0);
$meeting = $requireEditable($meetingId);

if ($action === 'save_field') {
    $field = (string) ($_POST['field'] ?? '');
    $value = trim((string) ($_POST['value'] ?? ''));
    $allowed = ['title','purpose','meeting_date','from_time','to_time','location','virtual_link',
                'chair_user_id','chair_contact_id','division_id','status','minutes_body',
                'next_meeting_date','next_meeting_time','previous_meeting_id'];
    if (!in_array($field, $allowed, true)) $sendError('Field not allowed.');
    // Coerce IDs / dates.
    $intFields  = ['chair_user_id','chair_contact_id','division_id','previous_meeting_id'];
    $dateFields = ['meeting_date','next_meeting_date'];
    $timeFields = ['from_time','to_time','next_meeting_time'];
    if (in_array($field, $intFields, true))  $value = (int) $value > 0 ? (int) $value : null;
    elseif (in_array($field, $dateFields, true)) $value = ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) ? $value : null;
    elseif (in_array($field, $timeFields, true)) $value = $value === '' ? null : $value;
    else $value = $value === '' ? null : $value;
    try {
        db()->prepare('UPDATE meeting SET ' . $field . ' = ?, updated_at = NOW(), updated_by = ? WHERE id = ?')
           ->execute([$value, $viewerId, $meetingId]);
        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) { $sendError('Field save failed: ' . $e->getMessage(), 500); }
}

if ($action === 'upsert_participant') {
    $id  = (int) ($_POST['id'] ?? 0);
    $uid = (int) ($_POST['user_id'] ?? 0);
    $cid = (int) ($_POST['contact_id'] ?? 0);
    if ($uid <= 0 && $cid <= 0) $sendError('Pick user or contact.');
    $mand = ((string) ($_POST['is_mandatory'] ?? '1')) === '1' ? 1 : 0;
    $att  = ((string) ($_POST['attended'] ?? '')) === '' ? null : ((string) $_POST['attended'] === '1' ? 1 : 0);
    $role = trim((string) ($_POST['role_label'] ?? '')) ?: null;
    try {
        if ($id > 0) {
            db()->prepare('UPDATE meeting_participant SET user_id = ?, contact_id = ?, is_mandatory = ?, attended = ?, role_label = ? WHERE id = ? AND meeting_id = ?')
                ->execute([$uid > 0 ? $uid : null, $cid > 0 ? $cid : null, $mand, $att, $role, $id, $meetingId]);
        } else {
            db()->prepare('INSERT INTO meeting_participant (meeting_id, user_id, contact_id, is_mandatory, attended, role_label, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$meetingId, $uid > 0 ? $uid : null, $cid > 0 ? $cid : null, $mand, $att, $role]);
            $id = db()->lastInsertId();
        }
        echo json_encode(['ok' => true, 'item_id' => $id]); exit;
    } catch (Throwable $e) { $sendError($e->getMessage(), 500); }
}
if ($action === 'delete_participant') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) $sendError('Missing id.');
    db()->prepare('DELETE FROM meeting_participant WHERE id = ? AND meeting_id = ?')->execute([$id, $meetingId]);
    echo json_encode(['ok' => true]); exit;
}

if ($action === 'upsert_agenda') {
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title === '') $sendError('Title required.');
    $desc = trim((string) ($_POST['description'] ?? '')) ?: null;
    // Multi-lead: user_ids[] / seat_ids[] / contact_ids[]. For
    // backward compatibility we also populate the legacy single-id
    // columns with the first entry from each list so older display
    // code that reads them still shows something sensible.
    $userIds    = array_values(array_filter(array_map('intval', (array) ($_POST['user_ids']    ?? []))));
    $seatIds    = array_values(array_filter(array_map('intval', (array) ($_POST['seat_ids']    ?? []))));
    $contactIds = array_values(array_filter(array_map('intval', (array) ($_POST['contact_ids'] ?? []))));
    $firstUser    = $userIds[0]    ?? null;
    $firstSeat    = $seatIds[0]    ?? null;
    $firstContact = $contactIds[0] ?? null;

    db()->query('START TRANSACTION');
    try {
        if ($id > 0) {
            db()->prepare('UPDATE meeting_agenda SET title = ?, description = ?, lead_user_id = ?, lead_seat_id = ?, lead_contact_id = ? WHERE id = ? AND meeting_id = ?')
                ->execute([$title, $desc, $firstUser, $firstSeat, $firstContact, $id, $meetingId]);
        } else {
            $st = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM meeting_agenda WHERE meeting_id = ?');
            $st->execute([$meetingId]);
            $newSort = (int) ($st->fetchColumn() ?: 1);
            db()->prepare('INSERT INTO meeting_agenda (meeting_id, sort_order, title, description, lead_user_id, lead_seat_id, lead_contact_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$meetingId, $newSort, $title, $desc, $firstUser, $firstSeat, $firstContact]);
            $id = db()->lastInsertId();
        }
        // Rewrite the many-to-many lead set.
        db()->prepare('DELETE FROM meeting_agenda_lead WHERE agenda_id = ?')->execute([$id]);
        $insL = db()->prepare('INSERT INTO meeting_agenda_lead (agenda_id, user_id, seat_id, contact_id) VALUES (?, ?, ?, ?)');
        foreach ($userIds    as $x) $insL->execute([$id, $x, null, null]);
        foreach ($seatIds    as $x) $insL->execute([$id, null, $x, null]);
        foreach ($contactIds as $x) $insL->execute([$id, null, null, $x]);
        db()->query('COMMIT');
        echo json_encode(['ok' => true, 'item_id' => $id]); exit;
    } catch (Throwable $e) {
        try { db()->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
        $sendError($e->getMessage(), 500);
    }
}
if ($action === 'delete_agenda') {
    $id = (int) ($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM meeting_agenda_lead WHERE agenda_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM meeting_agenda WHERE id = ? AND meeting_id = ?')->execute([$id, $meetingId]);
    echo json_encode(['ok' => true]); exit;
}

/**
 * Thin wrapper around meetings_sync_decision_tasks() — keeps the
 * existing closure name in use further down this file so edits
 * stay localised. The real implementation lives in
 * includes/meetings_helpers.php where it's shared with the bulk
 * import flow.
 */
$syncDecisionTasks = static function (int $decisionId, array $userIds, int $viewerId): void {
    meetings_sync_decision_tasks($decisionId, $userIds, $viewerId);
};
// --- original inline implementation retained below (unused) ----
$_unusedInlineSync = static function (int $decisionId, array $userIds, int $viewerId): void {
    try {
        $db = db();
        // Find the Own Tasks project.
        $st = $db->prepare("SELECT id, code, next_task_number FROM project WHERE code = 'OWN' LIMIT 1");
        $st->execute();
        $ownProject = $st->fetch();
        if ($ownProject === false) return;
        $ownProjectId = (int) $ownProject['id'];

        // Load the decision itself for title + description + due_date + meeting ref.
        $st = $db->prepare('SELECT md.heading, md.description, md.due_date, m.reference_no
            FROM meeting_decision md
            INNER JOIN meeting m ON m.id = md.meeting_id
            WHERE md.id = ?');
        $st->execute([$decisionId]);
        $dec = $st->fetch();
        if ($dec === false) return;
        $title = '[' . (string) $dec['reference_no'] . '] ' . (string) $dec['heading'];
        $desc  = (string) ($dec['description'] ?? '');
        $due   = (string) ($dec['due_date'] ?? '');
        $due   = ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) ? $due : null;

        // Default status = Not Started.
        $defaultStatus = (int) ($db->query("SELECT id FROM task_status WHERE name = 'Not Started' LIMIT 1")->fetchColumn() ?: 0);
        if ($defaultStatus === 0) $defaultStatus = (int) ($db->query('SELECT id FROM task_status WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 1')->fetchColumn() ?: 0);

        // Current task set for this decision.
        $st = $db->prepare('SELECT t.id, t.is_active, ta.seat_id, oh.officer_id
            FROM task t
            LEFT JOIN task_assignment ta ON ta.task_id = t.id AND ta.role = "primary"
            LEFT JOIN office_hierarchy_officer_history oh ON oh.node_id = ta.seat_id AND oh.unassigned_at IS NULL
            WHERE t.meeting_decision_id = ?');
        $st->execute([$decisionId]);
        $existing = $st->fetchAll(); // one row per existing task
        $existingByUser = [];
        foreach ($existing as $t) {
            $uid = (int) ($t['officer_id'] ?? 0);
            if ($uid > 0) $existingByUser[$uid] = (int) $t['id'];
        }

        // Figure out each responsible user's active seat.
        $wantedByUser = [];
        foreach ($userIds as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0) continue;
            $sst = $db->prepare('SELECT node_id FROM office_hierarchy_officer_history WHERE officer_id = ? AND unassigned_at IS NULL ORDER BY id DESC LIMIT 1');
            $sst->execute([$uid]);
            $seatId = (int) ($sst->fetchColumn() ?: 0);
            if ($seatId > 0) $wantedByUser[$uid] = $seatId;
        }

        // Upsert for each wanted user.
        foreach ($wantedByUser as $uid => $seatId) {
            if (isset($existingByUser[$uid])) {
                $taskId = $existingByUser[$uid];
                $db->prepare('UPDATE task SET title = ?, description = ?, planned_end = ?, is_active = 1, updated_at = NOW(), updated_by = ? WHERE id = ?')
                   ->execute([$title, $desc === '' ? null : $desc, $due, $viewerId, $taskId]);
            } else {
                // Allocate next_task_number atomically on the Own project.
                $db->query('START TRANSACTION');
                $lk = $db->prepare('SELECT next_task_number FROM project WHERE id = ? FOR UPDATE');
                $lk->execute([$ownProjectId]);
                $nextNum = (int) ($lk->fetchColumn() ?: 1);
                $db->prepare('INSERT INTO task (project_id, task_number, title, description, status_id, priority, planned_end, meeting_decision_id, is_active, created_at, updated_at, created_by, updated_by, board_order)
                    VALUES (?, ?, ?, ?, ?, "medium", ?, ?, 1, NOW(), NOW(), ?, ?, ?)')
                    ->execute([$ownProjectId, $nextNum, $title, $desc === '' ? null : $desc, $defaultStatus, $due, $decisionId, $viewerId, $viewerId, $nextNum * 1000]);
                $newId = $db->lastInsertId();
                $db->prepare('UPDATE project SET next_task_number = next_task_number + 1 WHERE id = ?')->execute([$ownProjectId]);
                // Primary seat assignment so My Work finds it.
                $db->prepare('INSERT INTO task_assignment (task_id, seat_id, role, created_at, created_by) VALUES (?, ?, "primary", NOW(), ?)')
                   ->execute([$newId, $seatId, $viewerId]);
                $db->query('COMMIT');
                $existingByUser[$uid] = $newId;
            }
        }
        // Deactivate tasks for users no longer responsible.
        foreach ($existingByUser as $uid => $taskId) {
            if (!isset($wantedByUser[$uid])) {
                $db->prepare('UPDATE task SET is_active = 0, updated_at = NOW(), updated_by = ? WHERE id = ?')->execute([$viewerId, $taskId]);
            }
        }
    } catch (Throwable $e) {
        try { db()->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
    }
};

if ($action === 'upsert_decision') {
    $id = (int) ($_POST['id'] ?? 0);
    $heading = trim((string) ($_POST['heading'] ?? ''));
    if ($heading === '') $sendError('Heading required.');
    $desc = trim((string) ($_POST['description'] ?? '')) ?: null;
    $due  = trim((string) ($_POST['due_date'] ?? ''));
    $due  = ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) ? $due : null;
    $userIds    = array_values(array_filter(array_map('intval', (array) ($_POST['user_ids']    ?? []))));
    $seatIds    = array_values(array_filter(array_map('intval', (array) ($_POST['seat_ids']    ?? []))));
    $contactIds = array_values(array_filter(array_map('intval', (array) ($_POST['contact_ids'] ?? []))));
    $createOwn    = isset($_POST['create_own_tasks']) ? (int) $_POST['create_own_tasks'] : 1;
    $statusPriv   = isset($_POST['status_private'])   ? (int) $_POST['status_private']   : 0;
    // Columns may be absent on older installs; detect once per request.
    static $decColsChecked = false, $hasDecFlags = false;
    if (!$decColsChecked) {
        try {
            $cols = [];
            foreach (db()->query('SHOW COLUMNS FROM meeting_decision')->fetchAll() as $c) $cols[strtolower((string) $c['Field'])] = true;
            $hasDecFlags = isset($cols['create_own_tasks']) && isset($cols['status_private']);
        } catch (Throwable $e) { $hasDecFlags = false; }
        $decColsChecked = true;
    }

    db()->query('START TRANSACTION');
    try {
        if ($id > 0) {
            if ($hasDecFlags) {
                db()->prepare('UPDATE meeting_decision SET heading = ?, description = ?, due_date = ?, create_own_tasks = ?, status_private = ? WHERE id = ? AND meeting_id = ?')
                    ->execute([$heading, $desc, $due, $createOwn ? 1 : 0, $statusPriv ? 1 : 0, $id, $meetingId]);
            } else {
                db()->prepare('UPDATE meeting_decision SET heading = ?, description = ?, due_date = ? WHERE id = ? AND meeting_id = ?')
                    ->execute([$heading, $desc, $due, $id, $meetingId]);
            }
        } else {
            $st = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM meeting_decision WHERE meeting_id = ?');
            $st->execute([$meetingId]);
            $newSort = (int) ($st->fetchColumn() ?: 1);
            if ($hasDecFlags) {
                db()->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date, create_own_tasks, status_private) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$meetingId, $newSort, $heading, $desc, $due, $createOwn ? 1 : 0, $statusPriv ? 1 : 0]);
            } else {
                db()->prepare('INSERT INTO meeting_decision (meeting_id, sort_order, heading, description, due_date) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$meetingId, $newSort, $heading, $desc, $due]);
            }
            $id = db()->lastInsertId();
        }
        // Rewrite responsibilities.
        db()->prepare('DELETE FROM meeting_decision_responsible WHERE decision_id = ?')->execute([$id]);
        $insR = db()->prepare('INSERT INTO meeting_decision_responsible (decision_id, user_id, contact_id, seat_id) VALUES (?, ?, ?, ?)');
        foreach ($userIds    as $x) $insR->execute([$id, $x, null, null]);
        foreach ($seatIds    as $x) $insR->execute([$id, null, null, $x]);
        foreach ($contactIds as $x) $insR->execute([$id, null, $x, null]);
        db()->query('COMMIT');
        // Sync Own Tasks only if the operator ticked the box. When
        // the box is off we deactivate every task we previously
        // spawned so the hidden-from-Own-Tasks promise is honoured.
        if ($createOwn) {
            $syncDecisionTasks((int) $id, $userIds, $viewerId);
        } else {
            try {
                db()->prepare('UPDATE task SET is_active = 0, updated_at = NOW(), updated_by = ? WHERE meeting_decision_id = ?')
                    ->execute([$viewerId, $id]);
            } catch (Throwable $e) { /* ignore */ }
        }
        echo json_encode(['ok' => true, 'item_id' => $id]); exit;
    } catch (Throwable $e) {
        try { db()->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
        $sendError($e->getMessage(), 500);
    }
}
if ($action === 'delete_decision') {
    $id = (int) ($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM meeting_decision_responsible WHERE decision_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM meeting_decision WHERE id = ? AND meeting_id = ?')->execute([$id, $meetingId]);
    // Deactivate all linked tasks so the owner no longer sees them.
    try { db()->prepare('UPDATE task SET is_active = 0, updated_at = NOW(), updated_by = ? WHERE meeting_decision_id = ?')->execute([$viewerId, $id]); } catch (Throwable $e) { /* ignore */ }
    echo json_encode(['ok' => true]); exit;
}

if ($action === 'upsert_next_agenda') {
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title === '') $sendError('Title required.');
    $desc = trim((string) ($_POST['description'] ?? '')) ?: null;
    try {
        if ($id > 0) {
            db()->prepare('UPDATE meeting_next_agenda SET title = ?, description = ? WHERE id = ? AND meeting_id = ?')
                ->execute([$title, $desc, $id, $meetingId]);
        } else {
            $st = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM meeting_next_agenda WHERE meeting_id = ?');
            $st->execute([$meetingId]);
            $newSort = (int) ($st->fetchColumn() ?: 1);
            db()->prepare('INSERT INTO meeting_next_agenda (meeting_id, sort_order, title, description) VALUES (?, ?, ?, ?)')
                ->execute([$meetingId, $newSort, $title, $desc]);
            $id = db()->lastInsertId();
        }
        echo json_encode(['ok' => true, 'item_id' => $id]); exit;
    } catch (Throwable $e) { $sendError($e->getMessage(), 500); }
}
if ($action === 'delete_next_agenda') {
    $id = (int) ($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM meeting_next_agenda WHERE id = ? AND meeting_id = ?')->execute([$id, $meetingId]);
    echo json_encode(['ok' => true]); exit;
}

if ($action === 'upsert_url') {
    $id = (int) ($_POST['id'] ?? 0);
    $url = trim((string) ($_POST['url'] ?? ''));
    if ($url === '') $sendError('URL required.');
    $label = trim((string) ($_POST['label'] ?? '')) ?: null;
    try {
        if ($id > 0) {
            db()->prepare('UPDATE meeting_url SET label = ?, url = ? WHERE id = ? AND meeting_id = ?')
                ->execute([$label, $url, $id, $meetingId]);
        } else {
            db()->prepare('INSERT INTO meeting_url (meeting_id, label, url) VALUES (?, ?, ?)')
                ->execute([$meetingId, $label, $url]);
            $id = db()->lastInsertId();
        }
        echo json_encode(['ok' => true, 'item_id' => $id]); exit;
    } catch (Throwable $e) { $sendError($e->getMessage(), 500); }
}
if ($action === 'delete_url') {
    $id = (int) ($_POST['id'] ?? 0);
    db()->prepare('DELETE FROM meeting_url WHERE id = ? AND meeting_id = ?')->execute([$id, $meetingId]);
    echo json_encode(['ok' => true]); exit;
}

$sendError('Unknown action: ' . $action, 400);

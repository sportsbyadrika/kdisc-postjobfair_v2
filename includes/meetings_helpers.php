<?php
/**
 * Meetings module — schema bootstrap + shared helpers.
 *
 * Tables:
 *   contact                          global contact master (external)
 *   meeting                          one row per meeting
 *   meeting_participant              invited list + attended flag
 *   meeting_agenda                   agenda item list
 *   meeting_decision                 decision points
 *   meeting_decision_responsible     N:M who owns the decision
 *   meeting_next_agenda              agenda for the follow-up meeting
 *   meeting_url                      attachment URLs (Google Drive etc.)
 *   meeting_setting                  key/value store for PDF branding
 *
 * A meeting is identified externally by its reference number:
 *   <DIVISION_CODE>/<YYYY>/<N>       e.g. ADM/2026/001
 * where DIVISION_CODE is office_hierarchy_nodes.code on the meeting's
 * owning division. If the division has no code set, the reference
 * falls back to the first 3 uppercased letters of the division name.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function meetings_bootstrap(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = db();

    try {
        $db->query("CREATE TABLE IF NOT EXISTS contact (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            institution VARCHAR(200) NULL,
            designation VARCHAR(200) NULL,
            email VARCHAR(200) NULL,
            mobile VARCHAR(40) NULL,
            address TEXT NULL,
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            created_by INT NULL,
            updated_by INT NULL,
            KEY idx_active (is_active),
            KEY idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS meeting (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reference_no VARCHAR(60) NOT NULL,
            title VARCHAR(300) NOT NULL,
            purpose TEXT NULL,
            meeting_date DATE NOT NULL,
            from_time TIME NULL,
            to_time TIME NULL,
            location VARCHAR(300) NULL,
            virtual_link VARCHAR(500) NULL,
            chair_user_id INT NULL,
            chair_contact_id INT NULL,
            division_id INT NULL,
            division_code_snapshot VARCHAR(30) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            minutes_body MEDIUMTEXT NULL,
            next_meeting_date DATE NULL,
            next_meeting_time TIME NULL,
            previous_meeting_id INT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            created_by INT NULL,
            updated_by INT NULL,
            UNIQUE KEY unique_ref (reference_no),
            KEY idx_date (meeting_date),
            KEY idx_status (status),
            KEY idx_division (division_id),
            KEY idx_prev (previous_meeting_id),
            KEY idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS meeting_participant (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            user_id INT NULL,
            contact_id INT NULL,
            is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
            attended TINYINT(1) NULL,
            role_label VARCHAR(80) NULL,
            created_at DATETIME NULL,
            KEY idx_meeting (meeting_id),
            KEY idx_user (user_id),
            KEY idx_contact (contact_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS meeting_agenda (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            title VARCHAR(300) NOT NULL,
            description TEXT NULL,
            lead_user_id INT NULL,
            lead_seat_id INT NULL,
            lead_contact_id INT NULL,
            KEY idx_meeting (meeting_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS meeting_agenda_lead (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agenda_id INT NOT NULL,
            user_id INT NULL,
            seat_id INT NULL,
            contact_id INT NULL,
            KEY idx_agenda (agenda_id),
            KEY idx_user (user_id),
            KEY idx_seat (seat_id),
            KEY idx_contact (contact_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS meeting_decision (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            heading VARCHAR(300) NOT NULL,
            description TEXT NULL,
            due_date DATE NULL,
            KEY idx_meeting (meeting_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS meeting_decision_responsible (
            id INT AUTO_INCREMENT PRIMARY KEY,
            decision_id INT NOT NULL,
            user_id INT NULL,
            contact_id INT NULL,
            seat_id INT NULL,
            KEY idx_decision (decision_id),
            KEY idx_user (user_id),
            KEY idx_contact (contact_id),
            KEY idx_seat (seat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS meeting_next_agenda (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            title VARCHAR(300) NOT NULL,
            description TEXT NULL,
            KEY idx_meeting (meeting_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS meeting_url (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            label VARCHAR(200) NULL,
            url VARCHAR(1000) NOT NULL,
            KEY idx_meeting (meeting_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS meeting_setting (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(80) NOT NULL,
            setting_value TEXT NULL,
            updated_at DATETIME NULL,
            updated_by INT NULL,
            UNIQUE KEY unique_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Optional short code on an office_hierarchy_nodes row — only
        // Division-level nodes need it for the meeting reference
        // number, but the column is generic in case Section/Sub Section
        // reference codes are added later.
        try {
            $cols = [];
            foreach ($db->query('SHOW COLUMNS FROM office_hierarchy_nodes')->fetchAll() as $c) {
                $cols[strtolower((string) $c['Field'])] = true;
            }
            if (!isset($cols['code'])) {
                $db->query('ALTER TABLE office_hierarchy_nodes ADD COLUMN code VARCHAR(30) NULL AFTER name');
            }
        } catch (Throwable $e) { /* alter refused — reference-number derivation falls back to name */ }

        // Decision-point toggles added after the initial schema.
        // Idempotent via SHOW COLUMNS; failed ALTERs are tolerated
        // (DB user may not have ALTER privilege on hosted setups).
        try {
            $cols = [];
            foreach ($db->query('SHOW COLUMNS FROM meeting_decision')->fetchAll() as $c) $cols[strtolower((string) $c['Field'])] = true;
            if (!isset($cols['create_own_tasks'])) {
                $db->query('ALTER TABLE meeting_decision ADD COLUMN create_own_tasks TINYINT(1) NOT NULL DEFAULT 1 AFTER due_date');
            }
            if (!isset($cols['status_private'])) {
                $db->query('ALTER TABLE meeting_decision ADD COLUMN status_private TINYINT(1) NOT NULL DEFAULT 0 AFTER create_own_tasks');
            }
            // Free-text remarks captured in the bulk-entry grid alongside
            // heading / description / due date. Visible on the full
            // decision modal and the meeting view too so the field is
            // not entry-only.
            if (!isset($cols['remarks'])) {
                $db->query('ALTER TABLE meeting_decision ADD COLUMN remarks TEXT NULL AFTER due_date');
            }
        } catch (Throwable $e) { /* ALTER refused — decision modal still works without the toggles */ }

        // Seed the MoM PDF branding keys so the settings page always
        // renders every row even on a fresh install.
        $seed = $db->prepare('INSERT IGNORE INTO meeting_setting (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())');
        foreach ([
            'mom_logo_url'      => '',
            'mom_heading'       => 'Minutes of Meeting',
            'mom_sub_heading'   => '',
            'mom_footer'        => 'Prepared under the Post Job Fair CRM.',
        ] as $k => $v) {
            $seed->execute([$k, $v]);
        }
    } catch (Throwable $e) { /* DB user lacks CREATE — module still returns empty everywhere */ }
}

/**
 * SHOW-COLUMNS cache for a single column lookup. Used by the bulk-entry
 * grid + the AJAX layer to decide whether optional columns (remarks,
 * fan_out_teams, etc.) exist on this host. Failures are tolerated and
 * treated as "column missing" so the page still renders.
 */
function meetings_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        foreach (db()->query('SHOW COLUMNS FROM ' . $table)->fetchAll() as $c) {
            if (strtolower((string) $c['Field']) === strtolower($column)) return $cache[$key] = true;
        }
    } catch (Throwable $e) { /* table missing */ }
    return $cache[$key] = false;
}

function meetings_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT setting_key, setting_value FROM meeting_setting')->fetchAll() as $r) {
                $cache[(string) $r['setting_key']] = (string) ($r['setting_value'] ?? '');
            }
        } catch (Throwable $e) { /* table missing */ }
    }
    return $cache[$key] ?? $default;
}

/**
 * Fetch the code + name for every Division row. Used when building
 * the "owning division" dropdown on a new meeting. Falls back to
 * empty when the code column doesn't exist yet.
 */
function meetings_divisions(): array
{
    try {
        return db()->query("SELECT id, name, code
            FROM office_hierarchy_nodes
            WHERE level_type = 'division' AND active_status = 1
            ORDER BY name ASC")->fetchAll();
    } catch (Throwable $e) {
        try {
            return db()->query("SELECT id, name FROM office_hierarchy_nodes
                WHERE level_type = 'division' AND active_status = 1
                ORDER BY name ASC")->fetchAll();
        } catch (Throwable $e2) { return []; }
    }
}

/**
 * Generate the next meeting reference number for a given division.
 * Format: <CODE>/<YYYY>/<N> where N is a per-(division,year) counter.
 * Callers should hold a row lock or wrap the INSERT in a
 * transaction — the counter is derived from MAX() so a concurrent
 * insert without lock could collide.
 */
function meetings_generate_reference(int $divisionId): string
{
    $year = (int) date('Y');
    $code = 'GEN';
    try {
        if ($divisionId > 0) {
            $stmt = db()->prepare('SELECT code, name FROM office_hierarchy_nodes WHERE id = ?');
            $stmt->execute([$divisionId]);
            $row = $stmt->fetch();
            if ($row !== false) {
                $stored = trim((string) ($row['code'] ?? ''));
                if ($stored !== '') $code = strtoupper($stored);
                else {
                    // Fallback: first 3 uppercased letters of the name.
                    $abbrev = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $row['name']));
                    if ($abbrev !== '') $code = substr($abbrev, 0, 3);
                }
            }
        }
    } catch (Throwable $e) { /* keep GEN */ }

    // Next N per (code, year).
    $prefix = $code . '/' . $year . '/';
    try {
        $stmt = db()->prepare('SELECT reference_no FROM meeting WHERE reference_no LIKE ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $lastN = 0;
        if ($last !== false && preg_match('#/(\d+)$#', (string) $last, $m)) $lastN = (int) $m[1];
        $n = $lastN + 1;
    } catch (Throwable $e) { $n = 1; }
    return $prefix . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
}

/**
 * Effective status of a meeting. When the stored value is 'scheduled'
 * and the meeting date + to_time has already passed, treat it as
 * completed at render time — no cron needed. Cancelled stays
 * cancelled forever regardless of date.
 */
function meetings_effective_status(array $m): string
{
    $stored = (string) ($m['status'] ?? 'scheduled');
    if ($stored === 'cancelled' || $stored === 'completed') return $stored;
    $dateEnd = (string) ($m['meeting_date'] ?? '');
    if ($dateEnd === '') return $stored;
    $endTime = (string) ($m['to_time'] ?? '23:59:59');
    if (strlen($endTime) === 5) $endTime .= ':00';
    return (strtotime($dateEnd . ' ' . $endTime) < time()) ? 'completed' : $stored;
}

function meetings_status_tone(string $status): string
{
    return match ($status) {
        'completed' => 'success',
        'cancelled' => 'secondary',
        'inprogress' => 'primary',
        default      => 'info',
    };
}

/**
 * Return the full set of internal user IDs that a decision point's
 * tasks should land on. Combines:
 *   - user_id rows in meeting_decision_responsible (direct)
 *   - team_id rows expanded through teams_target_user_ids(), honouring
 *     the decision's fan_out_teams flag (team head only vs every member).
 * Returns an empty array when the decision has no responsibilities, or
 * when the teams schema is absent on this host.
 */
function meetings_decision_target_user_ids(int $decisionId): array
{
    $out = [];
    try {
        $st = db()->prepare('SELECT DISTINCT user_id FROM meeting_decision_responsible WHERE decision_id = ? AND user_id IS NOT NULL');
        $st->execute([$decisionId]);
        foreach ($st->fetchAll() as $r) {
            $uid = (int) ($r['user_id'] ?? 0);
            if ($uid > 0 && !in_array($uid, $out, true)) $out[] = $uid;
        }
    } catch (Throwable $e) { /* ignore */ }

    if (!function_exists('teams_responsible_has_team_id')) return $out;
    if (!teams_responsible_has_team_id()) return $out;

    $fanOut = false;
    if (function_exists('teams_decision_has_fan_out') && teams_decision_has_fan_out()) {
        try {
            $st = db()->prepare('SELECT fan_out_teams FROM meeting_decision WHERE id = ?');
            $st->execute([$decisionId]);
            $fanOut = (int) ($st->fetchColumn() ?: 0) === 1;
        } catch (Throwable $e) { /* ignore */ }
    }
    try {
        $st = db()->prepare('SELECT DISTINCT team_id FROM meeting_decision_responsible WHERE decision_id = ? AND team_id IS NOT NULL');
        $st->execute([$decisionId]);
        foreach ($st->fetchAll() as $r) {
            $tid = (int) ($r['team_id'] ?? 0);
            if ($tid <= 0) continue;
            foreach (teams_target_user_ids($tid, $fanOut) as $uid) {
                if (!in_array((int) $uid, $out, true)) $out[] = (int) $uid;
            }
        }
    } catch (Throwable $e) { /* ignore */ }
    return $out;
}

/**
 * Sync "Own Tasks" rows for a decision point. For every internal
 * user responsible on the decision who holds an active seat, upsert
 * a task in the "Own Tasks" (code=OWN) project; the task's primary
 * assignment is that user's seat so it lands in My Work. Users
 * previously responsible but no longer get their task deactivated.
 *
 * Best-effort — a DB error anywhere in here just rolls back the
 * nested transaction and returns silently so the caller's own
 * commit is never undone.
 */
function meetings_sync_decision_tasks(int $decisionId, array $userIds, int $viewerId): void
{
    try {
        $db = db();
        $st = $db->prepare("SELECT id, next_task_number FROM project WHERE code = 'OWN' LIMIT 1");
        $st->execute();
        $ownProject = $st->fetch();
        if ($ownProject === false) return;
        $ownProjectId = (int) $ownProject['id'];

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

        $defaultStatus = (int) ($db->query("SELECT id FROM task_status WHERE name = 'Not Started' LIMIT 1")->fetchColumn() ?: 0);
        if ($defaultStatus === 0) $defaultStatus = (int) ($db->query('SELECT id FROM task_status WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 1')->fetchColumn() ?: 0);

        // Does task_assignment carry user_id? Set once we probe the
        // schema; if the column is missing on this host, seatless users
        // still get a task row but no assignment — admins will see it,
        // and reassigning a seat later surfaces it via My Work.
        $hasUserCol = function_exists('task_tracker_column_exists')
            ? task_tracker_column_exists('task_assignment', 'user_id')
            : false;

        // Existing-by-user map: covers both seat-held assignments (via
        // the officer history join) and direct user_id assignments.
        $existingByUser = [];
        $st = $db->prepare('SELECT t.id, ta.seat_id, oh.officer_id
            FROM task t
            LEFT JOIN task_assignment ta ON ta.task_id = t.id AND ta.role = "primary"
            LEFT JOIN office_hierarchy_officer_history oh ON oh.node_id = ta.seat_id AND oh.unassigned_at IS NULL
            WHERE t.meeting_decision_id = ?');
        $st->execute([$decisionId]);
        foreach ($st->fetchAll() as $t) {
            $uid = (int) ($t['officer_id'] ?? 0);
            if ($uid > 0 && !isset($existingByUser[$uid])) $existingByUser[$uid] = (int) $t['id'];
        }
        if ($hasUserCol) {
            $st = $db->prepare('SELECT t.id, ta.user_id
                FROM task t
                INNER JOIN task_assignment ta ON ta.task_id = t.id AND ta.role = "primary"
                WHERE t.meeting_decision_id = ? AND ta.user_id IS NOT NULL');
            $st->execute([$decisionId]);
            foreach ($st->fetchAll() as $t) {
                $uid = (int) ($t['user_id'] ?? 0);
                if ($uid > 0 && !isset($existingByUser[$uid])) $existingByUser[$uid] = (int) $t['id'];
            }
        }

        // Wanted-by-user: seat if the user holds one, else 0 so the
        // insert path knows to fall back to a user_id assignment.
        $wantedByUser = [];
        foreach ($userIds as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0) continue;
            $sst = $db->prepare('SELECT node_id FROM office_hierarchy_officer_history WHERE officer_id = ? AND unassigned_at IS NULL ORDER BY id DESC LIMIT 1');
            $sst->execute([$uid]);
            $seatId = (int) ($sst->fetchColumn() ?: 0);
            $wantedByUser[$uid] = $seatId; // 0 = seatless, assign direct
        }

        foreach ($wantedByUser as $uid => $seatId) {
            if (isset($existingByUser[$uid])) {
                $taskId = $existingByUser[$uid];
                $db->prepare('UPDATE task SET title = ?, description = ?, planned_end = ?, is_active = 1, updated_at = NOW(), updated_by = ? WHERE id = ?')
                   ->execute([$title, $desc === '' ? null : $desc, $due, $viewerId, $taskId]);
            } else {
                $db->query('START TRANSACTION');
                $lk = $db->prepare('SELECT next_task_number FROM project WHERE id = ? FOR UPDATE');
                $lk->execute([$ownProjectId]);
                $nextNum = (int) ($lk->fetchColumn() ?: 1);
                $db->prepare('INSERT INTO task (project_id, task_number, title, description, status_id, priority, planned_end, meeting_decision_id, is_active, created_at, updated_at, created_by, updated_by, board_order)
                    VALUES (?, ?, ?, ?, ?, "medium", ?, ?, 1, NOW(), NOW(), ?, ?, ?)')
                    ->execute([$ownProjectId, $nextNum, $title, $desc === '' ? null : $desc, $defaultStatus, $due, $decisionId, $viewerId, $viewerId, $nextNum * 1000]);
                $newId = $db->lastInsertId();
                $db->prepare('UPDATE project SET next_task_number = next_task_number + 1 WHERE id = ?')->execute([$ownProjectId]);
                if ($seatId > 0) {
                    // Preferred — seat-primary assignment, so the task
                    // follows the seat if the officer rotates.
                    if ($hasUserCol) {
                        $db->prepare('INSERT INTO task_assignment (task_id, seat_id, user_id, role, created_at, created_by) VALUES (?, ?, NULL, "primary", NOW(), ?)')
                           ->execute([$newId, $seatId, $viewerId]);
                    } else {
                        $db->prepare('INSERT INTO task_assignment (task_id, seat_id, role, created_at, created_by) VALUES (?, ?, "primary", NOW(), ?)')
                           ->execute([$newId, $seatId, $viewerId]);
                    }
                } elseif ($hasUserCol) {
                    // Fallback — seatless user. seat_id = 0 is a sentinel
                    // My Work ignores; the user_id row is what matches.
                    $db->prepare('INSERT INTO task_assignment (task_id, seat_id, user_id, role, created_at, created_by) VALUES (?, 0, ?, "primary", NOW(), ?)')
                       ->execute([$newId, $uid, $viewerId]);
                }
                // If !$hasUserCol and no seat: the task row is created
                // but has no assignment. The seatless owner won't see
                // it in My Work until an admin assigns them a seat and
                // the decision point is re-saved.
                $db->query('COMMIT');
                $existingByUser[$uid] = $newId;
            }
        }
        foreach ($existingByUser as $uid => $taskId) {
            if (!isset($wantedByUser[$uid])) {
                $db->prepare('UPDATE task SET is_active = 0, updated_at = NOW(), updated_by = ? WHERE id = ?')
                   ->execute([$viewerId, $taskId]);
            }
        }
    } catch (Throwable $e) {
        try { db()->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
    }
}

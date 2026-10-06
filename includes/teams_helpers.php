<?php
/**
 * Teams module — small master used by the Meetings module to assign a
 * decision point to a group of users at once.
 *
 * Tables:
 *   team          id, name, head_user_id, description, is_active, …
 *   team_member   id, team_id, user_id, created_at
 *
 * Semantics on a decision:
 *   - Pick one or more teams on a decision point.
 *   - By default the Own-Task sync creates ONE task on the team head's
 *     seat; the head splits work with the existing sub-activity flow.
 *   - A per-decision `fan_out_teams` toggle flips it to "create a task
 *     for every member seat". That lives on meeting_decision.
 *
 * This file also adds:
 *   - meeting_decision.fan_out_teams TINYINT(1) DEFAULT 0
 *   - meeting_decision_responsible.team_id INT NULL
 * via idempotent ALTERs. ALTER failures are tolerated (hosted DB user
 * may lack ALTER) — the decision modal / sync fall back to the
 * no-teams flow silently.
 */

require_once __DIR__ . '/db.php';

function teams_bootstrap(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = db();

    try {
        $db->query("CREATE TABLE IF NOT EXISTS team (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            head_user_id INT NULL,
            description TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            created_by INT NULL,
            updated_by INT NULL,
            KEY idx_active (is_active),
            KEY idx_head (head_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS team_member (
            id INT AUTO_INCREMENT PRIMARY KEY,
            team_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at DATETIME NULL,
            KEY idx_team (team_id),
            KEY idx_user (user_id),
            UNIQUE KEY unique_team_user (team_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Idempotent column adds on meeting_decision* so this file can
        // bootstrap teams without the user needing to visit the meetings
        // settings page first.
        try {
            $cols = [];
            foreach ($db->query('SHOW COLUMNS FROM meeting_decision')->fetchAll() as $c) {
                $cols[strtolower((string) $c['Field'])] = true;
            }
            if (!isset($cols['fan_out_teams'])) {
                $db->query('ALTER TABLE meeting_decision ADD COLUMN fan_out_teams TINYINT(1) NOT NULL DEFAULT 0 AFTER status_private');
            }
        } catch (Throwable $e) { /* ALTER refused — fan-out silently disabled */ }

        try {
            $cols = [];
            foreach ($db->query('SHOW COLUMNS FROM meeting_decision_responsible')->fetchAll() as $c) {
                $cols[strtolower((string) $c['Field'])] = true;
            }
            if (!isset($cols['team_id'])) {
                $db->query('ALTER TABLE meeting_decision_responsible ADD COLUMN team_id INT NULL AFTER seat_id');
            }
        } catch (Throwable $e) { /* ALTER refused — teams link column missing but modal still works */ }
    } catch (Throwable $e) { /* CREATE refused — teams master is a no-op */ }
}

/**
 * Does the meeting_decision_responsible table have the team_id column?
 * Callers short-circuit teams UI + sync when this is false so a hosted
 * DB without ALTER still works.
 */
function teams_responsible_has_team_id(): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        foreach (db()->query('SHOW COLUMNS FROM meeting_decision_responsible')->fetchAll() as $c) {
            if (strtolower((string) $c['Field']) === 'team_id') return $cached = true;
        }
    } catch (Throwable $e) { /* table missing */ }
    return $cached = false;
}

function teams_decision_has_fan_out(): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        foreach (db()->query('SHOW COLUMNS FROM meeting_decision')->fetchAll() as $c) {
            if (strtolower((string) $c['Field']) === 'fan_out_teams') return $cached = true;
        }
    } catch (Throwable $e) { /* table missing */ }
    return $cached = false;
}

/**
 * List active teams with their members pre-populated. Shape:
 *   [ { id, name, head_user_id, head_name, description, members: [{user_id,name}] } ]
 */
function teams_list_active(): array
{
    try {
        $rows = db()->query("SELECT t.id, t.name, t.head_user_id, t.description,
            uh.name AS head_name
            FROM team t
            LEFT JOIN users uh ON uh.id = t.head_user_id
            WHERE t.is_active = 1
            ORDER BY t.name ASC")->fetchAll();
    } catch (Throwable $e) { return []; }
    if ($rows === []) return [];
    $byId = [];
    foreach ($rows as $r) {
        $r['members'] = [];
        $byId[(int) $r['id']] = $r;
    }
    try {
        $mems = db()->query("SELECT tm.team_id, tm.user_id, u.name
            FROM team_member tm
            INNER JOIN users u ON u.id = tm.user_id
            WHERE tm.team_id IN (" . implode(',', array_map('intval', array_keys($byId))) . ')
              AND u.active_status = 1
            ORDER BY u.name ASC')->fetchAll();
        foreach ($mems as $m) {
            $tid = (int) $m['team_id'];
            if (isset($byId[$tid])) {
                $byId[$tid]['members'][] = ['user_id' => (int) $m['user_id'], 'name' => (string) $m['name']];
            }
        }
    } catch (Throwable $e) { /* ignore */ }
    return array_values($byId);
}

/**
 * List for the admin screen — includes inactive teams, filtered by
 * $q (name LIKE / description LIKE). Members pre-populated like
 * teams_list_active() but regardless of is_active.
 */
function teams_list_active_or_all(string $q = ''): array
{
    try {
        $sql = "SELECT t.id, t.name, t.head_user_id, t.description, t.is_active,
            uh.name AS head_name
            FROM team t
            LEFT JOIN users uh ON uh.id = t.head_user_id
            WHERE 1=1";
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (t.name LIKE ? OR t.description LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like);
        }
        $sql .= ' ORDER BY t.is_active DESC, t.name ASC';
        $st = db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (Throwable $e) { return []; }
    if ($rows === []) return [];
    $byId = [];
    foreach ($rows as $r) {
        $r['members'] = [];
        $byId[(int) $r['id']] = $r;
    }
    try {
        $mems = db()->query("SELECT tm.team_id, tm.user_id, u.name
            FROM team_member tm
            INNER JOIN users u ON u.id = tm.user_id
            WHERE tm.team_id IN (" . implode(',', array_map('intval', array_keys($byId))) . ")
            ORDER BY u.name ASC")->fetchAll();
        foreach ($mems as $m) {
            $tid = (int) $m['team_id'];
            if (isset($byId[$tid])) {
                $byId[$tid]['members'][] = ['user_id' => (int) $m['user_id'], 'name' => (string) $m['name']];
            }
        }
    } catch (Throwable $e) { /* ignore */ }
    return array_values($byId);
}

/**
 * Resolve a team to the user IDs that should receive Own-Tasks for a
 * given decision point. When $fanOut is true, every active member is
 * returned; otherwise only the team head (if the head is active).
 */
function teams_target_user_ids(int $teamId, bool $fanOut): array
{
    try {
        $st = db()->prepare('SELECT head_user_id FROM team WHERE id = ? AND is_active = 1');
        $st->execute([$teamId]);
        $headId = (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) { return []; }
    if ($teamId <= 0) return [];
    if (!$fanOut) {
        if ($headId <= 0) return [];
        try {
            $st = db()->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND active_status = 1');
            $st->execute([$headId]);
            if ((int) $st->fetchColumn() === 0) return [];
        } catch (Throwable $e) { /* best effort */ }
        return [$headId];
    }
    try {
        $st = db()->prepare('SELECT tm.user_id FROM team_member tm
            INNER JOIN users u ON u.id = tm.user_id
            WHERE tm.team_id = ? AND u.active_status = 1');
        $st->execute([$teamId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $uid = (int) ($r['user_id'] ?? 0);
            if ($uid > 0 && !in_array($uid, $out, true)) $out[] = $uid;
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * Return [team_id => true] for teams linked to this decision.
 */
function teams_for_decision(int $decisionId): array
{
    if (!teams_responsible_has_team_id()) return [];
    try {
        $st = db()->prepare('SELECT DISTINCT team_id FROM meeting_decision_responsible
            WHERE decision_id = ? AND team_id IS NOT NULL');
        $st->execute([$decisionId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $tid = (int) ($r['team_id'] ?? 0);
            if ($tid > 0) $out[$tid] = true;
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

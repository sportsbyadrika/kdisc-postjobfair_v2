<?php
/**
 * Task Tracker — schema bootstrap, scope helpers, and permission guards.
 *
 * This is the first module to introduce CSRF (see includes/csrf.php) and
 * the first to lean on the office-spine tables (office_hierarchy_nodes +
 * office_hierarchy_officer_history). Every table added by
 * task_tracker_bootstrap() is created idempotently — safe to call at the
 * top of every task-tracker page load.
 *
 * See CLAUDE.md at the repo root for the standing rules this module
 * follows (parameterised queries, DECIMAL(18,6) board_order, history-
 * row-per-change, deactivate-never-delete, DD/MM/YYYY UI dates).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/office_hierarchy_helpers.php';

/**
 * Single-office deployment for now, but every "office_id" column stays
 * populated with this value so multi-office is a config flip later,
 * never a code sweep. Deliberately not hardcoded across the module.
 */
if (!defined('TASK_TRACKER_OFFICE_ID')) {
    define('TASK_TRACKER_OFFICE_ID', 1);
}

/**
 * Palette users' initials avatars draw from. Bootstrap 5.3 semantic
 * tones — same tokens the rest of the app already uses — so new
 * initials avatars fit visually with the existing badges and pills.
 * A user gets one on creation and it's stable thereafter.
 */
if (!defined('TASK_TRACKER_AVATAR_COLOURS')) {
    define('TASK_TRACKER_AVATAR_COLOURS', [
        'primary', 'success', 'danger', 'warning',
        'info',    'secondary', 'dark',   'purple',
    ]);
}

/**
 * Idempotent schema bootstrap. Creates the Task Tracker tables, adds
 * three columns to existing tables (avatar_colour, responsibility_level,
 * is_additional_charge), and seeds task_status once.
 */
function task_tracker_bootstrap(): void
{
    $db = db();

    // -------------------------------------------------------------------
    // task_status — the columns on the Kanban board. Seeded once.
    // -------------------------------------------------------------------
    $db->query("CREATE TABLE IF NOT EXISTS task_status (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        colour_token VARCHAR(40) NOT NULL DEFAULT 'secondary',
        category ENUM('todo', 'inprogress', 'done') NOT NULL DEFAULT 'todo',
        is_terminal TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        created_by INT NULL,
        updated_by INT NULL,
        UNIQUE KEY unique_name (name),
        KEY idx_sort_order (sort_order),
        KEY idx_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $seeded = (int) $db->query('SELECT COUNT(*) FROM task_status')->fetchColumn();
    if ($seeded === 0) {
        $ins = $db->prepare('INSERT INTO task_status
            (name, sort_order, colour_token, category, is_terminal, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
        foreach ([
            ['Not Started', 10, 'neutral', 'todo',       0],
            ['In Progress', 20, 'primary', 'inprogress', 0],
            ['On Hold',     30, 'warning', 'todo',       0],
            ['Review',      40, 'info',    'inprogress', 0],
            ['Completed',   50, 'success', 'done',       1],
            ['Dropped',     60, 'danger',  'done',       1],
        ] as $row) {
            try { $ins->execute($row); } catch (Throwable $e) { /* ignore */ }
        }
    }

    // -------------------------------------------------------------------
    // project — one row per project inside an office.
    // -------------------------------------------------------------------
    $db->query("CREATE TABLE IF NOT EXISTS project (
        id INT AUTO_INCREMENT PRIMARY KEY,
        office_id INT NOT NULL,
        name VARCHAR(200) NOT NULL,
        code VARCHAR(20) NOT NULL,
        description TEXT NULL,
        financial_year VARCHAR(20) NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        status_id INT NULL,
        next_task_number INT NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        created_by INT NULL,
        updated_by INT NULL,
        UNIQUE KEY unique_office_code (office_id, code),
        KEY idx_office (office_id),
        KEY idx_active (is_active),
        KEY idx_status (status_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // -------------------------------------------------------------------
    // task — the item that moves on the board. Parent_id NULL = Activity,
    // set = Sub-activity. board_order is DECIMAL(18,6) so a mid-drop
    // updates ONE row instead of rewriting the column.
    // -------------------------------------------------------------------
    $db->query("CREATE TABLE IF NOT EXISTS task (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        task_number INT NOT NULL,
        parent_id INT NULL,
        title VARCHAR(500) NOT NULL,
        description TEXT NULL,
        target TEXT NULL,
        status_id INT NOT NULL,
        priority ENUM('lowest','low','medium','high','highest') NOT NULL DEFAULT 'medium',
        planned_start DATE NULL,
        planned_end DATE NULL,
        actual_start DATE NULL,
        actual_end DATE NULL,
        board_order DECIMAL(18,6) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        created_by INT NULL,
        updated_by INT NULL,
        UNIQUE KEY unique_project_task_number (project_id, task_number),
        KEY idx_project (project_id),
        KEY idx_parent (parent_id),
        KEY idx_status (status_id),
        KEY idx_column (project_id, status_id, board_order),
        KEY idx_planned_end (planned_end),
        KEY idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // -------------------------------------------------------------------
    // task_assignment — a seat holds primary or secondary responsibility
    // for a task. Exactly one primary per task is enforced in app code
    // (a UNIQUE(task_id, role) would forbid multiple secondaries).
    // -------------------------------------------------------------------
    $db->query("CREATE TABLE IF NOT EXISTS task_assignment (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        seat_id INT NOT NULL,
        role ENUM('primary','secondary') NOT NULL DEFAULT 'secondary',
        created_at DATETIME NULL,
        created_by INT NULL,
        UNIQUE KEY unique_task_seat_role (task_id, seat_id, role),
        KEY idx_task (task_id),
        KEY idx_seat (seat_id),
        KEY idx_role (role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // -------------------------------------------------------------------
    // task_history — every field change on a task writes a row in the
    // same transaction as the change. Nothing is ever deleted.
    // -------------------------------------------------------------------
    $db->query("CREATE TABLE IF NOT EXISTS task_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        changed_by INT NULL,
        changed_at DATETIME NOT NULL,
        KEY idx_task (task_id),
        KEY idx_changed_by (changed_by),
        KEY idx_changed_at (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // -------------------------------------------------------------------
    // task_remark — free-form comments on a task, oldest to newest.
    // -------------------------------------------------------------------
    $db->query("CREATE TABLE IF NOT EXISTS task_remark (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        remark TEXT NOT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_task (task_id),
        KEY idx_created_by (created_by),
        KEY idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // -------------------------------------------------------------------
    // task_file — one row per uploaded attachment. Bytes live on disk
    // under uploads/task_tracker/{project_id}/ (created by upload
    // handler in a later phase).
    // -------------------------------------------------------------------
    $db->query("CREATE TABLE IF NOT EXISTS task_file (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_path VARCHAR(500) NOT NULL,
        mime_type VARCHAR(120) NULL,
        size_bytes INT UNSIGNED NULL,
        uploaded_by INT NULL,
        uploaded_at DATETIME NOT NULL,
        KEY idx_task (task_id),
        KEY idx_uploaded_by (uploaded_by),
        KEY idx_uploaded_at (uploaded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // -------------------------------------------------------------------
    // Idempotent column extensions on existing tables (users, office
    // hierarchy nodes, officer history).
    // -------------------------------------------------------------------
    task_tracker__add_column_if_missing($db, 'users', 'avatar_colour',
        "ALTER TABLE users ADD COLUMN avatar_colour VARCHAR(20) NULL AFTER email");

    task_tracker__add_column_if_missing($db, 'office_hierarchy_nodes', 'responsibility_level',
        "ALTER TABLE office_hierarchy_nodes ADD COLUMN responsibility_level
         ENUM('staff','section_head','division_head','office_head') NULL AFTER designation");

    task_tracker__add_column_if_missing($db, 'office_hierarchy_officer_history', 'is_additional_charge',
        "ALTER TABLE office_hierarchy_officer_history ADD COLUMN is_additional_charge
         TINYINT(1) NOT NULL DEFAULT 0 AFTER designation");

    task_tracker__add_column_if_missing($db, 'office_hierarchy_officer_history', 'from_date',
        "ALTER TABLE office_hierarchy_officer_history ADD COLUMN from_date DATE NULL AFTER assigned_at");

    task_tracker__add_column_if_missing($db, 'office_hierarchy_officer_history', 'to_date',
        "ALTER TABLE office_hierarchy_officer_history ADD COLUMN to_date DATE NULL AFTER unassigned_at");
}

/**
 * SHOW COLUMNS-guarded ALTER helper. Wraps every ALTER in a try so an
 * install that already has the column (or is racing another request)
 * doesn't 500. If the ALTER fails (typically because the DB user lacks
 * the ALTER privilege), the failure is remembered — task_tracker_missing_columns()
 * reports it so pages that need the column can degrade gracefully
 * instead of crashing on an INSERT that references it.
 */
function task_tracker__add_column_if_missing(Database $db, string $table, string $column, string $alterSql): void
{
    static $checked = [];
    $key = strtolower($table . '.' . $column);
    if (isset($checked[$key])) return;
    try {
        $cols = [];
        foreach ($db->query('SHOW COLUMNS FROM ' . $table)->fetchAll() as $c) {
            $cols[strtolower((string) $c['Field'])] = true;
        }
        if (isset($cols[strtolower($column)])) {
            $checked[$key] = true;
            $GLOBALS['task_tracker_column_cache'][$key] = true;
            return;
        }
        $db->query($alterSql);
        $checked[$key] = true;
        $GLOBALS['task_tracker_column_cache'][$key] = true;
    } catch (Throwable $e) {
        // Table doesn't exist yet, or the ALTER failed (DB user lacks
        // ALTER privilege, is racing another request, etc.). Remember
        // so the page can adapt without repeating the error.
        $GLOBALS['task_tracker_column_cache'][$key] = false;
    }
}

/**
 * True when the column exists on the table, false otherwise. Uses the
 * cache task_tracker__add_column_if_missing populates. Callers can
 * check this before adding a column reference to an INSERT / UPDATE
 * that would otherwise fail on an environment where the bootstrap
 * ALTER did not apply.
 */
function task_tracker_column_exists(string $table, string $column): bool
{
    $key = strtolower($table . '.' . $column);
    if (isset($GLOBALS['task_tracker_column_cache'][$key])) {
        return (bool) $GLOBALS['task_tracker_column_cache'][$key];
    }
    // Not yet checked (bootstrap for that column didn't run this request).
    // Consult SHOW COLUMNS directly and cache the answer.
    try {
        foreach (db()->query('SHOW COLUMNS FROM ' . $table)->fetchAll() as $c) {
            if (strtolower((string) $c['Field']) === strtolower($column)) {
                return $GLOBALS['task_tracker_column_cache'][$key] = true;
            }
        }
    } catch (Throwable $e) { /* fall through to false */ }
    return $GLOBALS['task_tracker_column_cache'][$key] = false;
}

/**
 * Deterministic avatar colour picker used when a user is created without
 * one. Pure function so the same user always gets the same colour on a
 * given install, and imports / seeds are reproducible.
 */
function task_tracker_default_avatar_colour(string $seed): string
{
    $palette = TASK_TRACKER_AVATAR_COLOURS;
    if ($seed === '') return $palette[0];
    $h = crc32($seed);
    return $palette[$h % count($palette)];
}

/**
 * Compute a user's live task-tracker scope.
 *
 * A user's rights are derived from every seat they currently hold. When
 * multiple seats give different levels, take the widest.
 *
 * Returns:
 *   [
 *     'seat_ids'     => [int, ...],   // seats held today
 *     'section_ids'  => [int, ...],   // sections those seats belong to
 *     'division_ids' => [int, ...],   // divisions those sections belong to
 *     'office_ids'   => [int, ...],   // offices those divisions belong to
 *     'level'        => 'admin' | 'office_head' | 'division_head' | 'section_head' | 'staff' | 'viewer',
 *   ]
 */
function get_user_scope(int $userId): array
{
    $scope = [
        'seat_ids'     => [],
        'section_ids'  => [],
        'division_ids' => [],
        'office_ids'   => [],
        'level'        => 'viewer',
    ];

    if ($userId <= 0) return $scope;

    // Admin group short-circuits: is_manage_admin sees everything.
    $viewer = current_user() ?? [];
    if (($viewer['id'] ?? 0) === $userId && is_manage_admin($viewer)) {
        $scope['level'] = 'admin';
        // Admins have implicit view/edit rights everywhere — they don't
        // need seats populated to see tasks. Callers that need a seat-
        // list for a filter still get []; they should just not filter.
        return $scope;
    }

    // Every currently-held seat (unassigned_at IS NULL) + its
    // responsibility_level, and its section/division/office by walking
    // up the parent chain.
    try {
        $stmt = db()->prepare("SELECT h.node_id AS seat_id,
                n.responsibility_level AS resp_level,
                n.parent_id AS section_id,
                s.parent_id AS division_id,
                d.parent_id AS office_id
            FROM office_hierarchy_officer_history h
            INNER JOIN office_hierarchy_nodes n ON n.id = h.node_id
            LEFT JOIN office_hierarchy_nodes s ON s.id = n.parent_id
            LEFT JOIN office_hierarchy_nodes d ON d.id = s.parent_id
            WHERE h.officer_id = ? AND h.unassigned_at IS NULL
              AND n.level_type = 'seat' AND n.active_status = 1");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }

    if ($rows === []) return $scope;

    // Widest level across every held seat. Order matters: office_head >
    // division_head > section_head > staff. A user who holds two seats
    // where one is a section head and the other a staff seat gets
    // section_head level.
    $levelRank = ['staff' => 1, 'section_head' => 2, 'division_head' => 3, 'office_head' => 4];
    $best = 0;

    foreach ($rows as $r) {
        $sid = (int) ($r['seat_id']    ?? 0);
        $sec = (int) ($r['section_id'] ?? 0);
        $div = (int) ($r['division_id'] ?? 0);
        $off = (int) ($r['office_id']  ?? 0);
        if ($sid > 0 && !in_array($sid, $scope['seat_ids'],     true)) $scope['seat_ids'][]     = $sid;
        if ($sec > 0 && !in_array($sec, $scope['section_ids'],  true)) $scope['section_ids'][]  = $sec;
        if ($div > 0 && !in_array($div, $scope['division_ids'], true)) $scope['division_ids'][] = $div;
        if ($off > 0 && !in_array($off, $scope['office_ids'],   true)) $scope['office_ids'][]   = $off;
        $l = strtolower((string) ($r['resp_level'] ?? 'staff')) ?: 'staff';
        $r2 = $levelRank[$l] ?? 1;
        if ($r2 > $best) { $best = $r2; $scope['level'] = $l; }
    }

    if ($best === 0) $scope['level'] = 'staff';
    return $scope;
}

/**
 * Guard: refuses access unless the viewer has ANY task-tracker footprint
 * — either an admin-group role OR at least one seat held today.
 */
function require_task_tracker_access(): void
{
    require_auth();
    $u = current_user() ?? [];
    if (is_manage_admin($u)) return;
    $scope = get_user_scope((int) ($u['id'] ?? 0));
    if ($scope['seat_ids'] === []) {
        http_response_code(403);
        echo 'Access denied — Task Tracker requires you to hold at least one seat, or admin access.';
        exit;
    }
}

/**
 * Guard: refuses unless the viewer is admin-group. Used for masters
 * (status, project settings, imports).
 */
function require_task_tracker_admin(): void
{
    require_auth();
    $u = current_user() ?? [];
    if (!is_manage_admin($u)) {
        http_response_code(403);
        echo 'Access denied — Administrator role required for Task Tracker administration.';
        exit;
    }
}

/**
 * Permission predicates for a specific task row. Each takes the viewer
 * id + a task row (or a lightweight [id, primary_seat_id, section_id
 * chain...] shape); a null task row means "checking a future task", in
 * which case only the level is consulted.
 *
 * These are the SINGLE source of truth for permission decisions across
 * every task-tracker page. Do not repeat the logic inline.
 */
function can_view_task(int $viewerId, ?array $task = null): bool
{
    $u = current_user() ?? [];
    if (($u['id'] ?? 0) === $viewerId && is_manage_admin($u)) return true;
    $scope = get_user_scope($viewerId);
    if ($scope['level'] === 'admin' || $scope['level'] === 'office_head') return true;
    if ($task === null) return true;
    $primarySeat  = (int) ($task['primary_seat_id']  ?? 0);
    $primarySection  = (int) ($task['primary_section_id']  ?? 0);
    $primaryDivision = (int) ($task['primary_division_id'] ?? 0);
    if ($primarySeat > 0 && in_array($primarySeat, $scope['seat_ids'], true)) return true;
    if ($scope['level'] === 'division_head' && $primaryDivision > 0 && in_array($primaryDivision, $scope['division_ids'], true)) return true;
    if ($scope['level'] === 'section_head'  && $primarySection  > 0 && in_array($primarySection,  $scope['section_ids'],  true)) return true;
    return false;
}

function can_edit_task(int $viewerId, ?array $task = null): bool
{
    $u = current_user() ?? [];
    if (($u['id'] ?? 0) === $viewerId && is_manage_admin($u)) return true;
    $scope = get_user_scope($viewerId);
    if (in_array($scope['level'], ['admin', 'office_head', 'division_head', 'section_head'], true)) {
        return can_view_task($viewerId, $task);
    }
    // Staff can edit their own tasks (primary seat).
    if ($task !== null) {
        $primary = (int) ($task['primary_seat_id'] ?? 0);
        if ($primary > 0 && in_array($primary, $scope['seat_ids'], true)) return true;
    }
    return false;
}

function can_move_task(int $viewerId, ?array $task = null): bool
{
    // Same rule as edit — the board is a status field on the task.
    return can_edit_task($viewerId, $task);
}

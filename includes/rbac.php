<?php
/**
 * Role-based access control for the CRM.
 *
 * Data model
 *   module              — the five product areas the CRM ships (Job
 *                         Fair Result, Project Management, Demand
 *                         Side, PMU Assets, Administration).
 *   module_role         — three ranks per module: admin > reviewer
 *                         > user.
 *   user_module_role    — direct grants (one row per user × module).
 *   role_group          — a named bundle of (module, role) pairs.
 *   role_group_grant    — the pairs inside a group.
 *   user_role_group     — which groups a user belongs to.
 *
 * A user's *effective* role on a module is the highest role that
 * comes from either a direct grant OR any group they belong to
 * (admin > reviewer > user).
 *
 * The legacy `users.role` ENUM stays in place and is not touched by
 * this layer; existing require_admin() / is_manage_admin() checks
 * keep working. The bootstrap seeds this table on first run and
 * backfills existing users into module grants based on their legacy
 * role, so both the old and new gates approve the same users on
 * day one.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

const RBAC_MODULES = [
    ['code' => 'job_fair',           'name' => 'Job Fair Result',    'sort_order' => 10],
    ['code' => 'project_management', 'name' => 'Project Management', 'sort_order' => 20],
    ['code' => 'demand_side',        'name' => 'Demand Side',        'sort_order' => 30],
    ['code' => 'pmu_assets',         'name' => 'PMU Assets',         'sort_order' => 40],
    ['code' => 'administration',     'name' => 'Administration',     'sort_order' => 50],
];

const RBAC_ROLES = [
    ['code' => 'admin',    'name' => 'Admin',    'sort_order' => 10],
    ['code' => 'reviewer', 'name' => 'Reviewer', 'sort_order' => 20],
    ['code' => 'user',     'name' => 'User',     'sort_order' => 30],
];

/**
 * Idempotent bootstrap. Creates the five RBAC tables, seeds the
 * module + role catalogue, and backfills existing users whose module
 * grants haven't been derived yet from their legacy users.role
 * value. Safe to call at the top of every page load; the static
 * $done guard means it only runs once per request.
 */
function rbac_bootstrap(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = db();

    try {
        $db->query("CREATE TABLE IF NOT EXISTS module (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL,
            name VARCHAR(120) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY unique_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS module_role (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module_id INT NOT NULL,
            code VARCHAR(20) NOT NULL,
            name VARCHAR(60) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY unique_module_code (module_id, code),
            KEY idx_module (module_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS user_module_role (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            module_id INT NOT NULL,
            module_role_id INT NOT NULL,
            created_at DATETIME NULL,
            created_by INT NULL,
            UNIQUE KEY unique_user_module (user_id, module_id),
            KEY idx_user (user_id),
            KEY idx_module (module_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS role_group (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            description TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            created_by INT NULL,
            updated_by INT NULL,
            UNIQUE KEY unique_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS role_group_grant (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_group_id INT NOT NULL,
            module_id INT NOT NULL,
            module_role_id INT NOT NULL,
            UNIQUE KEY unique_group_module (role_group_id, module_id),
            KEY idx_group (role_group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS user_role_group (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            role_group_id INT NOT NULL,
            created_at DATETIME NULL,
            created_by INT NULL,
            UNIQUE KEY unique_user_group (user_id, role_group_id),
            KEY idx_user (user_id),
            KEY idx_group (role_group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // Tables missing / DB user lacks CREATE — page can still work
        // with legacy role gates in place. Nothing else to do here.
        return;
    }

    // Seed modules.
    try {
        foreach (RBAC_MODULES as $m) {
            $db->prepare('INSERT IGNORE INTO module (code, name, sort_order, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')
               ->execute([$m['code'], $m['name'], $m['sort_order']]);
        }
    } catch (Throwable $e) { /* ignore */ }

    // Seed roles per module.
    try {
        $modules = $db->query('SELECT id FROM module')->fetchAll();
        foreach ($modules as $m) {
            foreach (RBAC_ROLES as $r) {
                $db->prepare('INSERT IGNORE INTO module_role (module_id, code, name, sort_order) VALUES (?, ?, ?, ?)')
                   ->execute([(int) $m['id'], $r['code'], $r['name'], $r['sort_order']]);
            }
        }
    } catch (Throwable $e) { /* ignore */ }

    // Backfill: give every user without any grants a set of module
    // grants derived from their legacy users.role value. Uses INSERT
    // IGNORE so re-running is a no-op.
    try {
        rbac__backfill_from_legacy_roles($db);
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Map the legacy users.role ENUM values to module grants. Anything
 * not listed keeps zero module grants — those users still see the
 * pages their legacy role opens, but the new module-gated menu
 * items won't render for them until an admin grants them
 * explicitly.
 */
function rbac__backfill_from_legacy_roles(Database $db): void
{
    $mapping = [
        'administrator' => ['job_fair' => 'admin', 'project_management' => 'admin', 'demand_side' => 'admin', 'pmu_assets' => 'admin', 'administration' => 'admin'],
        'dsm_admin'     => ['job_fair' => 'user',  'project_management' => 'admin', 'demand_side' => 'admin', 'pmu_assets' => 'admin', 'administration' => 'admin'],
        'state_dsm'     => ['job_fair' => 'user',  'demand_side' => 'admin'],
        'crm_member'    => ['job_fair' => 'user'],
        'district_user' => ['job_fair' => 'user'],
        'district_pmu'  => ['pmu_assets' => 'user'],
        'state_pmu'     => ['pmu_assets' => 'user'],
        'edms'          => ['pmu_assets' => 'reviewer'],
    ];

    $moduleId = [];
    foreach ($db->query('SELECT id, code FROM module')->fetchAll() as $m) $moduleId[(string) $m['code']] = (int) $m['id'];
    $roleId = [];
    foreach ($db->query('SELECT mr.id, mr.code, m.code AS module_code FROM module_role mr INNER JOIN module m ON m.id = mr.module_id')->fetchAll() as $r) {
        $roleId[(string) $r['module_code']][(string) $r['code']] = (int) $r['id'];
    }

    $users = $db->query('SELECT u.id, u.role FROM users u
        WHERE u.active_status = 1
          AND NOT EXISTS (SELECT 1 FROM user_module_role umr WHERE umr.user_id = u.id)')->fetchAll();
    foreach ($users as $u) {
        $legacy = (string) ($u['role'] ?? '');
        $grants = $mapping[$legacy] ?? [];
        foreach ($grants as $modCode => $roleCode) {
            $mid = $moduleId[$modCode] ?? 0;
            $rid = $roleId[$modCode][$roleCode] ?? 0;
            if ($mid > 0 && $rid > 0) {
                try {
                    $db->prepare('INSERT IGNORE INTO user_module_role (user_id, module_id, module_role_id, created_at) VALUES (?, ?, ?, NOW())')
                       ->execute([(int) $u['id'], $mid, $rid]);
                } catch (Throwable $e) { /* ignore */ }
            }
        }
    }
}

/** admin > reviewer > user > (nothing) */
function rbac_role_rank(string $roleCode): int
{
    return ['user' => 1, 'reviewer' => 2, 'admin' => 3][$roleCode] ?? 0;
}

/**
 * Effective (module_code => role_code) for a user, taking the
 * highest role between direct grants and any group memberships.
 * Cached per request.
 */
function rbac_user_module_roles(int $userId): array
{
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    if ($userId <= 0) return $cache[$userId] = [];

    $out = [];
    $absorb = static function (array &$out, string $modCode, string $roleCode): void {
        $cur = $out[$modCode] ?? null;
        if ($cur === null || rbac_role_rank($roleCode) > rbac_role_rank($cur)) $out[$modCode] = $roleCode;
    };

    try {
        // Direct.
        $stmt = db()->prepare('SELECT m.code AS module_code, mr.code AS role_code
            FROM user_module_role umr
            INNER JOIN module m       ON m.id  = umr.module_id
            INNER JOIN module_role mr ON mr.id = umr.module_role_id
            WHERE umr.user_id = ?');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $r) $absorb($out, (string) $r['module_code'], (string) $r['role_code']);

        // Group-derived (active groups only).
        $stmt = db()->prepare('SELECT m.code AS module_code, mr.code AS role_code
            FROM user_role_group urg
            INNER JOIN role_group rg        ON rg.id  = urg.role_group_id AND rg.is_active = 1
            INNER JOIN role_group_grant rgg ON rgg.role_group_id = rg.id
            INNER JOIN module m             ON m.id  = rgg.module_id
            INNER JOIN module_role mr       ON mr.id = rgg.module_role_id
            WHERE urg.user_id = ?');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $r) $absorb($out, (string) $r['module_code'], (string) $r['role_code']);
    } catch (Throwable $e) { /* tables missing — treat as empty */ }

    return $cache[$userId] = $out;
}

function user_module_role(int $userId, string $moduleCode): ?string
{
    return rbac_user_module_roles($userId)[$moduleCode] ?? null;
}

function user_can_access_module(int $userId, string $moduleCode): bool
{
    return user_module_role($userId, $moduleCode) !== null;
}

function user_can_admin_module(int $userId, string $moduleCode): bool
{
    return user_module_role($userId, $moduleCode) === 'admin';
}

function user_can_review_module(int $userId, string $moduleCode): bool
{
    $r = user_module_role($userId, $moduleCode);
    return $r === 'admin' || $r === 'reviewer';
}

/**
 * Guard for new pages. Refuses access unless the current viewer holds
 * at least $minRole on $moduleCode. Renders a full 403 page so the
 * viewer sees where they landed.
 */
function require_module_role(string $moduleCode, string $minRole = 'user'): void
{
    require_auth();
    $u = current_user() ?? [];
    $uid = (int) ($u['id'] ?? 0);
    $mine = user_module_role($uid, $moduleCode);
    if ($mine === null || rbac_role_rank($mine) < rbac_role_rank($minRole)) {
        http_response_code(403);
        require_once __DIR__ . '/layout.php';
        render_header('Access denied');
        render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
        echo '<div class="alert alert-danger">You do not have permission to open this page. Ask an administrator to grant you at least <strong>' . htmlspecialchars($minRole, ENT_QUOTES) . '</strong> access on the <strong>' . htmlspecialchars($moduleCode, ENT_QUOTES) . '</strong> module.</div>';
        render_footer();
        exit;
    }
}

/** All modules for admin UIs. */
function rbac_all_modules(): array
{
    try { return db()->query('SELECT id, code, name, sort_order FROM module WHERE is_active = 1 ORDER BY sort_order ASC, name ASC')->fetchAll(); }
    catch (Throwable $e) { return []; }
}

/** All role rows keyed by module_id for admin UIs. */
function rbac_all_module_roles(): array
{
    try {
        $out = [];
        foreach (db()->query('SELECT id, module_id, code, name, sort_order FROM module_role ORDER BY sort_order ASC')->fetchAll() as $r) {
            $out[(int) $r['module_id']][] = $r;
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

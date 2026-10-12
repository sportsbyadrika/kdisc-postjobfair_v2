<?php
/**
 * Platform Requests module — schema + shared helpers.
 *
 * Workflow:
 *   • An LOI team member raises a request against an employer, picking
 *     a reason (DWMS password reset / status change / ...) from a
 *     master, setting a priority (urgent/critical/medium/low) and
 *     writing an initial comment.
 *   • The request lands in the Platform team's inbox. They open the
 *     chat for that request, reply, and update the status
 *     (open / in_progress / resolved / closed).
 *   • Any user who can see the employer can post a chat message on
 *     any of its requests; only platform-admin can change the status.
 *     Status changes emit a system message so the thread doubles as
 *     an activity log.
 *
 * One request has its own thread (loi_request_message rows). The
 * employer's Requests tab aggregates every request for that employer.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

const LOI_REQUEST_PRIORITIES = ['urgent', 'critical', 'medium', 'low'];
const LOI_REQUEST_STATUSES   = ['open', 'in_progress', 'resolved', 'closed'];

function loi_requests_bootstrap(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = db();

    try {
        $db->query("CREATE TABLE IF NOT EXISTS loi_request_reason (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            description TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 100,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            created_by INT NULL,
            updated_by INT NULL,
            UNIQUE KEY unique_name (name),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed a few canonical reasons on a fresh install. INSERT
        // IGNORE keeps admin renames stable on re-runs.
        $seed = $db->prepare('INSERT IGNORE INTO loi_request_reason (name, description, is_active, sort_order, created_at, updated_at) VALUES (?, ?, 1, ?, NOW(), NOW())');
        $ord = 10;
        foreach ([
            ['DWMS password reset',             'Reset of the employer\'s DWMS login password.'],
            ['DWMS employer status change',     'Status update of the employer record inside DWMS.'],
            ['DWMS job update',                 'Add / update / close a job on DWMS.'],
            ['DWMS registration help',          'Help registering the employer in DWMS.'],
            ['Other',                           'Anything that doesn\'t fit the specific reasons above.'],
        ] as $row) {
            $seed->execute([$row[0], $row[1], $ord]);
            $ord += 10;
        }

        $db->query("CREATE TABLE IF NOT EXISTS loi_request (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employer_id INT NOT NULL,
            reason_id INT NULL,
            priority ENUM('urgent','critical','medium','low') NOT NULL DEFAULT 'medium',
            status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
            initial_comment TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NULL,
            closed_by INT NULL,
            closed_at DATETIME NULL,
            updated_at DATETIME NULL,
            KEY idx_employer (employer_id),
            KEY idx_reason (reason_id),
            KEY idx_status (status),
            KEY idx_priority (priority),
            KEY idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS loi_request_message (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            author_id INT NULL,
            body TEXT NOT NULL,
            kind ENUM('comment','system') NOT NULL DEFAULT 'comment',
            created_at DATETIME NOT NULL,
            KEY idx_request (request_id),
            KEY idx_author (author_id),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* CREATE refused — module reduces to no-op */ }
}

/**
 * Platform admin = user with admin role on the platform_requests
 * module OR Administrator. They can change request statuses and see
 * every request in the inbox.
 */
function loi_requests_is_platform_admin(array $viewer): bool
{
    if (is_manage_admin($viewer)) return true;
    $vid = (int) ($viewer['id'] ?? 0);
    if ($vid <= 0) return false;
    if (function_exists('user_can_admin_module') && user_can_admin_module($vid, 'platform_requests')) return true;
    return false;
}

/**
 * Can the viewer raise + chat on requests? LOI user, LOI admin, or
 * platform admin — basically anyone with access to the LOI module.
 */
function loi_requests_can_participate(int $viewerId, array $viewer): bool
{
    if (is_admin($viewer)) return true;
    if (function_exists('user_can_access_module') && user_can_access_module($viewerId, 'loi_jobs')) return true;
    if (function_exists('user_can_access_module') && user_can_access_module($viewerId, 'platform_requests')) return true;
    return false;
}

/**
 * Active reasons ordered as seeded.
 */
function loi_request_reason_list(bool $onlyActive = true): array
{
    try {
        $sql = 'SELECT id, name, description, is_active, sort_order FROM loi_request_reason';
        if ($onlyActive) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY sort_order ASC, name ASC';
        return db()->query($sql)->fetchAll();
    } catch (Throwable $e) { return []; }
}

/**
 * Post a message to a request thread. kind='system' is reserved for
 * status change announcements; comments default to 'comment'. Returns
 * the new message id or 0 on failure.
 */
function loi_request_post_message(int $requestId, int $authorId, string $body, string $kind = 'comment'): int
{
    $body = trim($body);
    if ($body === '') return 0;
    if (!in_array($kind, ['comment','system'], true)) $kind = 'comment';
    try {
        db()->prepare('INSERT INTO loi_request_message (request_id, author_id, body, kind, created_at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([$requestId, $authorId > 0 ? $authorId : null, $body, $kind]);
        db()->prepare('UPDATE loi_request SET updated_at = NOW() WHERE id = ?')->execute([$requestId]);
        return (int) db()->lastInsertId();
    } catch (Throwable $e) { return 0; }
}

function loi_request_priority_tone(string $p): string
{
    return match ($p) {
        'urgent'   => 'danger',
        'critical' => 'warning',
        'medium'   => 'info',
        default    => 'secondary',
    };
}

function loi_request_status_tone(string $s): string
{
    return match ($s) {
        'resolved'    => 'success',
        'closed'      => 'secondary',
        'in_progress' => 'primary',
        default       => 'warning',
    };
}

function loi_request_status_label(string $s): string
{
    return match ($s) {
        'in_progress' => 'In progress',
        default       => ucfirst($s),
    };
}

/**
 * Dashboard counts — open-across-everyone (platform view) + open-raised-by-me.
 */
function loi_requests_dashboard_counts(int $viewerId): array
{
    $out = ['open_total' => 0, 'open_mine' => 0];
    try {
        $out['open_total'] = (int) db()->query("SELECT COUNT(*) FROM loi_request WHERE status IN ('open','in_progress')")->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM loi_request WHERE status IN ('open','in_progress') AND created_by = ?");
        $st->execute([$viewerId]);
        $out['open_mine'] = (int) $st->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    return $out;
}

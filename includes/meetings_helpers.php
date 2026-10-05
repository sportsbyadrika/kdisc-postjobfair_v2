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

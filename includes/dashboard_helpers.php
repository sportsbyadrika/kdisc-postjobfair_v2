<?php
/**
 * Dashboard card-group visibility.
 *
 * Admins toggle which of the named card groups render on the main
 * dashboard. A single-row-per-card table means the check is O(1),
 * and unknown codes are treated as visible by default so a newly-
 * added card block shows up on every install without a manual
 * config step.
 *
 * Bootstrap seeds the initial set. Demand Side Snapshot ships hidden
 * per the operator's request; everything else defaults to visible.
 */

require_once __DIR__ . '/db.php';

/**
 * The card groups this app knows about. Order controls the display
 * order on the settings page. Codes are stable; labels are what the
 * admin sees. `description` is optional context under the toggle.
 */
function dashboard_all_cards(): array
{
    return [
        ['code' => 'demand_snapshot',   'label' => 'Demand Side Snapshot',    'description' => 'Employer / job / positions tiles pulled from the DWMS import.'],
        ['code' => 'demand_verify',     'label' => 'Demand Side · Verification Status', 'description' => 'Valid / Invalid / Corrected / Not-yet-started status cards.'],
        ['code' => 'district_pmu',      'label' => 'District PMU Snapshot',   'description' => 'Asset register + submission counters (visible to PMU + admin roles).'],
        ['code' => 'district_photos',   'label' => 'District PMU Office Photos', 'description' => 'Photo panel loaded on demand from a Show-more button.'],
        ['code' => 'jobfair_status',    'label' => 'Post Job Fair Status',    'description' => 'CRM Job Fair post-fair conversion counters.'],
        ['code' => 'meetings',          'label' => 'Meetings',                'description' => 'Meeting count card + mini calendar.'],
        ['code' => 'projects_status',   'label' => 'Projects · Status',       'description' => 'Active / Completed project counts + task risk buckets (overdue / due / safe / not started).'],
        ['code' => 'office_stats',      'label' => 'Office Hierarchy · Summary', 'description' => 'Division / Section / Sub Section / Seat counts.'],
        ['code' => 'loi_jobs',          'label' => 'LOI & Jobs',              'description' => 'Active Employers / Active Jobs / Mobilisations in progress / Interviews this week.'],
    ];
}

function dashboard_cards_bootstrap(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = db();
    try {
        $db->query("CREATE TABLE IF NOT EXISTS dashboard_card_visibility (
            id INT AUTO_INCREMENT PRIMARY KEY,
            card_code VARCHAR(64) NOT NULL,
            is_visible TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 100,
            updated_at DATETIME NULL,
            updated_by INT NULL,
            UNIQUE KEY unique_code (card_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Idempotent add for the sort_order column so an install
        // that seeded the visibility table before this feature
        // shipped picks it up on the next request without an admin
        // touching schema.
        try {
            $cols = [];
            foreach ($db->query('SHOW COLUMNS FROM dashboard_card_visibility')->fetchAll() as $c) $cols[strtolower((string) $c['Field'])] = true;
            if (!isset($cols['sort_order'])) {
                $db->query('ALTER TABLE dashboard_card_visibility ADD COLUMN sort_order INT NOT NULL DEFAULT 100 AFTER is_visible');
            }
        } catch (Throwable $e) { /* ALTER refused — falls back to default order */ }

        // Initial seed. Demand Side Snapshot ships hidden per the
        // operator's request; every other card starts visible.
        // Sort order matches dashboard_all_cards() display order so
        // a fresh install renders in the same layout as before.
        // Re-runs are no-ops via INSERT IGNORE.
        $seed = $db->prepare('INSERT IGNORE INTO dashboard_card_visibility (card_code, is_visible, sort_order, updated_at) VALUES (?, ?, ?, NOW())');
        $ord = 10;
        foreach (dashboard_all_cards() as $c) {
            $default = ($c['code'] === 'demand_snapshot') ? 0 : 1;
            $seed->execute([$c['code'], $default, $ord]);
            $ord += 10;
        }
    } catch (Throwable $e) { /* ALTER-less hosting — helper still returns true below */ }
}

/**
 * Return the card list in the configured display order. Rows are
 * decorated with is_visible + sort_order from the DB when present.
 * Cards known to dashboard_all_cards() but missing from the DB
 * (fresh code, unseeded row) land at the end so nothing silently
 * disappears — they show up and the admin can slot them into place.
 */
function dashboard_ordered_cards(): array
{
    $all = dashboard_all_cards();
    $meta = [];
    try {
        foreach (db()->query('SELECT card_code, is_visible, sort_order FROM dashboard_card_visibility')->fetchAll() as $r) {
            $meta[(string) $r['card_code']] = [
                'is_visible' => ((int) $r['is_visible']) === 1,
                'sort_order' => (int) ($r['sort_order'] ?? 100),
            ];
        }
    } catch (Throwable $e) { /* table absent — fall through */ }

    $decorated = [];
    $tail = 10000;
    foreach ($all as $c) {
        $m = $meta[$c['code']] ?? null;
        $decorated[] = [
            'code'        => $c['code'],
            'label'       => $c['label'],
            'description' => $c['description'] ?? '',
            'is_visible'  => $m['is_visible'] ?? true,
            'sort_order'  => $m['sort_order'] ?? $tail++,
        ];
    }
    usort($decorated, static function ($a, $b) {
        if ($a['sort_order'] === $b['sort_order']) return strcmp((string) $a['code'], (string) $b['code']);
        return $a['sort_order'] <=> $b['sort_order'];
    });
    return $decorated;
}

/**
 * Return true when the named card should render. Unknown codes are
 * visible by default so freshly-added cards work without a config
 * change; only rows explicitly turned off are hidden.
 */
function dashboard_card_visible(string $code): bool
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT card_code, is_visible FROM dashboard_card_visibility')->fetchAll() as $r) {
                $cache[(string) $r['card_code']] = (int) $r['is_visible'] === 1;
            }
        } catch (Throwable $e) { /* table not present yet — everything visible */ }
    }
    return $cache[$code] ?? true;
}

/**
 * Top-nav menu groups the admin can hide globally. Codes here match
 * the group's module slug so the mapping to layout.php is obvious.
 * Administration is intentionally omitted — if it were hideable, an
 * admin could lock everyone (including themselves) out of these
 * settings without a direct-URL recovery.
 */
function dashboard_all_menus(): array
{
    return [
        ['code' => 'job_fair',           'label' => 'Job Fair · CRM',       'description' => 'Candidates, assignments, phone directory, reports — the CRM module.'],
        ['code' => 'project_management', 'label' => 'Project Management',   'description' => 'Projects, My Work, Kanban, Project Status, Reports, Financial Year master.'],
        ['code' => 'meetings',           'label' => 'Meetings',             'description' => 'All meetings, New meeting, Contacts master, MoM report settings.'],
        ['code' => 'demand_side',        'label' => 'Demand Side',          'description' => 'Employers, assignments, uploads, stats, settings.'],
        ['code' => 'pmu_assets',         'label' => 'PMU Assets',           'description' => 'District profiles + asset registers + district masters.'],
        ['code' => 'loi_jobs',           'label' => 'LOI & Jobs',           'description' => 'Employers, job roles, interviews, mobilisation & reports.'],
    ];
}

function dashboard_menus_bootstrap(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = db();
        $db->query("CREATE TABLE IF NOT EXISTS dashboard_menu_visibility (
            id INT AUTO_INCREMENT PRIMARY KEY,
            menu_code VARCHAR(64) NOT NULL,
            is_visible TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NULL,
            updated_by INT NULL,
            UNIQUE KEY unique_code (menu_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed every known group as visible. Re-runs are no-ops via
        // INSERT IGNORE so an admin's later toggle doesn't get reset.
        $seed = $db->prepare('INSERT IGNORE INTO dashboard_menu_visibility (menu_code, is_visible, updated_at) VALUES (?, 1, NOW())');
        foreach (dashboard_all_menus() as $m) $seed->execute([$m['code']]);
    } catch (Throwable $e) { /* CREATE refused — helper falls back to always-visible */ }
}

/**
 * Return the menu list decorated with the current visibility flag.
 * Rows missing from the DB are treated as visible — the same safe
 * default dashboard_card_visible() uses.
 */
function dashboard_menus_decorated(): array
{
    $all = dashboard_all_menus();
    $meta = [];
    try {
        foreach (db()->query('SELECT menu_code, is_visible FROM dashboard_menu_visibility')->fetchAll() as $r) {
            $meta[(string) $r['menu_code']] = (int) $r['is_visible'] === 1;
        }
    } catch (Throwable $e) { /* table absent */ }
    $out = [];
    foreach ($all as $m) {
        $out[] = $m + ['is_visible' => $meta[$m['code']] ?? true];
    }
    return $out;
}

function dashboard_menu_visible(string $code): bool
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT menu_code, is_visible FROM dashboard_menu_visibility')->fetchAll() as $r) {
                $cache[(string) $r['menu_code']] = (int) $r['is_visible'] === 1;
            }
        } catch (Throwable $e) { /* table not present — everything visible */ }
    }
    return $cache[$code] ?? true;
}

/**
 * Total meetings visible to this viewer today or later. Wrapped in
 * try/catch so the dashboard card renders 0 (not a 500) before the
 * Meetings module ships its schema.
 */
function dashboard_meeting_count_for(int $userId): int
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM meeting WHERE is_active = 1');
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Meetings on a given YYYY-MM-DD, visible to any viewer. Placeholder
 *  return until the Meetings module lands. */
function dashboard_meetings_on(string $ymd): array
{
    try {
        $stmt = db()->prepare('SELECT id, title, from_time, to_time FROM meeting
            WHERE meeting_date = ? AND is_active = 1
            ORDER BY from_time ASC');
        $stmt->execute([$ymd]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Projects · Status summary numbers for the dashboard card.
 * Every field is 0 when the Task Tracker schema hasn't shipped yet.
 * "Due window" is a 7-day horizon from today.
 *
 *   active_projects, completed_projects
 *   overdue_tasks, due_tasks, safe_tasks, not_started_tasks
 */
function dashboard_projects_status(): array
{
    $out = [
        'active_projects'    => 0,
        'completed_projects' => 0,
        'overdue_tasks'      => 0,
        'due_tasks'          => 0,
        'safe_tasks'         => 0,
        'not_started_tasks'  => 0,
    ];
    try {
        // A project is "completed" when its end_date is in the past
        // OR every task on it is on a terminal status. We use the
        // simpler date-based rule here so a running project without
        // an explicit end_date stays Active.
        $today = date('Y-m-d');
        $out['active_projects'] = (int) db()->query("SELECT COUNT(*) FROM project
            WHERE is_active = 1 AND (end_date IS NULL OR end_date >= '$today')")->fetchColumn();
        $out['completed_projects'] = (int) db()->query("SELECT COUNT(*) FROM project
            WHERE is_active = 1 AND end_date IS NOT NULL AND end_date < '$today'")->fetchColumn();

        $dueCap = date('Y-m-d', strtotime('+7 days'));
        // Only count tasks whose status is NOT terminal.
        $out['overdue_tasks'] = (int) db()->query("SELECT COUNT(*) FROM task t
            LEFT JOIN task_status s ON s.id = t.status_id
            WHERE t.is_active = 1
              AND t.planned_end IS NOT NULL AND t.planned_end < '$today'
              AND (s.is_terminal IS NULL OR s.is_terminal = 0)")->fetchColumn();
        $out['due_tasks'] = (int) db()->query("SELECT COUNT(*) FROM task t
            LEFT JOIN task_status s ON s.id = t.status_id
            WHERE t.is_active = 1
              AND t.planned_end IS NOT NULL
              AND t.planned_end BETWEEN '$today' AND '$dueCap'
              AND (s.is_terminal IS NULL OR s.is_terminal = 0)")->fetchColumn();
        $out['safe_tasks'] = (int) db()->query("SELECT COUNT(*) FROM task t
            LEFT JOIN task_status s ON s.id = t.status_id
            WHERE t.is_active = 1
              AND t.planned_end IS NOT NULL AND t.planned_end > '$dueCap'
              AND (t.planned_start IS NULL OR t.planned_start <= '$today')
              AND (s.is_terminal IS NULL OR s.is_terminal = 0)")->fetchColumn();
        $out['not_started_tasks'] = (int) db()->query("SELECT COUNT(*) FROM task t
            LEFT JOIN task_status s ON s.id = t.status_id
            WHERE t.is_active = 1
              AND (s.is_terminal IS NULL OR s.is_terminal = 0)
              AND ( (t.planned_start IS NOT NULL AND t.planned_start > '$today')
                    OR t.planned_start IS NULL AND t.planned_end IS NULL )")->fetchColumn();
    } catch (Throwable $e) { /* task tracker not present — zeros */ }
    return $out;
}

/**
 * Office Hierarchy summary — active-only counts per level. Zeros
 * when the office_hierarchy_nodes table doesn't exist yet.
 */
function dashboard_office_hierarchy_counts(): array
{
    $out = ['division' => 0, 'section' => 0, 'sub_section' => 0, 'seat' => 0];
    try {
        foreach (db()->query("SELECT level_type, COUNT(*) AS c
            FROM office_hierarchy_nodes
            WHERE active_status = 1 AND level_type IN ('division','section','sub_section','seat')
            GROUP BY level_type")->fetchAll() as $r) {
            $out[(string) $r['level_type']] = (int) $r['c'];
        }
    } catch (Throwable $e) { /* table missing */ }
    return $out;
}

/** Days in a month that have at least one meeting; returns a map
 *  keyed by YYYY-MM-DD → count of meetings that day. */
function dashboard_meeting_days_in_month(string $ym): array
{
    try {
        $start = $ym . '-01';
        $end   = date('Y-m-t', strtotime($start));
        $stmt = db()->prepare('SELECT meeting_date, COUNT(*) AS c FROM meeting
            WHERE meeting_date BETWEEN ? AND ? AND is_active = 1
            GROUP BY meeting_date');
        $stmt->execute([$start, $end]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) $out[(string) $r['meeting_date']] = (int) $r['c'];
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

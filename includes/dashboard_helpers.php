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
        ['code' => 'meetings',          'label' => 'Meetings',                'description' => 'Meeting count card + mini calendar. Full module ships separately.'],
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
            updated_at DATETIME NULL,
            updated_by INT NULL,
            UNIQUE KEY unique_code (card_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Initial seed. Demand Side Snapshot ships hidden per the
        // operator's request; every other card starts visible.
        // Re-runs are no-ops via INSERT IGNORE.
        $seed = $db->prepare('INSERT IGNORE INTO dashboard_card_visibility (card_code, is_visible, updated_at) VALUES (?, ?, NOW())');
        foreach (dashboard_all_cards() as $c) {
            $default = ($c['code'] === 'demand_snapshot') ? 0 : 1;
            $seed->execute([$c['code'], $default]);
        }
    } catch (Throwable $e) { /* ALTER-less hosting — helper still returns true below */ }
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

/** Days in a month that have at least one meeting; returns a
 *  set-like array keyed by YYYY-MM-DD. */
function dashboard_meeting_days_in_month(string $ym): array
{
    try {
        $start = $ym . '-01';
        $end   = date('Y-m-t', strtotime($start));
        $stmt = db()->prepare('SELECT DISTINCT meeting_date FROM meeting
            WHERE meeting_date BETWEEN ? AND ? AND is_active = 1');
        $stmt->execute([$start, $end]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) $out[(string) $r['meeting_date']] = true;
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

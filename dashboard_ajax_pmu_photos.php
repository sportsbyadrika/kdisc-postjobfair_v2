<?php
/**
 * AJAX endpoint used by the admin / EDMS dashboard to lazy-load the
 * District PMU office photos that are hidden behind the "Show more"
 * button. Returns an HTML fragment (a series of column tiles) that the
 * caller inserts before the button.
 *
 * Query params:
 *   offset — skip this many rows before returning (default 0)
 *   limit  — cap on rows returned (default 200, hard max 500)
 *
 * Ordering matches the initial render on dashboard.php so a client
 * asking for offset=3 gets tiles 4..N.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/dashboard_pmu_photo_tile.php';
require_auth();
$viewer = current_user() ?? [];
if (!is_admin($viewer) && !is_edms($viewer)) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-0">Access denied.</div>';
    exit;
}

$offset = max(0, (int) ($_GET['offset'] ?? 0));
$limit  = max(1, min((int) ($_GET['limit'] ?? 200), 500));

// district_pmu_office_profile may not have been bootstrapped yet on a
// completely fresh install; falling back to an empty fragment lets the
// caller's "Show more" button quietly disable itself.
try {
    $stmt = db()->prepare("SELECT district, office_name, building_photo_path, room_photo_path, updated_at
        FROM district_pmu_office_profile
        WHERE building_photo_path IS NOT NULL OR room_photo_path IS NOT NULL
        ORDER BY district ASC
        LIMIT $limit OFFSET $offset");
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = [];
}

if ($rows === []) {
    // Empty fragment — the caller's JS treats this as "nothing more to
    // load" and removes the Show more button.
    echo '<!-- no more photos -->';
    exit;
}
foreach ($rows as $pr) {
    render_dpmu_photo_tile($pr);
}

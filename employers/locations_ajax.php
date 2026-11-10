<?php
/**
 * Public districts/states AJAX for the Employer Enquiry form.
 *
 * Reference data only — no login required (the form itself is
 * public). Cannot reuse /loi_locations_ajax.php here because that
 * endpoint calls require_auth() and would redirect to /login.php.
 *
 *   GET ?action=districts&state=<name>  → { ok, districts: [...] }
 *   GET ?action=states                  → { ok, states: [...] }
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/india_locations_helpers.php';

india_locations_bootstrap();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$action = (string) ($_GET['action'] ?? 'districts');

if ($action === 'states') {
    echo json_encode(['ok' => true, 'states' => india_states_list()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'districts') {
    $state = (string) ($_GET['state'] ?? '');
    echo json_encode([
        'ok' => true,
        'state' => $state,
        'districts' => india_districts_for_state($state),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);

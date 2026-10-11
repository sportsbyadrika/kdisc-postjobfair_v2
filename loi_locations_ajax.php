<?php
/**
 * India locations · AJAX endpoints.
 *
 *   GET ?action=districts&state=<name>  → { ok, districts: [...] }
 *   GET ?action=states                  → { ok, states: [{id,name,code,kind}] }
 *
 * Any authenticated user can read these — they're reference data.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/india_locations_helpers.php';

header('Content-Type: application/json; charset=utf-8');

require_auth();
india_locations_bootstrap();

$action = (string) ($_GET['action'] ?? 'districts');

if ($action === 'districts') {
    $state = (string) ($_GET['state'] ?? '');
    echo json_encode(['ok' => true, 'state' => $state, 'districts' => india_districts_for_state($state)], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($action === 'states') {
    echo json_encode(['ok' => true, 'states' => india_states_list()], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);

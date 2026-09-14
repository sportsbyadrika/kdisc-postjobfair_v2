<?php
/**
 * AJAX endpoint used by the Office Hierarchy officer picker modal.
 * Returns an HTML fragment (table rows) of active users matching the
 * search string. Each row carries a Select button that the calling
 * modal wires up to set a hidden officer_id input.
 *
 * Query params:
 *   q — free-text; matches name, email or mobile_number (case-insensitive)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_auth();
$viewer = current_user() ?? [];
if (!is_manage_admin($viewer)) {
    http_response_code(403);
    echo '<tr><td colspan="4" class="text-danger">Access denied.</td></tr>';
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT id, name, role, mobile_number, email FROM users WHERE active_status = 1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (name LIKE ? OR email LIKE ? OR mobile_number LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
$sql .= ' ORDER BY name ASC LIMIT 40';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$esc = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/includes/layout.php';   // role_label()

if ($rows === []) {
    echo '<tr><td colspan="4" class="text-center text-muted py-3"><i class="bi bi-inbox me-1"></i>No matching users.</td></tr>';
    exit;
}
foreach ($rows as $r) {
    $id     = (int) $r['id'];
    $name   = $esc((string) ($r['name'] ?? ''));
    $mobile = $esc((string) ($r['mobile_number'] ?? ''));
    $email  = $esc((string) ($r['email'] ?? ''));
    $role   = $esc(role_label((string) ($r['role'] ?? '')));
    echo '<tr>';
    echo '<td class="fw-semibold">' . $name . '</td>';
    echo '<td>' . $role . '</td>';
    echo '<td class="small text-muted">' . $mobile . ($email !== '' ? '<div class="small">' . $email . '</div>' : '') . '</td>';
    echo '<td class="text-end">'
       . '<button type="button" class="btn btn-sm btn-primary js-pick-officer"'
       . ' data-id="' . $id . '"'
       . ' data-name="' . $name . '">'
       . '<i class="bi bi-check2 me-1"></i>Select</button>'
       . '</td>';
    echo '</tr>';
}

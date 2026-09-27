<?php
/**
 * Contacts AJAX endpoints.
 *
 * GET   ?action=list         → { ok: true, contacts: [{id,name,institution,designation,email,mobile}...] }
 * POST  action=create        → { ok: true, contact: {...} } (CSRF checked)
 *
 * Any logged-in user can create a contact (mirrors contacts.php).
 * Deactivated contacts are not returned by list.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/meetings_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$sendError = static function (string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
};

require_auth();
$viewer = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
meetings_bootstrap();

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($action === 'list') {
    try {
        $rows = db()->query('SELECT id, name, institution, designation, email, mobile FROM contact
            WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
    } catch (Throwable $e) { $rows = []; }
    echo json_encode(['ok' => true, 'contacts' => $rows]);
    exit;
}

if ($action === 'create') {
    if (!is_post()) $sendError('POST required.', 405);
    if (!csrf_check()) $sendError('CSRF check failed.', 403);
    $name        = trim((string) ($_POST['name'] ?? ''));
    $institution = trim((string) ($_POST['institution'] ?? ''));
    $designation = trim((string) ($_POST['designation'] ?? ''));
    $email       = trim((string) ($_POST['email'] ?? ''));
    $mobile      = trim((string) ($_POST['mobile'] ?? ''));
    $address     = trim((string) ($_POST['address'] ?? ''));
    $notes       = trim((string) ($_POST['notes'] ?? ''));
    if ($name === '') $sendError('Name is required.');
    try {
        db()->prepare('INSERT INTO contact
            (name, institution, designation, email, mobile, address, notes, is_active,
             created_at, updated_at, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), ?, ?)')
            ->execute([
                $name,
                $institution === '' ? null : $institution,
                $designation === '' ? null : $designation,
                $email === '' ? null : $email,
                $mobile === '' ? null : $mobile,
                $address === '' ? null : $address,
                $notes === '' ? null : $notes,
                $viewerId, $viewerId,
            ]);
        $newId = db()->lastInsertId();
        $st = db()->prepare('SELECT id, name, institution, designation, email, mobile FROM contact WHERE id = ?');
        $st->execute([$newId]);
        echo json_encode(['ok' => true, 'contact' => $st->fetch()]);
        exit;
    } catch (Throwable $e) {
        $sendError('Save failed: ' . $e->getMessage(), 500);
    }
}

$sendError('Unknown action.', 400);

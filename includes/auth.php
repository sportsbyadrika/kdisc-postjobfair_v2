<?php
require_once __DIR__ . '/db.php';

date_default_timezone_set('Asia/Kolkata');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_auth(): void
{
    if (!current_user()) {
        header('Location: /index.php');
        exit;
    }
}

function require_admin(): void
{
    require_auth();
    if (!is_admin(current_user())) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
}


function is_admin(array $user): bool
{
    // Broad "admin group" — anyone with elevated access to Demand Side +
    // Statistics. Administrator, State DSM and DSM Admin all qualify.
    return in_array($user['role'] ?? '', ['administrator', 'state_dsm', 'dsm_admin'], true);
}

function is_state_dsm(array $user): bool
{
    return ($user['role'] ?? '') === 'state_dsm';
}

function is_dsm_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'dsm_admin';
}

/**
 * User is administrator OR dsm_admin — can manage users, assign employers,
 * see Administration. Narrower than is_admin (excludes State DSM).
 */
function is_manage_admin(array $user): bool
{
    return in_array($user['role'] ?? '', ['administrator', 'dsm_admin'], true);
}

function is_district_user(array $user): bool
{
    return ($user['role'] ?? '') === 'district_user';
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function esc($value): string
{
    if ($value === null) {
        return '';
    }

    if (is_array($value)) {
        return '';
    }

    if (is_object($value)) {
        $value = method_exists($value, '__toString') ? (string) $value : '';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function login_user(string $mobile, string $password): ?string
{
    $stmt = db()->prepare('SELECT * FROM users WHERE mobile_number = ? AND active_status = 1 LIMIT 1');
    $stmt->execute([$mobile]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return 'Invalid credentials';
    }

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'mobile_number' => $user['mobile_number'],
        'role' => $user['role'],
        'email' => $user['email'],
    ];

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $logStmt = db()->prepare('INSERT INTO login_logs (user_id, login_at, ip_address, created_at, updated_at, modified_by) VALUES (?, NOW(), ?, NOW(), NOW(), ?)');
    $logStmt->execute([$user['id'], $ip, $user['id']]);
    $_SESSION['login_log_id'] = (int) db()->lastInsertId();

    return null;
}

function logout_user(): void
{
    if (!empty($_SESSION['login_log_id'])) {
        $stmt = db()->prepare('UPDATE login_logs SET logout_at = NOW(), updated_at = NOW(), modified_by = ? WHERE id = ?');
        $uid = current_user()['id'] ?? null;
        $stmt->execute([$uid, $_SESSION['login_log_id']]);
    }
    $_SESSION = [];
    session_destroy();
}

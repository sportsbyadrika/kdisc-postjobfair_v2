<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$user = current_user();
$flash = null;
$error = null;

if (is_post()) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'All fields are required.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirm password do not match.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters long.';
    } elseif ($currentPassword === $newPassword) {
        $error = 'New password must be different from the existing password.';
    } else {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? AND active_status = 1 LIMIT 1');
        $stmt->execute([$user['id']]);
        $dbUser = $stmt->fetch();

        if (!$dbUser || !password_verify($currentPassword, $dbUser['password_hash'])) {
            $error = 'Existing password is incorrect.';
        } else {
            $updateStmt = db()->prepare('UPDATE users SET password_hash = ?, updated_at = NOW(), modified_by = ? WHERE id = ?');
            $updateStmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id'], $user['id']]);
            $flash = 'Password changed successfully.';
        }
    }
}

render_header('Change Password');
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-5">
        <h1 class="h3 mb-3">Change Password</h1>

        <?php if ($flash): ?>
            <div class="alert alert-success"><?= esc($flash) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= esc($error) ?></div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Existing Password</label>
                        <input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="new_password">New Password</label>
                        <input class="form-control" type="password" id="new_password" name="new_password" required autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="confirm_password">Confirm New Password</label>
                        <input class="form-control" type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Update Password</button>
                        <a href="/dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php render_footer(); ?>

<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

if (current_user()) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;
if (is_post()) {
    $mobile = trim($_POST['mobile_number'] ?? '');
    $password = $_POST['password'] ?? '';
    $error = login_user($mobile, $password);
    if (!$error) {
        header('Location: /dashboard.php');
        exit;
    }
}

render_header('Login', [
    'show_navigation' => false,
    'main_container_class' => 'container-fluid login-page-container',
]);
?>
<div class="row justify-content-center align-items-center min-vh-100 py-4">
    <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 col-xxl-4">
        <div class="login-modal card border-0 shadow-lg">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <p class="login-kicker mb-2">KDISC Internal Portal</p>
                    <h1 class="h4 mb-2">Sign in to continue</h1>
                    <p class="text-muted mb-0 small">Job Fair Status Tracker</p>
                </div>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= esc($error) ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Mobile Number</label>
                        <input class="form-control form-control-lg" name="mobile_number" inputmode="numeric" autocomplete="username" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Password</label>
                        <input class="form-control form-control-lg" type="password" name="password" autocomplete="current-password" required>
                    </div>
                    <button class="btn btn-primary btn-lg w-100" type="submit">Login</button>
                </form>
                <div class="alert alert-warning d-flex align-items-start gap-2 mt-4 mb-0 small login-disclaimer" role="alert">
                    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                    <span>
                        <strong>Disclaimer:</strong> This is not production software. It is intended only for managing internal datasets.
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>
<?php render_footer(false); ?>

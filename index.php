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
        <div class="login-card card">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <span class="login-brand-mark mb-3"><i class="bi bi-briefcase-fill"></i></span>
                    <p class="login-kicker mb-1">KDISC Internal Portal</p>
                    <h1 class="h4 mb-1">Job Fair CRM</h1>
                    <p class="text-muted mb-0 small">Sign in to manage post job fair tracking</p>
                </div>
                <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2">
                        <i class="bi bi-exclamation-circle-fill"></i>
                        <span><?= esc($error) ?></span>
                    </div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Mobile Number</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                            <input class="form-control form-control-lg" name="mobile_number" inputmode="numeric" autocomplete="username" placeholder="Enter your mobile number" required>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input class="form-control form-control-lg" type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                        </div>
                    </div>
                    <button class="btn btn-primary btn-lg w-100" type="submit">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                    </button>
                </form>
                <div class="alert alert-warning d-flex align-items-start gap-2 mt-4 mb-0 small login-disclaimer" role="alert">
                    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                    <span>
                        <strong>Disclaimer:</strong> This is not production software. It is intended only for managing internal datasets.
                    </span>
                </div>
            </div>
        </div>
        <p class="text-center text-muted small mt-3 mb-0">&copy; <?= date('Y') ?> KDISC · Job Fair CRM</p>
    </div>
</div>
<?php render_footer(false); ?>

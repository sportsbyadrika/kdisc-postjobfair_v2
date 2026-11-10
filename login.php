<?php
/**
 * K-DISC MIS · Sign-in page.
 *
 * Self-contained so it shares the public home's design system
 * (deep navy + teal-to-sky gradient, Poppins display, Caveat
 * script). Loading the shared app shell would give it the
 * authenticated nav and a different look — the sign-in page is
 * public, like the home, and should feel like it.
 *
 * Flow:
 *   - Already signed in → straight to the dashboard.
 *   - POST with credentials → log in, go to the dashboard.
 *   - POST failing → re-render with the error above the form.
 */
require_once __DIR__ . '/includes/auth.php';

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

$esc = static fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In · K-DISC MIS</title>
    <meta name="description" content="Sign in to K-DISC MIS — the internal portal for the Kerala Development &amp; Innovation Strategic Council.">
    <meta name="robots" content="noindex,nofollow">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700;800&family=Caveat:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --c2c-navy:  #0d3778;
            --c2c-deep:  #072b46;
            --c2c-ink:   #0e3e66;
            --c2c-teal:  #0f9a8a;
            --c2c-teal2: #17ab9f;
            --c2c-sky:   #1d7fb8;
            --c2c-aqua:  #4fdcd2;
            --c2c-mint:  #e6f6f4;
            --c2c-wash:  #eef9f8;
            --c2c-slate: #475569;
        }
        html, body { height: 100%; }
        body {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            color: var(--c2c-ink);
            background: #ffffff;
        }
        .font-display { font-family: 'Poppins', 'Inter', sans-serif; letter-spacing: -0.01em; }
        .font-script  { font-family: 'Caveat', cursive; }

        /* ---------- Top strip (shared with home page tone) ---------- */
        .top-strip {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.75rem 0;
        }
        .top-strip .brand {
            display: inline-flex; align-items: center; gap: 0.75rem;
            color: var(--c2c-navy); text-decoration: none;
        }
        .top-strip .brand-mark {
            width: 40px; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700;
            background: linear-gradient(135deg, var(--c2c-teal) 0%, var(--c2c-sky) 100%);
            box-shadow: 0 6px 18px rgba(13, 55, 120, 0.18);
        }
        .top-strip .brand-text strong { font-family: 'Poppins'; font-weight: 700; font-size: 1rem; }
        .top-strip .brand-text small  { color: var(--c2c-slate); font-size: 0.78rem; }

        /* ---------- Split layout: branding panel + sign-in card ---------- */
        .auth-shell {
            min-height: calc(100vh - 160px);
            display: grid;
            grid-template-columns: 1fr;
            gap: 0;
            background:
                radial-gradient(ellipse at 10% 20%, #d9f3f1 0%, transparent 55%),
                radial-gradient(ellipse at 90% 80%, #e2f0fb 0%, transparent 55%),
                #ffffff;
        }
        @media (min-width: 992px) {
            .auth-shell { grid-template-columns: 1.1fr 1fr; }
        }

        .auth-brand {
            position: relative;
            padding: 3rem 2.5rem;
            color: #fff;
            background: linear-gradient(135deg, var(--c2c-navy) 0%, var(--c2c-deep) 55%, #0b4a6b 100%);
            overflow: hidden;
            display: flex; flex-direction: column; justify-content: center;
        }
        .auth-brand::before {
            content: '';
            position: absolute; inset: 0;
            background:
                radial-gradient(circle at 80% 20%, rgba(79, 220, 210, 0.22) 0%, transparent 55%),
                radial-gradient(circle at 20% 90%, rgba(29, 127, 184, 0.28) 0%, transparent 55%);
            pointer-events: none;
        }
        .auth-brand > * { position: relative; z-index: 1; }
        .auth-brand .script { font-family: 'Caveat', cursive; color: var(--c2c-aqua); font-size: 2rem; line-height: 1; margin-bottom: 0.5rem; }
        .auth-brand h1 {
            font-family: 'Poppins', sans-serif;
            font-weight: 800;
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1.02;
            margin-bottom: 1rem;
            letter-spacing: -0.015em;
        }
        .auth-brand h1 .grad {
            background: linear-gradient(90deg, var(--c2c-aqua) 0%, #a7f4ec 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            display: block;
        }
        .auth-brand .lead {
            color: rgba(255, 255, 255, 0.82); max-width: 32rem; font-size: 1.02rem;
            margin-bottom: 2rem;
        }
        .auth-brand .pills { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1.75rem; }
        .auth-brand .pill {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
            padding: 0.4rem 0.9rem; border-radius: 999px;
            font-size: 0.8rem; color: rgba(255, 255, 255, 0.92);
            display: inline-flex; align-items: center; gap: 0.4rem;
        }
        .auth-brand .pill i { color: var(--c2c-aqua); }
        .auth-brand .trust {
            padding-top: 1.25rem;
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            color: rgba(255, 255, 255, 0.72); font-size: 0.85rem;
        }

        /* ---------- Sign-in card ---------- */
        .auth-card-wrap {
            display: flex; align-items: center; justify-content: center;
            padding: 2.5rem 1.25rem;
        }
        .auth-card {
            width: 100%;
            max-width: 440px;
            background: #fff;
            border: 1px solid #e6eef6;
            border-radius: 22px;
            box-shadow: 0 30px 70px rgba(13, 55, 120, 0.12);
            padding: 2.25rem;
        }
        .auth-card .eyebrow {
            display: inline-flex; align-items: center; gap: 0.4rem;
            background: rgba(15, 154, 138, 0.1);
            color: var(--c2c-teal);
            padding: 0.3rem 0.85rem;
            border-radius: 999px;
            font-size: 0.72rem; font-weight: 600;
            letter-spacing: 0.1em; text-transform: uppercase;
            margin-bottom: 1rem;
        }
        .auth-card h2 {
            font-family: 'Poppins', sans-serif; font-weight: 700;
            color: var(--c2c-navy); font-size: 1.6rem; margin-bottom: 0.25rem;
        }
        .auth-card .sub { color: var(--c2c-slate); font-size: 0.92rem; margin-bottom: 1.5rem; }

        .form-label { font-weight: 500; color: var(--c2c-ink); font-size: 0.9rem; }
        .auth-card .input-group-text {
            background: var(--c2c-wash); color: var(--c2c-teal);
            border: 1px solid #d7ebe9; border-right: 0;
        }
        .auth-card .form-control {
            border: 1px solid #d7ebe9;
            border-left: 0;
            padding-top: 0.75rem; padding-bottom: 0.75rem;
        }
        .auth-card .form-control:focus,
        .auth-card .input-group:focus-within .input-group-text {
            border-color: var(--c2c-sky);
            box-shadow: 0 0 0 0.2rem rgba(29, 127, 184, 0.12);
        }
        .toggle-pwd {
            background: var(--c2c-wash); color: var(--c2c-slate);
            border: 1px solid #d7ebe9; border-left: 0;
            cursor: pointer;
        }

        .btn-c2c {
            background: linear-gradient(135deg, var(--c2c-navy) 0%, var(--c2c-sky) 100%);
            color: #fff; border: 0;
            padding: 0.85rem 1.25rem;
            border-radius: 999px;
            font-weight: 600;
            box-shadow: 0 14px 28px rgba(13, 55, 120, 0.22);
            width: 100%;
            font-size: 1rem;
            transition: filter 0.15s ease, transform 0.15s ease;
        }
        .btn-c2c:hover, .btn-c2c:focus { color: #fff; filter: brightness(1.05); }
        .btn-c2c:active { transform: translateY(1px); }

        .disclaimer {
            background: #fff8e8;
            border: 1px solid #f6dca8;
            border-radius: 12px;
            padding: 0.75rem 0.9rem;
            color: #6b4e00;
            font-size: 0.82rem;
            display: flex; gap: 0.5rem; align-items: flex-start;
        }
        .disclaimer i { color: #c48900; margin-top: 2px; }

        .footer-strip {
            background: #fff; border-top: 1px solid #e2e8f0;
            padding: 1.25rem 0; color: var(--c2c-slate); font-size: 0.85rem;
        }
        .footer-strip a { color: var(--c2c-navy); text-decoration: none; }
        .footer-strip a:hover { text-decoration: underline; }

        @media (max-width: 575.98px) {
            .auth-card { padding: 1.5rem; border-radius: 16px; }
            .auth-brand { padding: 2.25rem 1.5rem; }
        }
    </style>
</head>
<body>

<div class="top-strip">
    <div class="container d-flex align-items-center justify-content-between flex-wrap gap-2">
        <a class="brand" href="/">
            <span class="brand-mark">K</span>
            <span class="brand-text">
                <strong>K-DISC MIS</strong><br>
                <small>Kerala Development &amp; Innovation Strategic Council</small>
            </span>
        </a>
        <a class="small text-decoration-none" href="/"><i class="bi bi-arrow-left me-1"></i>Back to home</a>
    </div>
</div>

<main class="auth-shell">
    <aside class="auth-brand d-none d-lg-flex">
        <div class="script">Welcome back</div>
        <h1 class="font-display">
            Sign in to
            <span class="grad">K-DISC MIS</span>
        </h1>
        <p class="lead">
            The internal portal for the Kerala Development &amp; Innovation
            Strategic Council — meetings, projects, letters of intent,
            interviews, and onboarding, all in one place.
        </p>
        <div class="pills">
            <span class="pill"><i class="bi bi-calendar-check"></i>Meetings &amp; MoM</span>
            <span class="pill"><i class="bi bi-kanban"></i>Projects</span>
            <span class="pill"><i class="bi bi-file-earmark-text"></i>Letters of Intent</span>
            <span class="pill"><i class="bi bi-diagram-3"></i>Office Hierarchy</span>
            <span class="pill"><i class="bi bi-graph-up"></i>Reports</span>
        </div>
        <div class="trust">
            <i class="bi bi-shield-check me-1"></i>Secure · role-based access · audit trail on every change
        </div>
    </aside>

    <section class="auth-card-wrap">
        <div class="auth-card">
            <span class="eyebrow"><i class="bi bi-shield-lock-fill"></i>KDISC Internal Portal</span>
            <h2>Sign in</h2>
            <p class="sub">Use your registered mobile number and password.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-start gap-2 small mb-3">
                    <i class="bi bi-exclamation-circle-fill mt-1"></i>
                    <span><?= $esc($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" novalidate>
                <div class="mb-3">
                    <label class="form-label">Mobile Number</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                        <input class="form-control" name="mobile_number" inputmode="numeric" autocomplete="username" placeholder="Enter your mobile number" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input class="form-control" type="password" name="password" id="pwd" autocomplete="current-password" placeholder="Enter your password" required>
                        <button type="button" class="toggle-pwd px-3" id="togglePwd" aria-label="Show password">
                            <i class="bi bi-eye" id="togglePwdIcon"></i>
                        </button>
                    </div>
                </div>

                <button class="btn-c2c" type="submit">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
                </button>
            </form>

            <div class="text-center my-3 small">
                <span class="text-muted">Looking to partner with KKEM?</span>
                <a class="text-decoration-none" href="/employers/">Enrol your company <i class="bi bi-arrow-right-short"></i></a>
            </div>

            <div class="disclaimer">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><strong>Disclaimer:</strong> This is not production software. It is intended only for managing internal datasets.</span>
            </div>
        </div>
    </section>
</main>

<footer class="footer-strip">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>&copy; <?= (int) date('Y') ?> Kerala Development &amp; Innovation Strategic Council (KDISC)</div>
        <div>
            <a href="/">Home</a>
            <span class="mx-2">·</span>
            <a href="/employers/">For Employers</a>
        </div>
    </div>
</footer>

<script>
(function () {
    const pwd  = document.getElementById('pwd');
    const btn  = document.getElementById('togglePwd');
    const icon = document.getElementById('togglePwdIcon');
    btn.addEventListener('click', () => {
        const show = pwd.type === 'password';
        pwd.type = show ? 'text' : 'password';
        icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
})();
</script>

</body>
</html>

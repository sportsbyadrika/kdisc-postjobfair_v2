<?php
/**
 * Public landing page for K-DISC MIS.
 *
 * - Unauthenticated users see this (no login required).
 * - Already-signed-in users are sent straight to the dashboard.
 * - The "Sign In" button goes to /login.php.
 * - "For Employers" CTA goes to /employers/ (the public Enquiry form,
 *   delivered in Phase 2 of the build — the link will 404 until then).
 *
 * The visual language mirrors the Campus-to-Corporate home page so
 * KDISC products feel like one family: deep-blue + teal gradient,
 * Poppins display font, Caveat script tagline.
 *
 * This page is deliberately self-contained — the whole look lives in
 * one <style> block at the top so the landing can be previewed
 * without touching the shared app.css.
 */
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    header('Location: /dashboard.php');
    exit;
}

$year = date('Y');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>K-DISC MIS · Kerala Development &amp; Innovation Strategic Council</title>
    <meta name="description" content="K-DISC MIS — the internal management portal for the Kerala Development &amp; Innovation Strategic Council. Meetings, projects, LOI tracking, and more.">
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
            --c2c-sand:  #f6fafb;
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

        /* ---------- Top navigation ---------- */
        .home-nav {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.75rem 0;
        }
        .home-nav .brand {
            display: inline-flex; align-items: center; gap: 0.75rem;
            color: var(--c2c-navy); text-decoration: none;
        }
        .home-nav .brand-mark {
            width: 44px; height: 44px; border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; font-size: 1rem;
            background: linear-gradient(135deg, var(--c2c-teal) 0%, var(--c2c-sky) 100%);
            box-shadow: 0 6px 18px rgba(13, 55, 120, 0.18);
        }
        .home-nav .brand-text { line-height: 1.1; }
        .home-nav .brand-text strong { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 1.05rem; }
        .home-nav .brand-text small { color: var(--c2c-slate); font-size: 0.78rem; }
        .home-nav .nav-link {
            color: var(--c2c-ink); font-weight: 500; padding: 0.4rem 0.9rem;
        }
        .home-nav .nav-link:hover { color: var(--c2c-sky); }
        .btn-c2c {
            background: linear-gradient(135deg, var(--c2c-navy) 0%, var(--c2c-sky) 100%);
            color: #fff; border: 0;
            padding: 0.55rem 1.3rem;
            border-radius: 999px;
            font-weight: 600;
            box-shadow: 0 10px 22px rgba(13, 55, 120, 0.22);
        }
        .btn-c2c:hover, .btn-c2c:focus { color: #fff; filter: brightness(1.05); }
        .btn-c2c-outline {
            background: #fff; color: var(--c2c-navy);
            border: 1.5px solid var(--c2c-navy);
            padding: 0.5rem 1.2rem; border-radius: 999px; font-weight: 600;
        }
        .btn-c2c-outline:hover { background: var(--c2c-navy); color: #fff; }

        /* ---------- Hero ---------- */
        .hero {
            position: relative;
            padding: 3.5rem 0 4rem;
            background:
                radial-gradient(ellipse at 78% 35%, #d9f3f1 0%, #eef8fb 35%, #ffffff 70%);
            overflow: hidden;
        }
        .hero h1 {
            font-family: 'Poppins', sans-serif;
            font-weight: 800;
            font-size: clamp(2.4rem, 5vw, 3.6rem);
            line-height: 1.02;
            letter-spacing: -0.015em;
            margin-bottom: 0.75rem;
        }
        .hero h1 .ink  { color: var(--c2c-navy); display: block; }
        .hero h1 .grad {
            background: linear-gradient(90deg, var(--c2c-teal) 0%, var(--c2c-teal2) 45%, var(--c2c-sky) 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            display: block;
        }
        .hero .kicker {
            display: inline-flex; align-items: center; gap: 0.5rem;
            background: rgba(15, 154, 138, 0.1);
            color: var(--c2c-teal);
            padding: 0.3rem 0.85rem;
            border-radius: 999px;
            font-size: 0.78rem; font-weight: 600;
            letter-spacing: 0.08em; text-transform: uppercase;
            margin-bottom: 1.1rem;
        }
        .hero .lead {
            color: var(--c2c-slate); font-size: 1.08rem;
            max-width: 42rem; margin: 0 0 1.75rem;
        }
        .hero .tagline {
            color: var(--c2c-teal);
            font-family: 'Caveat', cursive;
            font-size: 1.9rem; font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .hero-stat {
            display: flex; align-items: center; gap: 1rem;
            padding: 1.1rem 1.25rem;
            border-radius: 16px;
            background: #fff;
            border: 1px solid #e6eef6;
            box-shadow: 0 18px 38px rgba(13, 55, 120, 0.08);
        }
        .hero-stat .stat-icon {
            width: 44px; height: 44px; border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, var(--c2c-teal) 0%, var(--c2c-sky) 100%);
            color: #fff; font-size: 1.1rem;
        }
        .hero-stat .stat-text strong { display: block; color: var(--c2c-navy); font-weight: 700; font-size: 1.05rem; line-height: 1.1; }
        .hero-stat .stat-text span { color: var(--c2c-slate); font-size: 0.85rem; }

        .hero-cta-card {
            background: #fff;
            border: 1px solid #e6eef6;
            border-radius: 20px;
            padding: 1.75rem;
            box-shadow: 0 24px 60px rgba(13, 55, 120, 0.1);
        }
        .hero-cta-card .script {
            font-family: 'Caveat', cursive; color: var(--c2c-teal);
            font-size: 1.6rem; line-height: 1; margin-bottom: 0.25rem;
        }
        .hero-cta-card h2 {
            font-family: 'Poppins', sans-serif; font-weight: 700;
            color: var(--c2c-navy); font-size: 1.5rem; margin-bottom: 0.5rem;
        }
        .hero-cta-card .btn-c2c { width: 100%; padding: 0.75rem 1rem; font-size: 1rem; }
        .hero-cta-card .caption { color: var(--c2c-slate); font-size: 0.82rem; margin-top: 0.75rem; }
        /* Sister-platform variant: same shape, different weight — a
           sharper aqua script eyebrow + a deeper-navy filled button. */
        .hero-cta-card.c2c-card {
            background: linear-gradient(135deg, #fbfdff 0%, var(--c2c-mint) 100%);
            border: 1px solid #d7ebe9;
        }
        .hero-cta-card.c2c-card .script-aqua { color: var(--c2c-sky); }
        .btn-c2c-navy {
            display: inline-block; width: 100%; text-align: center;
            background: var(--c2c-deep); color: #fff; border: 0;
            padding: 0.75rem 1rem; border-radius: 999px;
            font-weight: 600; font-size: 1rem;
            box-shadow: 0 10px 22px rgba(13, 55, 120, 0.22);
            text-decoration: none;
        }
        .btn-c2c-navy:hover, .btn-c2c-navy:focus { color: #fff; filter: brightness(1.08); }

        /* ---------- Feature grid ---------- */
        .features {
            padding: 4rem 0 3rem;
            background: #fff;
        }
        .section-head {
            margin-bottom: 2.5rem;
        }
        .section-head .eyebrow {
            display: inline-block;
            color: var(--c2c-teal);
            font-weight: 600; font-size: 0.78rem;
            letter-spacing: 0.14em; text-transform: uppercase;
            margin-bottom: 0.5rem;
        }
        .section-head h2 {
            font-family: 'Poppins', sans-serif; font-weight: 700;
            font-size: clamp(1.6rem, 3vw, 2.1rem);
            color: var(--c2c-navy);
            margin-bottom: 0.5rem;
        }
        .section-head p { color: var(--c2c-slate); max-width: 40rem; }
        .feature-card {
            background: #fff; border: 1px solid #e6eef6; border-radius: 16px;
            padding: 1.5rem; height: 100%;
            box-shadow: 0 10px 28px rgba(13, 55, 120, 0.06);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .feature-card:hover { transform: translateY(-3px); box-shadow: 0 18px 38px rgba(13, 55, 120, 0.1); }
        .feature-card .ficon {
            width: 48px; height: 48px; border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.25rem;
            background: linear-gradient(135deg, var(--c2c-teal) 0%, var(--c2c-sky) 100%);
            margin-bottom: 0.9rem;
        }
        .feature-card h3 {
            font-family: 'Poppins', sans-serif; font-weight: 600;
            color: var(--c2c-navy); font-size: 1.1rem; margin-bottom: 0.4rem;
        }
        .feature-card p { color: var(--c2c-slate); font-size: 0.92rem; margin-bottom: 0; }

        /* ---------- Employer banner ---------- */
        .emp-band {
            padding: 3rem 0;
            background:
                linear-gradient(135deg, var(--c2c-navy) 0%, var(--c2c-deep) 100%);
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        .emp-band::before {
            content: '';
            position: absolute; inset: 0;
            background: radial-gradient(circle at 85% 30%, rgba(79, 220, 210, 0.18) 0%, transparent 50%);
            pointer-events: none;
        }
        .emp-band .script { font-family: 'Caveat', cursive; color: var(--c2c-aqua); font-size: 1.6rem; line-height: 1; }
        .emp-band h2 {
            font-family: 'Poppins', sans-serif; font-weight: 700;
            font-size: clamp(1.5rem, 3vw, 2rem); margin-bottom: 0.5rem;
        }
        .emp-band p { color: rgba(255, 255, 255, 0.85); max-width: 42rem; margin-bottom: 1.5rem; }
        .emp-band .btn-light-hero {
            background: #fff; color: var(--c2c-navy); font-weight: 600;
            padding: 0.75rem 1.5rem; border-radius: 999px;
            border: 2px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.22);
        }
        .emp-band .btn-light-hero:hover { background: var(--c2c-aqua); color: var(--c2c-deep); }

        /* ---------- Footer ---------- */
        .home-footer {
            background: #fff;
            border-top: 1px solid #e2e8f0;
            padding: 1.5rem 0;
            color: var(--c2c-slate);
            font-size: 0.85rem;
        }
        .home-footer a { color: var(--c2c-navy); text-decoration: none; }
        .home-footer a:hover { text-decoration: underline; }

        @media (max-width: 575.98px) {
            .hero { padding: 2.25rem 0 2.5rem; }
            .hero .lead { font-size: 1rem; }
            .hero-cta-card { padding: 1.25rem; }
        }
    </style>
</head>
<body>

<nav class="home-nav">
    <div class="container d-flex align-items-center justify-content-between flex-wrap gap-2">
        <a class="brand" href="/index.php">
            <span class="brand-mark">K</span>
            <span class="brand-text">
                <strong>K-DISC MIS</strong><br>
                <small>Kerala Development &amp; Innovation Strategic Council</small>
            </span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <a class="nav-link d-none d-md-inline" href="#features">Features</a>
            <a class="nav-link d-none d-md-inline" href="#employers">For Employers</a>
            <a class="btn-c2c-outline" href="/employers/"><i class="bi bi-building me-1"></i>Enroll your company</a>
            <a class="btn-c2c" href="/login.php"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In</a>
        </div>
    </div>
</nav>

<section class="hero">
    <div class="container">
        <div class="row align-items-start g-4 g-lg-5">
            <div class="col-lg-7">
                <span class="kicker"><i class="bi bi-stars"></i> KDISC · Government of Kerala</span>
                <h1 class="font-display">
                    <span class="ink">Management</span>
                    <span class="grad">Information System</span>
                </h1>
                <p class="lead">
                    The internal portal that keeps KDISC programmes running — meetings,
                    projects, letters of intent, interviews and onboarding, all in one
                    place. Secure, auditable, and built around how KDISC teams
                    actually work.
                </p>
                <div class="d-flex flex-wrap gap-3 mb-4">
                    <a class="btn-c2c" href="/login.php"><i class="bi bi-box-arrow-in-right me-1"></i> Sign in to MIS</a>
                    <a class="btn-c2c-outline" href="#features">Learn more <i class="bi bi-arrow-right ms-1"></i></a>
                </div>

                <div class="row g-3 mt-2">
                    <div class="col-sm-6">
                        <div class="hero-stat">
                            <span class="stat-icon"><i class="bi bi-people-fill"></i></span>
                            <div class="stat-text">
                                <strong>DWMS</strong>
                                <span>Digital Workforce Management System</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="hero-stat">
                            <span class="stat-icon"><i class="bi bi-briefcase-fill"></i></span>
                            <div class="stat-text">
                                <strong>KKEM</strong>
                                <span>Kerala Knowledge Economy Mission</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="hero-cta-card mb-3">
                    <div class="script">Partner with us</div>
                    <h2>For Employers</h2>
                    <p class="text-secondary mb-3">
                        Looking to hire from Kerala's talent pool, onboard your company
                        on DWMS, or offer internships? Share your details — the KKEM
                        team will reach out.
                    </p>
                    <a class="btn-c2c" href="/employers/">
                        <i class="bi bi-building me-1"></i> Enroll your company
                    </a>
                    <div class="caption"><i class="bi bi-info-circle me-1"></i>No login required · takes under 3 minutes</div>
                </div>

                <!-- Sister platform for higher-education institutions. Links
                     off-site, so target=_blank + rel for the usual security
                     reasons. Distinct accent (navy filled tile + aqua button)
                     so a visitor reads the two cards as siblings, not twins. -->
                <div class="hero-cta-card c2c-card">
                    <div class="script script-aqua">Our sister platform</div>
                    <h2>Campus to Corporate</h2>
                    <p class="text-secondary mb-3">
                        Higher-education institutions in Kerala can enrol on KDISC's
                        Campus-to-Corporate platform to place students with
                        industry partners through structured, trackable programmes.
                    </p>
                    <a class="btn-c2c-navy" href="https://campustocorporate.kdisc.in" target="_blank" rel="noopener">
                        <i class="bi bi-mortarboard me-1"></i> Visit Campus to Corporate <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                    </a>
                    <div class="caption"><i class="bi bi-arrow-up-right-square me-1"></i>Opens in a new tab · higher-ed institutions only</div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="features" id="features">
    <div class="container">
        <div class="section-head text-center mx-auto">
            <span class="eyebrow">What's inside</span>
            <h2>Everything KDISC programmes need, in one place</h2>
            <p class="mx-auto">
                MIS brings together the day-to-day tools KDISC teams use — from
                planning meetings to tracking letters of intent to running
                district-level job fairs.
            </p>
        </div>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <span class="ficon"><i class="bi bi-calendar-check"></i></span>
                    <h3>Meetings &amp; MoM</h3>
                    <p>Schedule meetings, capture agenda items, log decisions, and keep an audit trail of who owned what.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <span class="ficon"><i class="bi bi-kanban"></i></span>
                    <h3>Projects &amp; Tasks</h3>
                    <p>Kanban boards and task trackers with office-hierarchy-aware assignments, history, and progress rollups.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <span class="ficon"><i class="bi bi-file-earmark-text"></i></span>
                    <h3>Letters of Intent</h3>
                    <p>Track LOIs, employers, job openings, interview schedules, and candidate onboarding in a single thread.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <span class="ficon"><i class="bi bi-diagram-3"></i></span>
                    <h3>Office Hierarchy</h3>
                    <p>Model offices, divisions, sections and seats — and transfer officers between seats with full history.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <span class="ficon"><i class="bi bi-graph-up"></i></span>
                    <h3>Reports &amp; Analytics</h3>
                    <p>District, state, and consolidated reports with filters and XLSX export — built for weekly reviews.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="feature-card">
                    <span class="ficon"><i class="bi bi-shield-check"></i></span>
                    <h3>Role-based access</h3>
                    <p>Administrator, State DSM, DSM Admin, District PMU, State PMU, EDMS — each module gated to the right eyes.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="emp-band" id="employers">
    <div class="container">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <div class="script">For employers</div>
                <h2>Partner with Kerala's workforce mission</h2>
                <p>
                    Submit your company details — vacancies, internships,
                    interest in DWMS onboarding — and the KKEM team will get
                    in touch. No login, no software setup.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a class="btn-light-hero" href="/employers/">
                    <i class="bi bi-building me-1"></i> Enroll your company
                </a>
            </div>
        </div>
    </div>
</section>

<footer class="home-footer">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            &copy; <?= (int) $year ?> Kerala Development &amp; Innovation Strategic Council (KDISC)
        </div>
        <div>
            <a href="/login.php">Sign In</a>
            <span class="mx-2">·</span>
            <a href="/employers/">For Employers</a>
        </div>
    </div>
</footer>

</body>
</html>

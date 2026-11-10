<?php
/**
 * Public Employer Enquiry form.
 *
 * URL:  /employers/
 *       (later also employers.kdiscmis.org.in once DNS is aliased
 *        to this directory; the form itself needs no further work.)
 *
 * Flow:
 *   GET  → render the form.
 *   POST → validate + captcha-check + insert + render thank-you.
 *
 * No login, so none of the usual admin helpers (require_auth,
 * render_header, rbac) run. Sessions still start so the captcha
 * can round-trip and so we can keep the last-submitted values on
 * a validation miss.
 *
 * Design pairs with the Campus-to-Corporate landing (/index.php).
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/india_locations_helpers.php';
require_once __DIR__ . '/../includes/employer_enquiry_helpers.php';

india_locations_bootstrap();
employer_enquiry_bootstrap();

$esc = static fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

/** Minimal CSRF for this public form — a one-off token in the
 *  session, regenerated on each successful submit. No shared
 *  session with the authenticated side. */
if (empty($_SESSION['employer_enquiry_csrf'])) {
    $_SESSION['employer_enquiry_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['employer_enquiry_csrf'];

$mathA = $_SESSION['employer_enquiry_math_a'] ?? null;
$mathB = $_SESSION['employer_enquiry_math_b'] ?? null;
if ($mathA === null || $mathB === null) {
    $mathA = random_int(1, 9);
    $mathB = random_int(1, 9);
    $_SESSION['employer_enquiry_math_a'] = $mathA;
    $_SESSION['employer_enquiry_math_b'] = $mathB;
}

$headerText = employer_enquiry_setting('header_text', EMPLOYER_ENQUIRY_DEFAULT_HEADER);
$thanksText = employer_enquiry_setting('thanks_text', EMPLOYER_ENQUIRY_DEFAULT_THANKS);

$errors = [];
$success = null;
$values = [
    'company_name' => '', 'hr_email' => '', 'hr_phone' => '', 'contact_person_name' => '',
    'website' => '', 'address' => '', 'country' => 'India', 'country_other' => '',
    'state' => '', 'district' => '', 'postcode' => '',
    'company_type' => '', 'pan_number' => '', 'tin_cin_number' => '',
    'interested_dwms' => '0',
    'vacancies_posts' => '', 'vacancies_open_positions' => '',
    'internship_available' => '0', 'internship_with_stipend' => '0', 'internship_open_positions' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // CSRF + captcha first.
    $postedCsrf = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $postedCsrf)) {
        $errors[] = 'This form was open too long. Please reload and try again.';
    }

    $imgCaptcha = strtoupper(trim((string) ($_POST['captcha_image'] ?? '')));
    $expected   = (string) ($_SESSION['employer_enquiry_captcha'] ?? '');
    if ($expected === '' || $imgCaptcha === '' || $imgCaptcha !== $expected) {
        $errors[] = 'The characters you typed do not match the picture. Please try again.';
    }
    $mathAnswer = (int) ($_POST['captcha_math'] ?? -1);
    if ($mathAnswer !== ($mathA + $mathB)) {
        $errors[] = 'The math answer is incorrect.';
    }

    // Trim all submitted strings.
    foreach ($values as $k => $_) {
        $values[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    // Checkbox coerce.
    $values['interested_dwms']      = !empty($_POST['interested_dwms']) ? '1' : '0';
    $values['internship_available'] = !empty($_POST['internship_available']) ? '1' : '0';
    $values['internship_with_stipend'] = !empty($_POST['internship_with_stipend']) ? '1' : '0';

    // Required fields.
    foreach ([
        'company_name' => 'Name of Company',
        'hr_email'     => 'Email address of HR',
        'hr_phone'     => 'Phone number of HR',
        'contact_person_name' => 'Name of the Contact person',
        'address'      => 'Company Address',
        'country'      => 'Country',
        'company_type' => 'Type of the Company',
    ] as $f => $label) {
        if ($values[$f] === '') $errors[] = $label . ' is required.';
    }

    if ($values['hr_email'] !== '' && !filter_var($values['hr_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'HR email does not look like a valid email address.';
    }
    if ($values['hr_phone'] !== '' && !employer_enquiry_valid_phone($values['hr_phone'])) {
        $errors[] = 'HR phone number does not look right (expected 7–15 digits).';
    }
    if ($values['pan_number'] !== '' && !employer_enquiry_valid_pan($values['pan_number'])) {
        $errors[] = 'PAN format should be like ABCDE1234F.';
    }
    if ($values['country'] === 'India') {
        if ($values['state'] === '')    $errors[] = 'State is required for India.';
        if ($values['district'] === '') $errors[] = 'District is required for India.';
    } elseif ($values['country'] === 'Outside India') {
        // "Outside India" is a routing choice, not a country value —
        // the user must pick a specific country from the searchable
        // list (and must not pick India from it either, that's what
        // the India branch is for).
        if ($values['country_other'] === '') {
            $errors[] = 'Please pick the country.';
        } elseif (!employer_enquiry_is_valid_foreign_country($values['country_other'])) {
            $errors[] = 'Please pick a country from the suggestions list.';
        }
    } else {
        $errors[] = 'Country is required.';
    }
    if ($values['website'] !== '' && !preg_match('/^(https?:\/\/)?[a-z0-9.\-]+\.[a-z]{2,}([\/?#].*)?$/i', $values['website'])) {
        $errors[] = 'Website should be a URL (e.g. https://example.com).';
    }
    // Normalise PAN to uppercase before insert.
    $values['pan_number'] = strtoupper($values['pan_number']);

    $intOrNull = static function (string $v): ?int {
        $v = trim($v);
        if ($v === '') return null;
        return max(0, (int) $v);
    };
    $vacPosts   = $intOrNull($values['vacancies_posts']);
    $vacOpen    = $intOrNull($values['vacancies_open_positions']);
    $intOpen    = $intOrNull($values['internship_open_positions']);

    if ($errors === []) {
        try {
            // Resolve the country value that lands in the DB:
            //   India        → 'India'
            //   Outside India → the picked country name, exact case
            //                   as it appears in the whitelist.
            $resolvedCountry = 'India';
            if ($values['country'] === 'Outside India') {
                $needle = mb_strtolower(trim($values['country_other']));
                $resolvedCountry = $values['country_other'];
                foreach (EMPLOYER_ENQUIRY_COUNTRIES_EXCEPT_INDIA as $c) {
                    if (mb_strtolower($c) === $needle) { $resolvedCountry = $c; break; }
                }
            }
            $reference = employer_enquiry_make_reference();
            $db = db();
            $stmt = $db->prepare('INSERT INTO employer_enquiry
                (reference_number, company_name, hr_email, hr_phone, contact_person_name,
                 website, address, country, state, district, postcode, company_type,
                 pan_number, tin_cin_number, interested_dwms,
                 vacancies_posts, vacancies_open_positions,
                 internship_available, internship_with_stipend, internship_open_positions,
                 submitter_ip, submitter_ua, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "new", NOW(), NOW())');
            $stmt->execute([
                $reference,
                $values['company_name'],
                $values['hr_email'],
                $values['hr_phone'],
                $values['contact_person_name'],
                $values['website']       === '' ? null : $values['website'],
                $values['address'],
                $resolvedCountry,
                $resolvedCountry === 'India' ? $values['state']    : null,
                $resolvedCountry === 'India' ? $values['district'] : null,
                $values['postcode']      === '' ? null : $values['postcode'],
                $values['company_type'],
                $values['pan_number']    === '' ? null : $values['pan_number'],
                $values['tin_cin_number']=== '' ? null : $values['tin_cin_number'],
                (int) $values['interested_dwms'],
                $vacPosts, $vacOpen,
                (int) $values['internship_available'],
                (int) $values['internship_with_stipend'],
                $intOpen,
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);
            $success = ['reference' => $reference, 'company' => $values['company_name']];

            // Rotate captchas + CSRF so a resubmit of the same form
            // can't go through unchallenged.
            unset(
                $_SESSION['employer_enquiry_captcha'],
                $_SESSION['employer_enquiry_math_a'],
                $_SESSION['employer_enquiry_math_b'],
                $_SESSION['employer_enquiry_csrf'],
            );
        } catch (Throwable $e) {
            $errors[] = 'Could not save your submission. Please try again in a moment.';
        }
    }
}

$states = india_states_list();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enroll Your Company · KKEM · K-DISC</title>
    <meta name="description" content="Share your company details with the Kerala Knowledge Economy Mission (KKEM). The team will reach out to onboard you on the DWMS portal.">
    <meta name="robots" content="noindex,nofollow">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700;800&family=Caveat:wght@500;700&family=Noto+Sans+Malayalam:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --c2c-navy: #0d3778; --c2c-deep: #072b46; --c2c-ink: #0e3e66;
            --c2c-teal: #0f9a8a; --c2c-sky: #1d7fb8; --c2c-aqua: #4fdcd2;
            --c2c-wash: #eef9f8; --c2c-slate: #475569;
        }
        html, body { height: 100%; }
        body { font-family: 'Inter', system-ui, sans-serif; color: var(--c2c-ink); background: linear-gradient(180deg, var(--c2c-wash) 0%, #ffffff 40%); }
        .font-display { font-family: 'Poppins', 'Inter', sans-serif; }
        .font-malayalam { font-family: 'Noto Sans Malayalam', 'Inter', sans-serif; line-height: 1.7; }
        .font-script { font-family: 'Caveat', cursive; }

        .top-strip { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 0.75rem 0; }
        .top-strip .brand { display: inline-flex; align-items: center; gap: 0.75rem; color: var(--c2c-navy); text-decoration: none; }
        .top-strip .brand-mark { width: 40px; height: 40px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; background: linear-gradient(135deg, var(--c2c-teal) 0%, var(--c2c-sky) 100%); }
        .top-strip .brand-text strong { font-family: 'Poppins'; font-weight: 700; font-size: 1rem; }
        .top-strip .brand-text small { color: var(--c2c-slate); font-size: 0.78rem; }

        .hero {
            padding: 2.5rem 0 1rem;
            background: radial-gradient(ellipse at 80% 20%, #d9f3f1 0%, #eef8fb 40%, #ffffff 80%);
        }
        .hero .script { font-family: 'Caveat'; color: var(--c2c-teal); font-size: 1.8rem; line-height: 1; }
        .hero h1 { font-family: 'Poppins'; font-weight: 700; color: var(--c2c-navy); margin-bottom: 0.75rem; font-size: clamp(1.6rem, 3vw, 2.25rem); }
        .malayalam-head {
            background: #fff;
            border: 1px solid #e6eef6;
            border-left: 4px solid var(--c2c-teal);
            border-radius: 10px;
            padding: 1rem 1.25rem;
            color: var(--c2c-ink);
            box-shadow: 0 10px 24px rgba(13, 55, 120, 0.06);
            margin-bottom: 1.5rem;
        }

        .form-card { background: #fff; border: 1px solid #e6eef6; border-radius: 18px; box-shadow: 0 20px 44px rgba(13, 55, 120, 0.08); }
        .form-card .card-section { padding: 1.5rem; border-bottom: 1px solid #eef2f7; }
        .form-card .card-section:last-of-type { border-bottom: 0; }
        .form-card h2 { font-family: 'Poppins'; font-weight: 600; font-size: 1.05rem; color: var(--c2c-navy); display: flex; align-items: center; gap: 0.6rem; margin-bottom: 1rem; }
        .form-card h2 .sec-dot { width: 32px; height: 32px; border-radius: 10px; background: linear-gradient(135deg, var(--c2c-teal), var(--c2c-sky)); color: #fff; display: inline-flex; align-items: center; justify-content: center; }
        .form-card .form-label { font-weight: 500; color: var(--c2c-ink); font-size: 0.9rem; }
        .form-card .form-label .req { color: #dc3545; margin-left: 2px; }

        .footer-strip { background: #fff; border-top: 1px solid #e2e8f0; padding: 1.25rem 0; color: var(--c2c-slate); font-size: 0.85rem; margin-top: 2rem; }

        .captcha-box { background: var(--c2c-wash); border: 1px solid #d7ebe9; border-radius: 10px; padding: 0.75rem; display: inline-flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .captcha-box img { height: 72px; border-radius: 8px; background: #fff; border: 1px solid #e0e6ef; }
        .btn-c2c { background: linear-gradient(135deg, var(--c2c-navy) 0%, var(--c2c-sky) 100%); color: #fff; border: 0; padding: 0.75rem 1.75rem; border-radius: 999px; font-weight: 600; box-shadow: 0 14px 28px rgba(13, 55, 120, 0.22); }
        .btn-c2c:hover, .btn-c2c:focus { color: #fff; filter: brightness(1.05); }

        .success-card {
            background: linear-gradient(135deg, #0f9a8a 0%, #1d7fb8 100%);
            color: #fff; border-radius: 20px; padding: 2.5rem;
            box-shadow: 0 24px 60px rgba(13, 55, 120, 0.22);
        }
        .success-card .ref-pill { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.35); padding: 0.5rem 1.25rem; border-radius: 999px; font-family: 'Poppins'; font-weight: 700; letter-spacing: 0.05em; font-size: 1.1rem; display: inline-block; }
        .success-card h1 { font-family: 'Poppins'; font-weight: 700; margin-bottom: 0.75rem; }
    </style>
</head>
<body>

<div class="top-strip">
    <div class="container d-flex align-items-center justify-content-between flex-wrap gap-2">
        <a class="brand" href="/">
            <span class="brand-mark">K</span>
            <span class="brand-text">
                <strong>KKEM · K-DISC</strong><br>
                <small>Kerala Knowledge Economy Mission</small>
            </span>
        </a>
        <a class="small text-decoration-none" href="/"><i class="bi bi-arrow-left me-1"></i>Back to K-DISC MIS home</a>
    </div>
</div>

<?php if ($success !== null): ?>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-8">
                <div class="success-card text-center">
                    <div class="font-script" style="font-size: 2rem; opacity: 0.9;">Thank you!</div>
                    <h1 class="h2">Your enquiry has been received</h1>
                    <p class="font-malayalam mb-4" style="font-size: 1.05rem;"><?= $esc($thanksText) ?></p>
                    <div class="mb-4">
                        <div class="small opacity-75 mb-2">Your reference number</div>
                        <div class="ref-pill"><?= $esc($success['reference']) ?></div>
                    </div>
                    <p class="small opacity-75 mb-0">
                        We've logged your submission under <strong><?= $esc($success['company']) ?></strong>.
                        The KKEM team will contact you at the email and phone number you provided.
                    </p>
                    <div class="mt-4">
                        <a class="btn btn-light btn-lg" href="/employers/">
                            <i class="bi bi-plus-circle me-1"></i>Submit another enquiry
                        </a>
                        <a class="btn btn-outline-light btn-lg ms-2" href="/">
                            <i class="bi bi-house me-1"></i>Home
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>

<section class="hero">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-9">
                <div class="script">Partner with us</div>
                <h1>Enroll your company with KKEM</h1>
                <div class="malayalam-head font-malayalam" id="headerText">
                    <?= nl2br($esc($headerText)) ?>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="container pb-5">
    <?php if ($errors !== []): ?>
        <div class="alert alert-danger">
            <strong class="d-block mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Please fix the following:</strong>
            <ul class="mb-0">
                <?php foreach ($errors as $e): ?><li><?= $esc($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="form-card" novalidate>
        <input type="hidden" name="csrf" value="<?= $esc($csrf) ?>">

        <div class="card-section">
            <h2><span class="sec-dot"><i class="bi bi-building"></i></span>Company details</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Name of Company<span class="req">*</span></label>
                    <input type="text" name="company_name" class="form-control" required maxlength="255" value="<?= $esc($values['company_name']) ?>" placeholder="Registered name of your company">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Type of the Company<span class="req">*</span></label>
                    <select name="company_type" class="form-select" required>
                        <option value="">— Select type —</option>
                        <?php foreach (EMPLOYER_ENQUIRY_COMPANY_TYPES as $t): ?>
                            <option value="<?= $esc($t) ?>" <?= $values['company_type'] === $t ? 'selected' : '' ?>><?= $esc($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Website</label>
                    <input type="text" name="website" class="form-control" maxlength="500" value="<?= $esc($values['website']) ?>" placeholder="https://yourcompany.com">
                </div>
                <div class="col-md-3">
                    <label class="form-label">PAN</label>
                    <input type="text" name="pan_number" class="form-control" maxlength="20" value="<?= $esc($values['pan_number']) ?>" placeholder="ABCDE1234F" style="text-transform: uppercase;">
                </div>
                <div class="col-md-3">
                    <label class="form-label">TIN / CIN</label>
                    <input type="text" name="tin_cin_number" class="form-control" maxlength="50" value="<?= $esc($values['tin_cin_number']) ?>">
                </div>
            </div>
        </div>

        <div class="card-section">
            <h2><span class="sec-dot"><i class="bi bi-person-badge"></i></span>HR / Contact</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Email address of HR<span class="req">*</span></label>
                    <input type="email" name="hr_email" class="form-control" required maxlength="255" value="<?= $esc($values['hr_email']) ?>" placeholder="hr@yourcompany.com">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone number of HR<span class="req">*</span></label>
                    <input type="tel" name="hr_phone" class="form-control" required maxlength="40" value="<?= $esc($values['hr_phone']) ?>" placeholder="+91 98765 43210" inputmode="tel">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Name of the Contact person<span class="req">*</span></label>
                    <input type="text" name="contact_person_name" class="form-control" required maxlength="255" value="<?= $esc($values['contact_person_name']) ?>">
                </div>
            </div>
        </div>

        <div class="card-section">
            <h2><span class="sec-dot"><i class="bi bi-geo-alt"></i></span>Company address</h2>
            <div class="row g-3">
                <div class="col-md-9">
                    <label class="form-label">Full address<span class="req">*</span></label>
                    <textarea name="address" class="form-control" required rows="3" placeholder="Street, area, city"><?= $esc($values['address']) ?></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Post Code / ZIP</label>
                    <input type="text" name="postcode" class="form-control" maxlength="20"
                           value="<?= $esc($values['postcode']) ?>" placeholder="e.g. 695001" autocomplete="postal-code">
                    <div class="form-text">PIN for India, ZIP/postcode for elsewhere.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Country<span class="req">*</span></label>
                    <select name="country" id="country" class="form-select" required>
                        <option value="India" <?= $values['country'] === 'India' ? 'selected' : '' ?>>India</option>
                        <option value="Outside India" <?= $values['country'] === 'Outside India' ? 'selected' : '' ?>>Outside India</option>
                    </select>
                </div>
                <!-- Country-name picker, only shown when "Outside India" is chosen.
                     A text input backed by a <datalist> gives native type-to-search
                     without shipping any JS library; it still accepts only entries
                     from the whitelist because the server re-validates on submit. -->
                <div class="col-md-8" id="countryOtherWrap" style="display:none;">
                    <label class="form-label">Which country?<span class="req">*</span></label>
                    <input type="text" name="country_other" id="countryOther" class="form-control"
                           list="countriesList" autocomplete="off"
                           placeholder="Start typing country name (e.g. United States, Germany, UAE…)"
                           value="<?= $esc($values['country_other']) ?>">
                    <datalist id="countriesList">
                        <?php foreach (EMPLOYER_ENQUIRY_COUNTRIES_EXCEPT_INDIA as $c): ?>
                            <option value="<?= $esc($c) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <div class="form-text">Pick from the suggestions — India has its own branch above.</div>
                </div>
                <div class="col-md-4" id="stateWrap">
                    <label class="form-label">State<span class="req">*</span></label>
                    <select name="state" id="state" class="form-select">
                        <option value="">— Select state —</option>
                        <?php foreach ($states as $s): ?>
                            <option value="<?= $esc($s['name']) ?>" <?= $values['state'] === $s['name'] ? 'selected' : '' ?>><?= $esc($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4" id="districtWrap">
                    <label class="form-label">District<span class="req">*</span></label>
                    <select name="district" id="district" class="form-select">
                        <option value="">— Select district —</option>
                        <?php if ($values['district'] !== ''): ?>
                            <option value="<?= $esc($values['district']) ?>" selected><?= $esc($values['district']) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="card-section">
            <h2><span class="sec-dot"><i class="bi bi-briefcase"></i></span>Vacancies &amp; onboarding</h2>
            <div class="row g-3">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="interested_dwms" id="interestedDwms" value="1" <?= $values['interested_dwms'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="interestedDwms">
                            Interested in enrolling on <strong>DWMS</strong> (Digital Workforce Management System)?
                        </label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Vacancies currently available — Number of posts</label>
                    <input type="number" name="vacancies_posts" class="form-control" min="0" step="1" value="<?= $esc($values['vacancies_posts']) ?>" placeholder="e.g. 3">
                    <div class="form-text">How many distinct roles are open right now.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Number of open positions</label>
                    <input type="number" name="vacancies_open_positions" class="form-control" min="0" step="1" value="<?= $esc($values['vacancies_open_positions']) ?>" placeholder="e.g. 10">
                    <div class="form-text">Total candidates you'd hire across those roles.</div>
                </div>
            </div>
        </div>

        <div class="card-section">
            <h2><span class="sec-dot"><i class="bi bi-mortarboard"></i></span>Internships</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="internship_available" id="internAvail" value="1" <?= $values['internship_available'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="internAvail">Internships available?</label>
                    </div>
                </div>
                <div class="col-md-4" id="internStipendWrap">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="internship_with_stipend" id="internStipend" value="1" <?= $values['internship_with_stipend'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="internStipend">With stipend?</label>
                    </div>
                </div>
                <div class="col-md-4" id="internOpenWrap">
                    <label class="form-label">Number of open positions</label>
                    <input type="number" name="internship_open_positions" class="form-control" min="0" step="1" value="<?= $esc($values['internship_open_positions']) ?>">
                </div>
            </div>
        </div>

        <div class="card-section">
            <h2><span class="sec-dot"><i class="bi bi-shield-check"></i></span>Verify &amp; submit</h2>
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Type the characters in the picture<span class="req">*</span></label>
                    <div class="captcha-box">
                        <img src="/employers/captcha.php?t=<?= time() ?>" alt="captcha" id="captchaImg">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="reloadCaptcha" title="Refresh captcha">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                        <input type="text" name="captcha_image" class="form-control" required maxlength="6" placeholder="Type here" autocomplete="off" style="text-transform: uppercase; max-width: 180px;">
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">And a quick math check<span class="req">*</span></label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text"><?= (int) $mathA ?> + <?= (int) $mathB ?> =</span>
                        <input type="number" name="captcha_math" class="form-control" required inputmode="numeric" autocomplete="off">
                    </div>
                </div>
            </div>
            <div class="mt-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="small text-muted">
                    <i class="bi bi-info-circle me-1"></i>Your details are stored securely with KKEM. We'll only use them to onboard you on DWMS and respond to your enquiry.
                </div>
                <button class="btn-c2c" type="submit">
                    <i class="bi bi-send me-1"></i>Submit enquiry
                </button>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    const country = document.getElementById('country');
    const stateWrap = document.getElementById('stateWrap');
    const districtWrap = document.getElementById('districtWrap');
    const countryOtherWrap = document.getElementById('countryOtherWrap');
    const countryOther = document.getElementById('countryOther');
    const stateSel = document.getElementById('state');
    const distSel  = document.getElementById('district');

    const toggleCountryBranch = () => {
        const isIndia   = country.value === 'India';
        const isForeign = country.value === 'Outside India';
        // India branch → show state + district (cascaded).
        stateWrap.style.display    = isIndia ? '' : 'none';
        districtWrap.style.display = isIndia ? '' : 'none';
        stateSel.required = isIndia;
        distSel.required  = isIndia;
        if (!isIndia) {
            stateSel.value = '';
            distSel.innerHTML = '<option value="">— Select district —</option>';
        }
        // Outside-India branch → show the searchable country picker.
        countryOtherWrap.style.display = isForeign ? '' : 'none';
        countryOther.required = isForeign;
        if (!isForeign) countryOther.value = '';
    };
    country.addEventListener('change', toggleCountryBranch);
    toggleCountryBranch();

    const loadDistricts = (state, selected) => {
        distSel.innerHTML = '<option value="">— Loading… —</option>';
        if (!state) {
            distSel.innerHTML = '<option value="">— Select district —</option>';
            return;
        }
        fetch('/employers/locations_ajax.php?action=districts&state=' + encodeURIComponent(state))
            .then(r => r.json())
            .then(data => {
                distSel.innerHTML = '<option value="">— Select district —</option>';
                (data.districts || []).forEach(d => {
                    const o = document.createElement('option');
                    o.value = d; o.textContent = d;
                    if (d === selected) o.selected = true;
                    distSel.appendChild(o);
                });
            })
            .catch(() => {
                distSel.innerHTML = '<option value="">— Could not load districts —</option>';
            });
    };
    stateSel.addEventListener('change', () => loadDistricts(stateSel.value, ''));
    if (stateSel.value) loadDistricts(stateSel.value, <?= json_encode($values['district'], JSON_UNESCAPED_UNICODE) ?>);

    // Internship fields: only matter when the "available" toggle is on.
    const internAvail   = document.getElementById('internAvail');
    const internOpenWrap = document.getElementById('internOpenWrap');
    const internStipendWrap = document.getElementById('internStipendWrap');
    const refreshIntern = () => {
        const on = internAvail.checked;
        internOpenWrap.style.opacity   = on ? '1' : '0.45';
        internStipendWrap.style.opacity = on ? '1' : '0.45';
    };
    internAvail.addEventListener('change', refreshIntern);
    refreshIntern();

    // Reload captcha.
    document.getElementById('reloadCaptcha').addEventListener('click', () => {
        const img = document.getElementById('captchaImg');
        img.src = '/employers/captcha.php?t=' + Date.now();
    });
})();
</script>
<?php endif; ?>

<footer class="footer-strip">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>&copy; <?= date('Y') ?> Kerala Development &amp; Innovation Strategic Council (KDISC)</div>
        <div><a class="text-decoration-none" href="/">K-DISC MIS Home</a></div>
    </div>
</footer>

</body>
</html>

<?php
/**
 * LOI & Jobs · Employer detail.
 *
 * Tabbed layout. Phase 1 ships Overview + Jobs with full CRUD and
 * loi_job_history audit on changes. Interviews / Candidates / Audit
 * tabs are stubbed with "coming in Phase 2" notices so the shape of
 * the page is in place and the user can start capturing data today.
 *
 * Access: anyone with the LOI module role views; admin + the viewer
 * who is the employer's creator can edit.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/loi_jobs_helpers.php';
require_once __DIR__ . '/includes/loi_requests_helpers.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
loi_jobs_bootstrap();
loi_requests_bootstrap();

if (!user_can_access_module($viewerId, 'loi_jobs') && !is_admin($viewer)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">You do not have access to the LOI & Jobs module.</div>';
    render_footer(); exit;
}

$canAdmin = is_manage_admin($viewer) || user_can_admin_module($viewerId, 'loi_jobs');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: /loi_employers.php'); exit; }
$st = db()->prepare('SELECT e.*, s.name AS sbu_name FROM loi_employer e LEFT JOIN loi_sbu s ON s.id = e.sbu_id WHERE e.id = ? LIMIT 1');
$st->execute([$id]);
$emp = $st->fetch();
if ($emp === false) { header('Location: /loi_employers.php'); exit; }
$isCreator = $viewerId === (int) ($emp['created_by'] ?? 0);
$canEdit   = $canAdmin || $isCreator;

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['loi_emp_view_flash']) && is_array($_SESSION['loi_emp_view_flash'])) {
    $flashMessage = (string) ($_SESSION['loi_emp_view_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['loi_emp_view_flash']['type'] ?? 'success');
    unset($_SESSION['loi_emp_view_flash']);
}
$flashAndBack = static function (string $msg, string $type = 'success', int $empId = 0, string $tab = 'overview'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['loi_emp_view_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /loi_employer_view.php?id=' . $empId . '&tab=' . $tab);
    exit;
};

/* ---------- Jobs CRUD + audit ---------- */
// Field list drives the audit writer. Includes every column we persist
// so a change in any one of them lands as a row in loi_job_history.
$jobFields = ['job_role','dwms_job_id','vacancy_count','expiry_date','qualification',
              'qualification_category_id','job_description','skills_required','gender',
              'salary_range_id',
              'experience_from_years','experience_to_years','experience_valid_till',
              'preferred_candidates','status','mobilisation_commenced_at','mobilisation_completed_at',
              'applications_count'];

if (is_post() && ($_POST['action'] ?? '') === 'save_job') {
    csrf_check_or_die();
    if (!$canEdit) $flashAndBack('Not permitted.', 'danger', $id, 'jobs');
    $jobId = (int) ($_POST['job_id'] ?? 0);
    $data  = [
        'job_role'               => trim((string) ($_POST['job_role'] ?? '')),
        'dwms_job_id'            => trim((string) ($_POST['dwms_job_id'] ?? '')) ?: null,
        'vacancy_count'          => trim((string) ($_POST['vacancy_count'] ?? '')) === '' ? null : (int) $_POST['vacancy_count'],
        'expiry_date'            => trim((string) ($_POST['expiry_date'] ?? '')) ?: null,   // UI label = "Date Valid From"
        'qualification'          => trim((string) ($_POST['qualification'] ?? '')) ?: null,
        'qualification_category_id' => (int) ($_POST['qualification_category_id'] ?? 0) ?: null,
        'job_description'        => trim((string) ($_POST['job_description'] ?? '')) ?: null,
        'skills_required'        => trim((string) ($_POST['skills_required'] ?? '')) ?: null,
        'gender'                 => in_array($_POST['gender'] ?? '', ['male','female','other','male_female'], true) ? $_POST['gender'] : null,
        'salary_range_id'        => (int) ($_POST['salary_range_id'] ?? 0) ?: null,
        'experience_from_years'  => trim((string) ($_POST['experience_from_years'] ?? '')) === '' ? null : (float) $_POST['experience_from_years'],
        'experience_to_years'    => trim((string) ($_POST['experience_to_years'] ?? '')) === '' ? null : (float) $_POST['experience_to_years'],
        'experience_valid_till'  => trim((string) ($_POST['experience_valid_till'] ?? '')) ?: null,   // UI label = "Date Valid To"
        'preferred_candidates'   => in_array($_POST['preferred_candidates'] ?? '', ['fresher','experienced','either'], true) ? $_POST['preferred_candidates'] : 'either',
        'status'                 => in_array($_POST['status'] ?? '', ['active','closed'], true) ? $_POST['status'] : 'active',
        'mobilisation_commenced' => isset($_POST['mobilisation_commenced']) ? 1 : 0,
        'mobilisation_completed' => isset($_POST['mobilisation_completed']) ? 1 : 0,
        'applications_count'     => trim((string) ($_POST['applications_count'] ?? '')) === '' ? null : (int) $_POST['applications_count'],
    ];
    // Multi-select locations — array of ids from the checkbox list.
    $locationIds = array_values(array_filter(array_map('intval', (array) ($_POST['location_ids'] ?? []))));
    // Per-job sector + Applications-as-on date (new fields). Captured
    // separately so a hosted install without the ALTER still saves the
    // base row without erroring.
    $jobSector         = trim((string) ($_POST['job_sector'] ?? ''));
    $appsCountAsOnRaw  = trim((string) ($_POST['applications_count_as_on'] ?? ''));
    $appsCountAsOn     = ($appsCountAsOnRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $appsCountAsOnRaw)) ? $appsCountAsOnRaw : null;
    if ($data['job_role'] === '') $flashAndBack('Job role is required.', 'danger', $id, 'jobs');
    if ($data['dwms_job_id'] === null || $data['dwms_job_id'] === '') $flashAndBack('DWMS Job ID is required.', 'danger', $id, 'jobs');

    // Convert the mobilisation checkboxes into timestamps on first flip;
    // keep previous timestamps on subsequent saves.
    $prev = ['mobilisation_commenced_at' => null, 'mobilisation_completed_at' => null];
    if ($jobId > 0) {
        $pt = db()->prepare('SELECT * FROM loi_job WHERE id = ? AND employer_id = ?');
        $pt->execute([$jobId, $id]);
        $prev = $pt->fetch() ?: $prev;
    }
    $mobCommencedAt = $prev['mobilisation_commenced_at'] ?? null;
    $mobCompletedAt = $prev['mobilisation_completed_at'] ?? null;
    if ($data['mobilisation_commenced'] && $mobCommencedAt === null) $mobCommencedAt = date('Y-m-d H:i:s');
    if (!$data['mobilisation_commenced']) $mobCommencedAt = null;
    if ($data['mobilisation_completed'] && $mobCompletedAt === null) $mobCompletedAt = date('Y-m-d H:i:s');
    if (!$data['mobilisation_completed']) $mobCompletedAt = null;

    try {
        if ($jobId > 0) {
            db()->prepare('UPDATE loi_job SET
                    job_role = ?, dwms_job_id = ?, vacancy_count = ?, expiry_date = ?, qualification = ?,
                    qualification_category_id = ?, job_description = ?, skills_required = ?,
                    gender = ?, salary_range_id = ?,
                    experience_from_years = ?, experience_to_years = ?, experience_valid_till = ?,
                    preferred_candidates = ?, status = ?,
                    mobilisation_commenced_at = ?, mobilisation_completed_at = ?,
                    applications_count = ?, updated_at = NOW(), updated_by = ?
                WHERE id = ? AND employer_id = ?')
                ->execute([
                    $data['job_role'], $data['dwms_job_id'], $data['vacancy_count'], $data['expiry_date'], $data['qualification'],
                    $data['qualification_category_id'], $data['job_description'], $data['skills_required'],
                    $data['gender'], $data['salary_range_id'],
                    $data['experience_from_years'], $data['experience_to_years'], $data['experience_valid_till'],
                    $data['preferred_candidates'], $data['status'],
                    $mobCommencedAt, $mobCompletedAt,
                    $data['applications_count'], $viewerId, $jobId, $id,
                ]);
            // Audit — one row per changed field.
            foreach ($jobFields as $f) {
                $oldVal = $prev[$f] ?? null;
                $newVal = $f === 'mobilisation_commenced_at' ? $mobCommencedAt
                        : ($f === 'mobilisation_completed_at' ? $mobCompletedAt
                        : ($data[$f] ?? null));
                loi_job_history_write($jobId, $f, $oldVal, $newVal, $viewerId);
            }
            // Locations — wholesale rewrite of the bridge.
            db()->prepare('DELETE FROM loi_job_location WHERE job_id = ?')->execute([$jobId]);
            $insLoc = db()->prepare('INSERT INTO loi_job_location (job_id, location_id) VALUES (?, ?)');
            foreach ($locationIds as $lid) try { $insLoc->execute([$jobId, $lid]); } catch (Throwable $e) { /* dup */ }
            // Late-added columns — tolerated when absent.
            try { db()->prepare('UPDATE loi_job SET job_sector = ?, applications_count_as_on = ? WHERE id = ? AND employer_id = ?')
                ->execute([$jobSector === '' ? null : $jobSector, $appsCountAsOn, $jobId, $id]); } catch (Throwable $e) { /* columns missing */ }
            $flashAndBack('Job updated.', 'success', $id, 'jobs');
        } else {
            db()->prepare('INSERT INTO loi_job
                (employer_id, job_role, dwms_job_id, vacancy_count, expiry_date, qualification,
                 qualification_category_id, job_description, skills_required, gender, salary_range_id,
                 experience_from_years, experience_to_years, experience_valid_till,
                 preferred_candidates, status,
                 mobilisation_commenced_at, mobilisation_completed_at, applications_count,
                 is_active, created_at, updated_at, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), ?, ?)')
                ->execute([
                    $id, $data['job_role'], $data['dwms_job_id'], $data['vacancy_count'], $data['expiry_date'], $data['qualification'],
                    $data['qualification_category_id'], $data['job_description'], $data['skills_required'],
                    $data['gender'], $data['salary_range_id'],
                    $data['experience_from_years'], $data['experience_to_years'], $data['experience_valid_till'],
                    $data['preferred_candidates'], $data['status'],
                    $mobCommencedAt, $mobCompletedAt, $data['applications_count'],
                    $viewerId, $viewerId,
                ]);
            $newId = (int) db()->lastInsertId();
            // Audit — first-time create records "null -> value" rows so
            // the audit tab shows the initial state too.
            foreach ($jobFields as $f) {
                $newVal = $f === 'mobilisation_commenced_at' ? $mobCommencedAt
                        : ($f === 'mobilisation_completed_at' ? $mobCompletedAt
                        : ($data[$f] ?? null));
                if ($newVal === null || $newVal === '') continue;
                loi_job_history_write($newId, $f, null, $newVal, $viewerId);
            }
            // Locations bridge for the fresh job row.
            $insLoc = db()->prepare('INSERT INTO loi_job_location (job_id, location_id) VALUES (?, ?)');
            foreach ($locationIds as $lid) try { $insLoc->execute([$newId, $lid]); } catch (Throwable $e) { /* dup */ }
            try { db()->prepare('UPDATE loi_job SET job_sector = ?, applications_count_as_on = ? WHERE id = ?')
                ->execute([$jobSector === '' ? null : $jobSector, $appsCountAsOn, $newId]); } catch (Throwable $e) { /* columns missing */ }
            $flashAndBack('Job added.', 'success', $id, 'jobs');
        }
    } catch (Throwable $e) {
        $flashAndBack('Save failed: ' . $e->getMessage(), 'danger', $id, 'jobs');
    }
}
if (is_post() && ($_POST['action'] ?? '') === 'toggle_job') {
    csrf_check_or_die();
    if (!$canEdit) $flashAndBack('Not permitted.', 'danger', $id, 'jobs');
    $jobId = (int) ($_POST['job_id'] ?? 0);
    db()->prepare('UPDATE loi_job SET is_active = 1 - is_active, updated_at = NOW(), updated_by = ? WHERE id = ? AND employer_id = ?')
        ->execute([$viewerId, $jobId, $id]);
    $flashAndBack('Toggled.', 'success', $id, 'jobs');
}

/* ---------- Interview CRUD (per-job rows under this employer) ---- */
if (is_post() && ($_POST['action'] ?? '') === 'save_interview') {
    csrf_check_or_die();
    if (!$canEdit) $flashAndBack('Not permitted.', 'danger', $id, 'interviews');
    $ivId  = (int) ($_POST['interview_id'] ?? 0);
    $jobIdPost = (int) ($_POST['job_id'] ?? 0);
    $iDate    = trim((string) ($_POST['interview_date'] ?? ''));
    $iDate    = ($iDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $iDate)) ? $iDate : null;
    $modeTx   = trim((string) ($_POST['mode_text'] ?? ''));
    $desc     = trim((string) ($_POST['description'] ?? ''));
    $att      = trim((string) ($_POST['applicants_attended'] ?? ''));
    $sel      = trim((string) ($_POST['selected_count'] ?? ''));
    $rej      = trim((string) ($_POST['rejected_count'] ?? ''));
    $pUrl     = trim((string) ($_POST['participants_url'] ?? ''));

    if ($jobIdPost <= 0)  $flashAndBack('Pick a job for this interview.', 'danger', $id, 'interviews');
    if ($iDate === null)  $flashAndBack('Interview date is required.', 'danger', $id, 'interviews');

    try {
        if ($ivId > 0) {
            db()->prepare('UPDATE loi_interview SET
                    job_id = ?, interview_date = ?, mode_text = ?, description = ?,
                    applicants_attended = ?, selected_count = ?, rejected_count = ?,
                    participants_url = ?, updated_at = NOW(), updated_by = ?
                WHERE id = ? AND employer_id = ?')
                ->execute([
                    $jobIdPost, $iDate,
                    $modeTx === '' ? null : $modeTx,
                    $desc === '' ? null : $desc,
                    $att === '' ? null : (int) $att,
                    $sel === '' ? null : (int) $sel,
                    $rej === '' ? null : (int) $rej,
                    $pUrl === '' ? null : $pUrl,
                    $viewerId, $ivId, $id,
                ]);
            $flashAndBack('Interview updated.', 'success', $id, 'interviews');
        } else {
            // scheduled_at keeps compatibility with the legacy ENUM-mode
            // table shape — mirror interview_date into it so pre-existing
            // reports keyed on scheduled_at still see the row.
            db()->prepare('INSERT INTO loi_interview
                (employer_id, job_id, scheduled_at, interview_date, mode_text, description,
                 applicants_attended, selected_count, rejected_count, participants_url,
                 is_active, created_at, updated_at, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), ?, ?)')
                ->execute([
                    $id, $jobIdPost,
                    $iDate . ' 09:00:00',
                    $iDate,
                    $modeTx === '' ? null : $modeTx,
                    $desc === '' ? null : $desc,
                    $att === '' ? null : (int) $att,
                    $sel === '' ? null : (int) $sel,
                    $rej === '' ? null : (int) $rej,
                    $pUrl === '' ? null : $pUrl,
                    $viewerId, $viewerId,
                ]);
            $flashAndBack('Interview added.', 'success', $id, 'interviews');
        }
    } catch (Throwable $e) {
        $flashAndBack('Save failed: ' . $e->getMessage(), 'danger', $id, 'interviews');
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'delete_interview') {
    csrf_check_or_die();
    if (!$canEdit) $flashAndBack('Not permitted.', 'danger', $id, 'interviews');
    $ivId = (int) ($_POST['interview_id'] ?? 0);
    try { db()->prepare('DELETE FROM loi_interview WHERE id = ? AND employer_id = ?')->execute([$ivId, $id]); }
    catch (Throwable $e) { /* ignore */ }
    $flashAndBack('Interview deleted.', 'success', $id, 'interviews');
}

$jobs = [];
try {
    $st = db()->prepare('SELECT * FROM loi_job WHERE employer_id = ? ORDER BY is_active DESC, status ASC, job_role ASC');
    $st->execute([$id]);
    $jobs = $st->fetchAll();
} catch (Throwable $e) { /* empty */ }

// Per-job location sets {job_id => [location_id, ...]} + name map for
// displaying the chips in the Jobs table.
$jobLocationIds = [];
$locationName   = [];
try {
    foreach (db()->query('SELECT jl.job_id, jl.location_id, l.name
        FROM loi_job_location jl INNER JOIN loi_location l ON l.id = jl.location_id')->fetchAll() as $row) {
        $jobLocationIds[(int) $row['job_id']][] = (int) $row['location_id'];
        $locationName[(int) $row['location_id']] = (string) $row['name'];
    }
} catch (Throwable $e) { /* table absent */ }

$sbuRows            = loi_sbu_list(true);
$qualCategoryRows   = loi_qualification_category_list(true);
$locationRows       = loi_location_list(true);
$salaryRangeRows    = loi_salary_range_list(true);
$tab = (string) ($_GET['tab'] ?? 'overview');
if (!in_array($tab, ['overview','jobs','interviews','candidates','requests','audit'], true)) $tab = 'overview';

$tabHref = static fn(string $t): string => '/loi_employer_view.php?id=' . (int) $_GET['id'] . '&tab=' . $t;

$fmtDate = static fn($s) => $s === null || $s === '' ? '—' : date('d/m/Y', strtotime((string) $s));

render_header('LOI & Jobs · ' . $emp['name'], ['main_container_class' => 'container-xl']);
render_page_header($emp['name'], [
    'icon'     => 'bi-building',
    'subtitle' => 'LOI & Jobs · ' . (string) ($emp['sbu_name'] ?? '—')
                 . ' · ' . (string) ($emp['district'] ?? '—')
                 . (empty($emp['state']) ? '' : ', ' . (string) $emp['state']),
    'actions'  => '<a class="btn btn-light" href="/loi_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'overview'   ? 'active' : '' ?>" href="<?= esc($tabHref('overview')) ?>"><i class="bi bi-info-circle me-1"></i>Overview</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'jobs'       ? 'active' : '' ?>" href="<?= esc($tabHref('jobs')) ?>"><i class="bi bi-briefcase me-1"></i>Jobs <span class="badge text-bg-light border ms-1"><?= count($jobs) ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'interviews' ? 'active' : '' ?>" href="<?= esc($tabHref('interviews')) ?>"><i class="bi bi-calendar-event me-1"></i>Interviews</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'candidates' ? 'active' : '' ?>" href="<?= esc($tabHref('candidates')) ?>"><i class="bi bi-people me-1"></i>Candidates</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'requests'   ? 'active' : '' ?>" href="<?= esc($tabHref('requests')) ?>"><i class="bi bi-chat-dots me-1"></i>Platform Requests</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'audit'      ? 'active' : '' ?>" href="<?= esc($tabHref('audit')) ?>"><i class="bi bi-clock-history me-1"></i>Audit</a></li>
</ul>

<?php if ($tab === 'overview'): ?>
    <div class="card">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><div class="small text-muted">SBU</div><div class="fw-semibold"><?= esc((string) ($emp['sbu_name'] ?? '—')) ?></div></div>
                <div class="col-md-3"><div class="small text-muted">Sector</div><div><?= esc((string) ($emp['sector'] ?? '—')) ?></div></div>
                <div class="col-md-3"><div class="small text-muted">State / District</div><div><?= esc((string) ($emp['state'] ?? '—')) ?><?= !empty($emp['district']) ? ' · ' . esc((string) $emp['district']) : '' ?></div></div>
                <div class="col-md-3"><div class="small text-muted">Status</div><div><?php if ((int) $emp['is_active'] === 1): ?><span class="badge text-bg-success">Active</span><?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?></div></div>

                <?php if (!empty($emp['address_of_communication'])): ?>
                    <div class="col-12"><div class="small text-muted">Address of communication</div><div style="white-space:pre-wrap;"><?= esc((string) $emp['address_of_communication']) ?></div></div>
                <?php endif; ?>

                <div class="col-md-3"><div class="small text-muted">DWMS Employer ID</div><div class="font-monospace"><?= esc((string) ($emp['dwms_employer_id'] ?? '—')) ?></div></div>
                <div class="col-md-3"><div class="small text-muted">Contact number</div><div><?= esc((string) ($emp['contact_number'] ?? '—')) ?></div></div>
                <div class="col-md-3"><div class="small text-muted">Contact email</div><div><?= esc((string) ($emp['contact_email'] ?? '—')) ?></div></div>
                <div class="col-md-6"><div class="small text-muted">HR manager / SPOC</div>
                    <div><?= esc((string) ($emp['hr_manager_name'] ?? '—')) ?>
                        <?php if (!empty($emp['hr_manager_mobile'])): ?><span class="text-muted small"> · <?= esc((string) $emp['hr_manager_mobile']) ?></span><?php endif; ?>
                        <?php if (!empty($emp['hr_manager_email'])): ?><span class="text-muted small"> · <?= esc((string) $emp['hr_manager_email']) ?></span><?php endif; ?>
                    </div>
                </div>

                <div class="col-md-3"><div class="small text-muted">LOI received</div><div><?php if ((int) ($emp['loi_received'] ?? 0) === 1): ?><span class="badge text-bg-success">Yes</span> <span class="small text-muted"><?= esc($fmtDate($emp['loi_received_date'] ?? '')) ?></span><?php else: ?><span class="badge text-bg-secondary">No</span><?php endif; ?></div></div>
                <div class="col-md-3"><div class="small text-muted">Registered in DWMS</div><div><?= (int) ($emp['registered_in_dwms'] ?? 0) === 1 ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-secondary">No</span>' ?></div></div>
                <div class="col-md-3"><div class="small text-muted">Jobs added in DWMS</div><div><?= (int) ($emp['jobs_added_in_dwms'] ?? 0) === 1 ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-secondary">No</span>' ?></div></div>

                <?php if (!empty($emp['notes'])): ?>
                    <div class="col-12"><div class="small text-muted">Notes</div><div style="white-space:pre-wrap;"><?= esc((string) $emp['notes']) ?></div></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($canEdit): ?>
        <div class="card-footer text-end">
            <a class="btn btn-primary" href="/loi_employers.php?open=<?= (int) $emp['id'] ?>"><i class="bi bi-pencil me-1"></i>Edit employer</a>
        </div>
        <?php endif; ?>
    </div>
<?php elseif ($tab === 'jobs'): ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-briefcase text-primary me-1"></i>Jobs for this employer</span>
            <?php if ($canEdit): ?>
                <button type="button" class="btn btn-sm btn-primary js-new-job" data-bs-toggle="modal" data-bs-target="#jobModal"><i class="bi bi-plus-lg me-1"></i>Add job</button>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($jobs === []): ?>
                <div class="empty-state"><i class="bi bi-inbox"></i>No jobs added yet.</div>
            <?php else: ?>
                <div class="row g-3 loi-job-grid">
                <?php foreach ($jobs as $j):
                    $active = ((int) $j['is_active']) === 1;
                    $jLocIds = $jobLocationIds[(int) $j['id']] ?? [];
                    $qcName = '';
                    if (!empty($j['qualification_category_id'])) {
                        foreach ($qualCategoryRows as $qc) if ((int) $qc['id'] === (int) $j['qualification_category_id']) { $qcName = (string) $qc['name']; break; }
                    }
                    $srName = '';
                    if (!empty($j['salary_range_id'])) {
                        foreach ($salaryRangeRows as $sr) if ((int) $sr['id'] === (int) $j['salary_range_id']) { $srName = (string) $sr['name']; break; }
                    }
                    $payload = htmlspecialchars(json_encode([
                        'id'                     => (int) $j['id'],
                        'job_role'               => (string) $j['job_role'],
                        'dwms_job_id'            => (string) ($j['dwms_job_id'] ?? ''),
                        'job_sector'             => (string) ($j['job_sector'] ?? ''),
                        'applications_count_as_on' => (string) ($j['applications_count_as_on'] ?? ''),
                        'vacancy_count'          => $j['vacancy_count'],
                        'expiry_date'            => (string) ($j['expiry_date'] ?? ''),
                        'qualification'          => (string) ($j['qualification'] ?? ''),
                        'qualification_category_id' => (int) ($j['qualification_category_id'] ?? 0),
                        'job_description'        => (string) ($j['job_description'] ?? ''),
                        'skills_required'        => (string) ($j['skills_required'] ?? ''),
                        'gender'                 => (string) ($j['gender'] ?? ''),
                        'salary_range_id'        => (int) ($j['salary_range_id'] ?? 0),
                        'experience_from_years'  => $j['experience_from_years'],
                        'experience_to_years'    => $j['experience_to_years'],
                        'experience_valid_till'  => (string) ($j['experience_valid_till'] ?? ''),
                        'preferred_candidates'   => (string) ($j['preferred_candidates'] ?? 'either'),
                        'status'                 => (string) ($j['status'] ?? 'active'),
                        'mobilisation_commenced' => !empty($j['mobilisation_commenced_at']) ? 1 : 0,
                        'mobilisation_completed' => !empty($j['mobilisation_completed_at']) ? 1 : 0,
                        'applications_count'     => $j['applications_count'],
                        'location_ids'           => $jLocIds,
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                    $comm = !empty($j['mobilisation_commenced_at']);
                    $done = !empty($j['mobilisation_completed_at']);
                    if ($comm && $done) { $mobBadge = '<span class="badge text-bg-success">Completed</span>'; }
                    elseif ($comm)      { $mobBadge = '<span class="badge text-bg-warning">In progress</span>'; }
                    else                { $mobBadge = '<span class="badge text-bg-light border">Not started</span>'; }
                ?>
                    <div class="col-12 col-xl-6">
                        <div class="loi-job-card card h-100 <?= $active ? '' : 'loi-job-inactive' ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="loi-job-role fw-semibold"><?= esc((string) $j['job_role']) ?></div>
                                        <div class="small text-muted mt-1 d-flex flex-wrap gap-2 align-items-center">
                                            <?php if (!empty($j['dwms_job_id'])): ?>
                                                <span class="font-monospace"><i class="bi bi-hash"></i><?= esc((string) $j['dwms_job_id']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($j['job_sector'])): ?>
                                                <span><i class="bi bi-briefcase me-1"></i><?= esc((string) $j['job_sector']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="badge text-bg-<?= esc(loi_job_status_tone((string) $j['status'])) ?> flex-shrink-0"><?= esc(ucfirst((string) $j['status'])) ?></span>
                                </div>

                                <dl class="loi-job-kv row g-1 small mb-0">
                                    <dt class="col-5 col-sm-4 text-muted">Openings</dt>
                                    <dd class="col-7 col-sm-8 mb-0 fw-semibold"><?= $j['vacancy_count'] === null ? '—' : (int) $j['vacancy_count'] ?></dd>

                                    <dt class="col-5 col-sm-4 text-muted">Applications</dt>
                                    <dd class="col-7 col-sm-8 mb-0">
                                        <?= $j['applications_count'] === null ? '—' : (int) $j['applications_count'] ?>
                                        <?php if (!empty($j['applications_count_as_on'])): ?>
                                            <span class="text-muted small">(as on <?= esc($fmtDate($j['applications_count_as_on'])) ?>)</span>
                                        <?php endif; ?>
                                    </dd>

                                    <dt class="col-5 col-sm-4 text-muted">Qualification</dt>
                                    <dd class="col-7 col-sm-8 mb-0">
                                        <?= esc((string) ($j['qualification'] ?? '—') ?: '—') ?>
                                        <?php if ($qcName !== ''): ?><div class="text-muted small">[<?= esc($qcName) ?>]</div><?php endif; ?>
                                    </dd>

                                    <dt class="col-5 col-sm-4 text-muted">Salary</dt>
                                    <dd class="col-7 col-sm-8 mb-0"><?= $srName === '' ? '<span class="text-muted">—</span>' : esc($srName) ?></dd>

                                    <dt class="col-5 col-sm-4 text-muted">Mobilisation</dt>
                                    <dd class="col-7 col-sm-8 mb-0"><?= $mobBadge ?></dd>

                                    <dt class="col-5 col-sm-4 text-muted">Valid From → To</dt>
                                    <dd class="col-7 col-sm-8 mb-0">
                                        <?= esc($fmtDate($j['expiry_date'] ?? '')) ?>
                                        <?php if (!empty($j['experience_valid_till'])): ?>
                                            <span class="text-muted">→ <?= esc($fmtDate($j['experience_valid_till'])) ?></span>
                                        <?php endif; ?>
                                    </dd>
                                </dl>
                            </div>
                            <?php if ($canEdit): ?>
                                <div class="card-footer bg-transparent d-flex justify-content-end gap-1 py-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary js-edit-job" data-payload="<?= $payload ?>" data-bs-toggle="modal" data-bs-target="#jobModal"><i class="bi bi-pencil me-1"></i>Edit</button>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Toggle job status?');">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_job">
                                        <input type="hidden" name="job_id" value="<?= (int) $j['id'] ?>">
                                        <button class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-success' ?>"><i class="bi <?= $active ? 'bi-slash-circle me-1' : 'bi-check2-circle me-1' ?>"></i><?= $active ? 'Deactivate' : 'Activate' ?></button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <style>
    .loi-job-grid .loi-job-card         { border: 1px solid #e2e8f0; border-radius: 10px; transition: box-shadow .15s; }
    .loi-job-grid .loi-job-card:hover   { box-shadow: 0 4px 14px rgba(30,42,66,.08); }
    .loi-job-grid .loi-job-inactive     { opacity: .65; }
    .loi-job-grid .loi-job-role         { font-size: 1.1rem; line-height: 1.3; word-break: break-word; }
    .loi-job-kv dt                      { font-weight: 500; }
    .loi-job-kv dd                      { word-break: break-word; }
    </style>

    <?php if ($canEdit): ?>
    <div class="modal fade" id="jobModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="save_job">
                    <input type="hidden" name="job_id" id="jModalId" value="0">
                    <div class="modal-header">
                        <h5 class="modal-title" id="jModalTitle"><i class="bi bi-plus-lg me-1"></i>New job</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-5"><label class="form-label">Job role <span class="text-danger">*</span></label><input class="form-control" id="jModalRole" name="job_role" required maxlength="200"></div>
                            <div class="col-md-3"><label class="form-label">DWMS Job ID <span class="text-danger">*</span></label><input class="form-control" id="jModalDwmsId" name="dwms_job_id" required maxlength="80" placeholder="e.g. JOB-01234"></div>
                            <div class="col-md-2"><label class="form-label">Openings</label><input type="number" min="0" class="form-control" id="jModalVac" name="vacancy_count"></div>
                            <div class="col-md-2"><label class="form-label">Job Sector</label><input class="form-control" id="jModalJobSector" name="job_sector" maxlength="120" placeholder="e.g. Logistics"></div>
                            <div class="col-md-3"><label class="form-label">Applications</label><input type="number" min="0" class="form-control" id="jModalApps" name="applications_count"></div>
                            <div class="col-md-3"><label class="form-label">Applications count as on</label><input type="date" class="form-control" id="jModalAppsAsOn" name="applications_count_as_on"></div>

                            <div class="col-md-5"><label class="form-label">Candidate qualification</label><input class="form-control" id="jModalQual" name="qualification" maxlength="300" placeholder="Free-text qualification note"></div>
                            <div class="col-md-3"><label class="form-label">Qualification category</label>
                                <select class="form-select" id="jModalQCat" name="qualification_category_id">
                                    <option value="0">— select —</option>
                                    <?php foreach ($qualCategoryRows as $qc): ?>
                                        <option value="<?= (int) $qc['id'] ?>"><?= esc((string) $qc['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2"><label class="form-label">Experience from (yrs)</label><input type="number" step="0.5" min="0" class="form-control" id="jModalEFrom" name="experience_from_years"></div>
                            <div class="col-md-2"><label class="form-label">Experience to (yrs)</label><input type="number" step="0.5" min="0" class="form-control" id="jModalETo" name="experience_to_years"></div>

                            <div class="col-md-12"><label class="form-label">Job description</label><textarea class="form-control" id="jModalDesc" name="job_description" rows="3" placeholder="Full description of the role, duties and responsibilities."></textarea></div>

                            <div class="col-md-6"><label class="form-label">Skills required</label><textarea class="form-control" id="jModalSkills" name="skills_required" rows="2" placeholder="Comma-separated skills, e.g. Java, SQL, Communication"></textarea></div>
                            <div class="col-md-3"><label class="form-label">Gender</label>
                                <select class="form-select" id="jModalGender" name="gender">
                                    <option value="">— any —</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                    <option value="male_female">Male / Female</option>
                                </select>
                            </div>
                            <div class="col-md-3"><label class="form-label">Salary range</label>
                                <select class="form-select" id="jModalSalary" name="salary_range_id">
                                    <option value="0">— select —</option>
                                    <?php foreach ($salaryRangeRows as $sr): ?>
                                        <option value="<?= (int) $sr['id'] ?>"><?= esc((string) $sr['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Location <span class="small text-muted">(tick as many as apply)</span></label>
                                <div class="border rounded p-2" style="max-height: 180px; overflow-y: auto;">
                                    <div class="row g-1">
                                        <?php foreach ($locationRows as $lr): ?>
                                            <div class="col-md-3 col-sm-4 col-6">
                                                <div class="form-check">
                                                    <input class="form-check-input js-loc-cb" type="checkbox" name="location_ids[]" value="<?= (int) $lr['id'] ?>" id="jModalLoc<?= (int) $lr['id'] ?>">
                                                    <label class="form-check-label small" for="jModalLoc<?= (int) $lr['id'] ?>"><?= esc((string) $lr['name']) ?></label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3"><label class="form-label">Date Valid From</label><input type="date" class="form-control" id="jModalExp" name="expiry_date"></div>
                            <div class="col-md-3"><label class="form-label">Date Valid To</label><input type="date" class="form-control" id="jModalETill" name="experience_valid_till"></div>
                            <div class="col-md-3"><label class="form-label">Preferred candidates</label>
                                <select class="form-select" id="jModalPref" name="preferred_candidates">
                                    <option value="either">Either</option>
                                    <option value="fresher">Freshers only</option>
                                    <option value="experienced">Experienced only</option>
                                </select>
                            </div>
                            <div class="col-md-3"><label class="form-label">Status</label>
                                <select class="form-select" id="jModalStatus" name="status">
                                    <option value="active">Active</option>
                                    <option value="closed">Closed</option>
                                </select>
                            </div>

                            <div class="col-md-6"><div class="form-check">
                                <input class="form-check-input" type="checkbox" id="jModalMobComm" name="mobilisation_commenced" value="1">
                                <label class="form-check-label" for="jModalMobComm">Mobilisation commenced</label>
                            </div></div>
                            <div class="col-md-6"><div class="form-check">
                                <input class="form-check-input" type="checkbox" id="jModalMobDone" name="mobilisation_completed" value="1">
                                <label class="form-check-label" for="jModalMobDone">Mobilisation completed</label>
                            </div></div>
                        </div>
                        <div class="small text-muted mt-3"><i class="bi bi-info-circle me-1"></i>Every change you save here is recorded in the Audit tab (who changed what and when).</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
    (function () {
        const modalEl = document.getElementById('jobModal');
        const setV = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
        const setC = (id, v) => { const el = document.getElementById(id); if (el) el.checked = !!v; };
        const resetLocationChecks = (ids) => {
            const set = new Set((ids || []).map(Number));
            document.querySelectorAll('.js-loc-cb').forEach(cb => { cb.checked = set.has(Number(cb.value)); });
        };
        modalEl?.addEventListener('show.bs.modal', (ev) => {
            const t = ev.relatedTarget; if (!t) return;
            const title = document.getElementById('jModalTitle');
            if (t.classList.contains('js-new-job')) {
                title.innerHTML = '<i class="bi bi-plus-lg me-1"></i>New job';
                ['jModalId','jModalRole','jModalDwmsId','jModalJobSector','jModalVac','jModalExp','jModalApps','jModalAppsAsOn',
                 'jModalQual','jModalDesc','jModalSkills','jModalEFrom','jModalETo','jModalETill']
                    .forEach(k => setV(k, k === 'jModalId' ? '0' : ''));
                setV('jModalPref', 'either'); setV('jModalStatus', 'active');
                setV('jModalQCat', '0'); setV('jModalSalary', '0'); setV('jModalGender', '');
                setC('jModalMobComm', false); setC('jModalMobDone', false);
                resetLocationChecks([]);
            } else if (t.classList.contains('js-edit-job')) {
                let d = {}; try { d = JSON.parse(t.getAttribute('data-payload') || '{}'); } catch (e) {}
                title.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit job';
                setV('jModalId', String(d.id || 0));
                setV('jModalRole', d.job_role);
                setV('jModalDwmsId', d.dwms_job_id);
                setV('jModalJobSector', d.job_sector || '');
                setV('jModalVac', d.vacancy_count);
                setV('jModalExp', (d.expiry_date || '').substring(0, 10));
                setV('jModalApps', d.applications_count);
                setV('jModalAppsAsOn', (d.applications_count_as_on || '').substring(0, 10));
                setV('jModalQual', d.qualification);
                setV('jModalQCat', String(d.qualification_category_id || 0));
                setV('jModalDesc', d.job_description);
                setV('jModalSkills', d.skills_required);
                setV('jModalGender', d.gender || '');
                setV('jModalSalary', String(d.salary_range_id || 0));
                setV('jModalEFrom', d.experience_from_years); setV('jModalETo', d.experience_to_years);
                setV('jModalETill', (d.experience_valid_till || '').substring(0, 10));
                setV('jModalPref', d.preferred_candidates || 'either');
                setV('jModalStatus', d.status || 'active');
                setC('jModalMobComm', d.mobilisation_commenced === 1);
                setC('jModalMobDone', d.mobilisation_completed === 1);
                resetLocationChecks(d.location_ids || []);
            }
        });
    })();
    </script>
    <?php endif; ?>
<?php elseif ($tab === 'interviews'):
    $interviews = [];
    try {
        $is = db()->prepare('SELECT i.*, j.job_role, j.dwms_job_id
            FROM loi_interview i
            LEFT JOIN loi_job j ON j.id = i.job_id
            WHERE i.employer_id = ? AND i.is_active = 1
            ORDER BY COALESCE(i.interview_date, DATE(i.scheduled_at)) DESC, i.id DESC');
        $is->execute([$id]);
        $interviews = $is->fetchAll();
    } catch (Throwable $e) { /* empty */ }
    $templateFile = loi_setting('participants_template_file', '');
    $templateUrl  = $templateFile !== '' ? '/uploads/loi/' . rawurlencode($templateFile) : '';
    $jobsForPicker = [];
    try {
        $js = db()->prepare('SELECT id, job_role, dwms_job_id FROM loi_job WHERE employer_id = ? AND is_active = 1 ORDER BY job_role ASC');
        $js->execute([$id]);
        $jobsForPicker = $js->fetchAll();
    } catch (Throwable $e) { /* empty */ }
?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-calendar-event text-primary me-1"></i>Interviews</span>
            <div class="d-flex gap-2">
                <?php if ($templateUrl !== ''): ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?= esc($templateUrl) ?>" target="_blank"><i class="bi bi-download me-1"></i>Download Participants Template</a>
                <?php else: ?>
                    <a class="btn btn-sm btn-outline-secondary" href="/loi_settings.php" title="No template uploaded yet. An admin can upload one from LOI settings."><i class="bi bi-cloud-upload me-1"></i>Upload template (admin)</a>
                <?php endif; ?>
                <?php if ($canEdit && $jobsForPicker !== []): ?>
                    <button class="btn btn-sm btn-primary js-new-iv" type="button" data-bs-toggle="modal" data-bs-target="#ivModal"><i class="bi bi-plus-lg me-1"></i>New interview</button>
                <?php elseif ($canEdit): ?>
                    <span class="small text-muted align-self-center">Add a job first before scheduling an interview.</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 120px;">Date</th>
                        <th>Job</th>
                        <th>Mode</th>
                        <th class="text-end">Attended</th>
                        <th class="text-end">Selected</th>
                        <th class="text-end">Rejected</th>
                        <th>Participants</th>
                        <th class="text-end" style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($interviews === []): ?>
                        <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No interviews yet.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($interviews as $iv):
                        $ivPayload = htmlspecialchars(json_encode([
                            'id'                  => (int) $iv['id'],
                            'job_id'              => (int) ($iv['job_id'] ?? 0),
                            'interview_date'      => (string) ($iv['interview_date'] ?? ''),
                            'mode_text'           => (string) ($iv['mode_text'] ?? ''),
                            'description'         => (string) ($iv['description'] ?? ''),
                            'applicants_attended' => $iv['applicants_attended'],
                            'selected_count'      => $iv['selected_count'],
                            'rejected_count'      => $iv['rejected_count'],
                            'participants_url'    => (string) ($iv['participants_url'] ?? ''),
                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
                        $dateDisp = (string) ($iv['interview_date'] ?? '');
                        if ($dateDisp === '' && !empty($iv['scheduled_at'])) $dateDisp = substr((string) $iv['scheduled_at'], 0, 10);
                    ?>
                        <tr>
                            <td class="small"><?= $dateDisp !== '' ? esc(date('d/m/Y', strtotime($dateDisp))) : '—' ?></td>
                            <td>
                                <?php if (!empty($iv['job_role'])): ?>
                                    <strong><?= esc((string) $iv['job_role']) ?></strong>
                                    <?php if (!empty($iv['dwms_job_id'])): ?><div class="small text-muted"><?= esc((string) $iv['dwms_job_id']) ?></div><?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">— job removed —</span>
                                <?php endif; ?>
                                <?php if (!empty($iv['description'])): ?>
                                    <div class="small text-muted" style="white-space:pre-wrap;"><?= esc((string) $iv['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= esc((string) ($iv['mode_text'] ?? '—') ?: '—') ?></td>
                            <td class="text-end"><?= $iv['applicants_attended'] === null ? '—' : (int) $iv['applicants_attended'] ?></td>
                            <td class="text-end"><?= $iv['selected_count']      === null ? '—' : (int) $iv['selected_count'] ?></td>
                            <td class="text-end"><?= $iv['rejected_count']      === null ? '—' : (int) $iv['rejected_count'] ?></td>
                            <td class="small"><?php if (!empty($iv['participants_url'])): ?><a href="<?= esc((string) $iv['participants_url']) ?>" target="_blank" rel="noopener"><i class="bi bi-link-45deg me-1"></i>Open</a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                            <td class="text-end">
                                <?php if ($canEdit): ?>
                                    <button class="btn btn-sm btn-outline-secondary js-edit-iv" data-bs-toggle="modal" data-bs-target="#ivModal" data-payload='<?= $ivPayload ?>'><i class="bi bi-pencil"></i></button>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this interview record?');">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_interview">
                                        <input type="hidden" name="interview_id" value="<?= (int) $iv['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($canEdit && $jobsForPicker !== []): ?>
    <div class="modal fade" id="ivModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form class="modal-content" method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="save_interview">
                <input type="hidden" name="interview_id" id="ivModalId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="ivModalTitle"><i class="bi bi-plus-lg me-1"></i>New interview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Job <span class="text-danger">*</span></label>
                            <select class="form-select" id="ivModalJob" name="job_id" required>
                                <option value="">— select job —</option>
                                <?php foreach ($jobsForPicker as $j): ?>
                                    <option value="<?= (int) $j['id'] ?>"><?= esc((string) $j['job_role']) ?><?= !empty($j['dwms_job_id']) ? ' · ' . esc((string) $j['dwms_job_id']) : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Interview Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="ivModalDate" name="interview_date" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Mode of Interview</label>
                            <input type="text" class="form-control" id="ivModalMode" name="mode_text" maxlength="80" placeholder="e.g. In-person, Online, Hybrid">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="ivModalDesc" name="description" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Applicants attended</label>
                            <input type="number" min="0" class="form-control" id="ivModalAtt" name="applicants_attended">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Selected candidates</label>
                            <input type="number" min="0" class="form-control" id="ivModalSel" name="selected_count">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Rejected candidates</label>
                            <input type="number" min="0" class="form-control" id="ivModalRej" name="rejected_count">
                        </div>
                        <div class="col-12">
                            <label class="form-label d-flex justify-content-between align-items-center">
                                <span>Participants list <span class="small text-muted">(Google Drive URL)</span></span>
                                <?php if ($templateUrl !== ''): ?>
                                    <a class="small" href="<?= esc($templateUrl) ?>" target="_blank"><i class="bi bi-download me-1"></i>Download template</a>
                                <?php endif; ?>
                            </label>
                            <input type="url" class="form-control" id="ivModalPUrl" name="participants_url" maxlength="500" placeholder="https://drive.google.com/…">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    (function () {
        const modalEl = document.getElementById('ivModal');
        const setV = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
        modalEl?.addEventListener('show.bs.modal', (ev) => {
            const t = ev.relatedTarget; if (!t) return;
            const title = document.getElementById('ivModalTitle');
            if (t.classList.contains('js-new-iv')) {
                title.innerHTML = '<i class="bi bi-plus-lg me-1"></i>New interview';
                ['ivModalId','ivModalJob','ivModalDate','ivModalMode','ivModalDesc',
                 'ivModalAtt','ivModalSel','ivModalRej','ivModalPUrl'].forEach(k => setV(k, k === 'ivModalId' ? '0' : ''));
            } else if (t.classList.contains('js-edit-iv')) {
                let d = {}; try { d = JSON.parse(t.getAttribute('data-payload') || '{}'); } catch (e) {}
                title.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Edit interview';
                setV('ivModalId', String(d.id || 0));
                setV('ivModalJob', String(d.job_id || ''));
                setV('ivModalDate', (d.interview_date || '').substring(0, 10));
                setV('ivModalMode', d.mode_text);
                setV('ivModalDesc', d.description);
                setV('ivModalAtt',  d.applicants_attended);
                setV('ivModalSel',  d.selected_count);
                setV('ivModalRej',  d.rejected_count);
                setV('ivModalPUrl', d.participants_url);
            }
        });
    })();
    </script>
    <?php endif; ?>
<?php elseif ($tab === 'candidates'): ?>
    <div class="card"><div class="card-body">
        <div class="alert alert-info mb-0"><i class="bi bi-hourglass-split me-1"></i>Candidate roster lands in <strong>Phase 2</strong>. Each row will capture Name / Mobile / Email / Qualification / Experience / Outcome against a specific interview-job pair.</div>
    </div></div>
<?php elseif ($tab === 'requests'):
    $canParticipate = loi_requests_can_participate($viewerId, $viewer);
    $reqs = [];
    try {
        $rst = db()->prepare("SELECT r.*, u.name AS raised_by_name, rr.name AS reason_name,
                (SELECT COUNT(*) FROM loi_request_message WHERE request_id = r.id) AS msg_count,
                (SELECT MAX(created_at) FROM loi_request_message WHERE request_id = r.id) AS last_msg_at
            FROM loi_request r
            LEFT JOIN users u ON u.id = r.created_by
            LEFT JOIN loi_request_reason rr ON rr.id = r.reason_id
            WHERE r.employer_id = ?
            ORDER BY FIELD(r.status, 'open','in_progress','resolved','closed'), r.created_at DESC");
        $rst->execute([$id]);
        $reqs = $rst->fetchAll();
    } catch (Throwable $e) { /* empty */ }
?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-chat-dots text-primary me-1"></i>Platform requests for this employer</span>
            <?php if ($canParticipate && (int) $emp['is_active'] === 1): ?>
                <button type="button" class="btn btn-sm btn-warning js-raise-req-from-tab"><i class="bi bi-chat-dots me-1"></i>Raise new request</button>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th>Reason</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Raised by</th>
                    <th>Last activity</th>
                    <th class="text-center">Messages</th>
                    <th class="text-end">Action</th>
                </tr></thead>
                <tbody>
                    <?php if ($reqs === []): ?>
                        <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No platform requests raised for this employer yet.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($reqs as $r): ?>
                        <tr>
                            <td class="fw-semibold"><?= esc((string) ($r['reason_name'] ?? '—')) ?></td>
                            <td><span class="badge text-bg-<?= esc(loi_request_priority_tone((string) $r['priority'])) ?>"><?= esc(ucfirst((string) $r['priority'])) ?></span></td>
                            <td><span class="badge text-bg-<?= esc(loi_request_status_tone((string) $r['status'])) ?>"><?= esc(loi_request_status_label((string) $r['status'])) ?></span></td>
                            <td class="small"><?= esc((string) ($r['raised_by_name'] ?? '—')) ?><div class="text-muted"><?= esc(date('d/m/Y H:i', strtotime((string) $r['created_at']))) ?></div></td>
                            <td class="small text-muted"><?= $r['last_msg_at'] ? esc(date('d/m/Y H:i', strtotime((string) $r['last_msg_at']))) : '—' ?></td>
                            <td class="text-center"><?= (int) ($r['msg_count'] ?? 0) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="/loi_request_view.php?id=<?= (int) $r['id'] ?>"><i class="bi bi-chat-left-text me-1"></i>Open chat</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($canParticipate): ?>
    <script>
    // Lightweight bridge: clicking "Raise new request" bounces back to
    // the employers list page's modal, pre-filled with this employer.
    // Keeps a single modal definition instead of duplicating here.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.js-raise-req-from-tab');
        if (!btn) return;
        location.href = '/loi_employers.php?raise=<?= (int) $emp['id'] ?>';
    });
    </script>
    <?php endif; ?>
<?php elseif ($tab === 'audit'): ?>
    <?php
        $audit = [];
        try {
            $st = db()->prepare('SELECT h.*, j.job_role, u.name AS changed_by_name
                FROM loi_job_history h
                INNER JOIN loi_job j ON j.id = h.job_id
                LEFT JOIN users u ON u.id = h.changed_by
                WHERE j.employer_id = ?
                ORDER BY h.changed_at DESC, h.id DESC
                LIMIT 500');
            $st->execute([$id]);
            $audit = $st->fetchAll();
        } catch (Throwable $e) { /* empty */ }
    ?>
    <div class="card">
        <div class="card-header"><i class="bi bi-clock-history text-primary me-1"></i>Change history (jobs)</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>When</th><th>Who</th><th>Job</th><th>Field</th><th>From</th><th>To</th></tr></thead>
                <tbody>
                    <?php if ($audit === []): ?>
                        <tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i>No audit entries yet.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($audit as $h): ?>
                        <tr>
                            <td class="small text-muted"><?= esc(date('d/m/Y H:i', strtotime((string) $h['changed_at']))) ?></td>
                            <td class="small"><?= esc((string) ($h['changed_by_name'] ?? '—')) ?></td>
                            <td class="small"><?= esc((string) $h['job_role']) ?></td>
                            <td class="small font-monospace"><?= esc((string) $h['field_name']) ?></td>
                            <td class="small"><?= $h['old_value'] === null ? '<span class="text-muted">—</span>' : esc((string) $h['old_value']) ?></td>
                            <td class="small"><?= $h['new_value'] === null ? '<span class="text-muted">—</span>' : esc((string) $h['new_value']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php render_footer(); ?>

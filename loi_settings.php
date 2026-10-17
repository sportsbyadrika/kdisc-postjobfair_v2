<?php
/**
 * Administration · LOI settings.
 *
 * Lives under the Administration menu. Right now the only setting is
 * the uploaded Participants list Template (Excel) that an operator can
 * download from each interview row on the LOI employer detail page.
 * The file is stored at uploads/loi/participants_template.<ext> and
 * the filename is tracked in the loi_setting key-value table so the
 * download endpoint can serve whichever extension the admin uploaded.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/loi_jobs_helpers.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
loi_jobs_bootstrap();

if (!is_manage_admin($viewer) && !user_can_admin_module($viewerId, 'administration') && !user_can_admin_module($viewerId, 'loi_jobs')) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only an Administrator (or LOI module admin) can change LOI settings.</div>';
    render_footer(); exit;
}

const LOI_UPLOAD_DIR = __DIR__ . '/uploads/loi';
if (!is_dir(LOI_UPLOAD_DIR)) @mkdir(LOI_UPLOAD_DIR, 0775, true);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['loi_settings_flash']) && is_array($_SESSION['loi_settings_flash'])) {
    $flashMessage = (string) ($_SESSION['loi_settings_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['loi_settings_flash']['type'] ?? 'success');
    unset($_SESSION['loi_settings_flash']);
}
$flashAndBack = static function (string $msg, string $type = 'success'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['loi_settings_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /loi_settings.php');
    exit;
};

/* ---------- Upload handler ---------- */
if (is_post() && ($_POST['action'] ?? '') === 'upload_participants_template') {
    csrf_check_or_die();
    if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $flashAndBack('Please choose a file to upload.', 'danger');
    }
    $tmp  = (string) $_FILES['file']['tmp_name'];
    $orig = (string) $_FILES['file']['name'];
    $ext  = strtolower((string) pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'xlsm', 'csv'], true)) {
        $flashAndBack('Only .xlsx / .xls / .xlsm / .csv files are allowed.', 'danger');
    }
    if (filesize($tmp) > 5 * 1024 * 1024) {
        $flashAndBack('File is too large (5 MiB max).', 'danger');
    }
    // Remove any previous template so we don't orphan older extensions.
    foreach (['xlsx', 'xls', 'xlsm', 'csv'] as $e) {
        $old = LOI_UPLOAD_DIR . '/participants_template.' . $e;
        if (is_file($old)) @unlink($old);
    }
    $destFile = 'participants_template.' . $ext;
    $destPath = LOI_UPLOAD_DIR . '/' . $destFile;
    if (!move_uploaded_file($tmp, $destPath)) {
        $flashAndBack('Failed to save file on server.', 'danger');
    }
    loi_setting_set('participants_template_file', $destFile, $viewerId);
    $flashAndBack('Participants list template uploaded (' . $destFile . ').');
}

if (is_post() && ($_POST['action'] ?? '') === 'delete_participants_template') {
    csrf_check_or_die();
    $cur = loi_setting('participants_template_file', '');
    if ($cur !== '') {
        $path = LOI_UPLOAD_DIR . '/' . basename($cur);
        if (is_file($path)) @unlink($path);
    }
    loi_setting_set('participants_template_file', '', $viewerId);
    $flashAndBack('Template removed.');
}

$currentTemplate = loi_setting('participants_template_file', '');

render_header('Administration · LOI settings');
render_page_header('Administration · LOI settings', [
    'icon'     => 'bi-sliders2',
    'subtitle' => 'Module-wide settings for LOI & Jobs. Right now this is the Participants list template that each interview card links to for download.',
    'actions'  => '<a class="btn btn-light" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Participants list template</div>
            <div class="card-body">
                <?php if ($currentTemplate !== '' && is_file(LOI_UPLOAD_DIR . '/' . basename($currentTemplate))): ?>
                    <div class="alert alert-light border d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-check-circle-fill text-success"></i>
                        <div class="flex-grow-1">
                            Current template: <strong><?= esc($currentTemplate) ?></strong>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="/uploads/loi/<?= esc($currentTemplate) ?>" target="_blank"><i class="bi bi-download me-1"></i>Download</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Remove the current template file?');">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_participants_template">
                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning mb-3"><i class="bi bi-exclamation-triangle me-1"></i>No template uploaded yet. Interview cards won't show a Download Template button until one is set.</div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="upload_participants_template">
                    <div class="mb-3">
                        <label class="form-label">Upload new template (.xlsx / .xls / .xlsm / .csv, 5 MiB max)</label>
                        <input type="file" class="form-control" name="file" accept=".xlsx,.xls,.xlsm,.csv" required>
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-cloud-arrow-up me-1"></i>Upload template</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i>About this template</div>
            <div class="card-body small text-muted">
                <p>Each interview card on an employer's Interviews tab shows a <em>Download Participants list Template</em> button. Users fill the downloaded Excel file with the day's participants, then upload the completed file to Google Drive and paste the share URL back into the interview record.</p>
                <p class="mb-0">Replacing the template here changes what every interview card downloads — the current template is a single global file, not per-employer.</p>
            </div>
        </div>
    </div>
</div>

<?php render_footer(); ?>
